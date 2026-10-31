<?php

declare(strict_types=1);

namespace App\Domain\Device;

use App\Domain\Audit\AuditService;
use App\Domain\Audit\EventType;
use App\Domain\Auth\RemoteIdentity;
use App\Domain\Policy\EffectivePolicyResolver;
use App\Domain\Policy\PermissionCatalog;
use App\Domain\Session\ClientCapabilities;
use App\Domain\Session\ControlService;
use App\Domain\Session\ParticipantService;
use App\Domain\Session\SessionService;
use App\Domain\Session\SessionStatus;
use App\Domain\Support\ApiException;
use App\Domain\Support\Clock;
use App\Domain\Support\Ids;
use CodeIgniter\Database\BaseConnection;
use Config\Remote as RemoteConfig;

/**
 * Sessions that involve a desktop agent — attended and unattended.
 *
 * **An unattended connection is an ordinary Remote session.** It goes through
 * `SessionService::create()` like every other one, so it gets the same policy
 * snapshot, the same expiry, the same participant records, the same audit
 * trail and the same signalling authorisation. What it does *not* get is a
 * shortcut around any of that: the only thing unattended access changes is
 * *who consents*, and that consent was given earlier, deliberately, by a person
 * at the machine — and is revocable by them or by an administrator at any time.
 *
 * Six things must all be true before this class will start one, and the order
 * is deliberate — the cheapest tenant check first, the device's own state last:
 *
 *   1. the caller is a member of the device's company — or, for a personal
 *      computer, which has no company, is its owner;
 *   2. the scope permits unattended access (policy ∧ entitlement);
 *   3. the caller holds `remote.unattended.access`;
 *   4. the device is ACTIVE — not suspended, not revoked;
 *   5. the device has unattended access enabled, by somebody, on a date;
 *   6. the device is actually reachable.
 *
 * For a personal account the second and third are the platform's own answer
 * and are yes: reaching your own computer is what Basic is for. The first and
 * the fifth are what keep that safe — nobody but the owner can reach a
 * personal computer at all, and none can be reached until somebody at it
 * switched unattended access on and confirmed it there.
 *
 * The tray application still shows a session is running. There is no hidden
 * mode, and this class provides no way to create one.
 */
class DeviceSessionService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly DeviceService $devices,
        private readonly SessionService $sessions,
        private readonly ParticipantService $participants,
        private readonly ControlService $control,
        private readonly EffectivePolicyResolver $policies,
        private readonly AuditService $audit,
        private readonly RemoteConfig $config,
    ) {
    }

    /**
     * Connect to a registered device with nobody at it.
     *
     * @return array{session: array<string, mixed>, device: array<string, mixed>,
     *               participant: array<string, mixed>, hostParticipant: array<string, mixed>}
     */
    public function startUnattended(RemoteIdentity $identity, string $deviceUuid, ?string $issueSummary = null): array
    {
        $device    = $this->devices->findByUuidOrFail($deviceUuid);
        $companyId = $device['company_id'] !== null ? (int) $device['company_id'] : null;

        // (1) Tenant isolation. `resolve()` throws before reading a capability
        // if this person has no standing in the device's organisation, so a
        // device uuid from another tenant is refused here, not later.
        //
        // A personal device has no tenant to be isolated from, and resolving
        // `(int) null` would have asked about company 0 — a company nobody
        // has — turning "you may not connect to this" into "that device could
        // not be found". Its owner is checked below instead.
        try {
            $policy = $this->policies->resolve(
                $identity,
                $companyId === null ? 'PERSONAL' : 'COMPANY',
                $companyId,
            );
        } catch (ApiException) {
            throw ApiException::notFound('That device could not be found.');
        }

        // A personal device answers to its owner and to nobody else. There is
        // no organisation whose administrators could also reach it, so this is
        // the whole of its access control.
        if ($companyId === null && (int) ($device['user_id'] ?? 0) !== $identity->id) {
            throw ApiException::notFound('That device could not be found.');
        }

        // (2) and (3). A personal computer has no organisation to blame for a
        // refusal, so it is not told it has one.
        if (! $policy->allowUnattendedAccess) {
            throw ApiException::forbidden(
                'UNATTENDED_ACCESS_NOT_ALLOWED',
                $companyId === null
                    ? 'Unattended access is not available for this account.'
                    : 'Unattended access is not enabled for this organisation.',
                ['restrictions' => $policy->restrictions],
            );
        }

        if (! $policy->can(PermissionCatalog::UNATTENDED_ACCESS)) {
            throw ApiException::forbidden(
                'UNATTENDED_ACCESS_DENIED',
                'You do not have permission to connect to a computer when nobody is at it.',
                ['permission' => PermissionCatalog::UNATTENDED_ACCESS],
            );
        }

        // (4), (5) and (6).
        if ((string) $device['status'] !== DeviceService::STATUS_ACTIVE) {
            throw ApiException::conflict('DEVICE_NOT_ACTIVE', 'That device is not active in AICOUNTLY Remote.');
        }

        if ($device['unattended_access_enabled'] !== true) {
            throw ApiException::forbidden(
                'UNATTENDED_NOT_ENABLED',
                'Unattended access has not been switched on for this device. Somebody at the machine has to enable it.',
            );
        }

        if (! $this->devices->isOnline($device)) {
            throw ApiException::conflict(
                'DEVICE_OFFLINE',
                'That device is not reachable at the moment.',
                ['lastSeenAt' => Clock::iso($device['last_seen_at'] ?? null)],
            );
        }

        $capabilities = $this->devices->effectiveCapabilities($device, $policy);

        // The ordinary session-creation path: quota, policy snapshot, display
        // id, join code, audit. Nothing here is bypassed.
        // A personal device's session is the owner's personal session; a
        // company device's belongs to that company.
        $session = $this->sessions->create($identity, [
            'scopeType'          => $companyId === null ? 'PERSONAL' : 'COMPANY',
            'companyId'          => $companyId,
            'sessionType'        => $companyId === null ? 'ASSISTANCE' : 'INTERNAL',
            'requestedShareMode' => $this->shareModeFor($policy->allowedShareModes()),
            'issueSummary'       => $issueSummary,
        ], $policy);

        $this->db->table('remote_sessions')->where('id', $session['id'])->update([
            'access_mode' => 'UNATTENDED',
            'device_id'   => $device['id'],
            'updated_at'  => Clock::now(),
        ]);

        // The creator was made a SHARER host by `create()`, because that is
        // what creating a session normally means. Here it does not: the machine
        // shares, and the person who connected watches and controls.
        $self = $this->participants->findByUser((int) $session['id'], $identity->id);
        if ($self !== null) {
            $this->db->table('remote_participants')->where('id', $self['id'])->update([
                'participant_role' => ParticipantService::ROLE_VIEWER,
                'is_host'          => false,
                'updated_at'       => Clock::now(),
            ]);
        }

        $host = $this->attachDeviceParticipant($session, $device, $capabilities, true);

        // Control is the point of an unattended connection, so it is granted
        // with the session rather than requested from an empty chair — but only
        // where the organisation and this person's permissions allow it. Where
        // they do not, the session is a view of the screen and nothing more,
        // which is an honest outcome rather than a failed one.
        if ($capabilities['remote_control'] === true
            && $policy->allowRemoteControl
            && $policy->can(PermissionCatalog::CONTROL_REQUEST)
            && $self !== null
        ) {
            $this->db->table('remote_participants')->where('id', $self['id'])->update([
                'control_state'              => ControlService::STATE_GRANTED,
                'control_requested_at'       => Clock::now(),
                'control_granted_at'         => Clock::now(),
                'control_granted_by_user_id' => null,
                'updated_at'                 => Clock::now(),
            ]);

            $this->audit->record(
                $session,
                EventType::CONTROL_GRANTED,
                null,
                'DEVICE',
                (int) $self['id'],
                (string) $self['uuid'],
                ['reason' => 'UNATTENDED_ENROLMENT', 'controllerUserId' => $identity->id],
            );
        }

        $this->db->table('remote_devices')->where('id', $device['id'])->update([
            'unattended_last_used_at' => Clock::now(),
            'updated_at'              => Clock::now(),
        ]);

        // The event that makes unattended access auditable as its own thing.
        // Without it, a connection to an empty machine would be indistinguishable
        // in the record from one somebody consented to at the time.
        $this->audit->record(
            $session,
            EventType::UNATTENDED_SESSION_STARTED,
            $identity->id,
            'USER',
            $host !== null ? (int) $host['id'] : null,
            $host !== null ? (string) $host['uuid'] : null,
            [
                'deviceUuid' => $deviceUuid,
                'deviceName' => (string) $device['device_name'],
                'unattendedEnabledAt' => Clock::iso($device['unattended_enabled_at'] ?? null),
            ],
        );

        return [
            'session'         => $this->sessions->findByUuidOrFail((string) $session['uuid']),
            'device'          => $this->devices->findByUuidOrFail($deviceUuid),
            'participant'     => $self !== null ? $this->participants->findByIdOrFail((int) $self['id']) : [],
            'hostParticipant' => $host ?? [],
        ];
    }

    /**
     * Join a device to a session as its screen-sharing host.
     *
     * Used by the attended flow: the person at the Windows machine starts (or
     * joins) a session from the desktop application, and the agent registers
     * itself with the capabilities the organisation permits.
     *
     * @param  array<string, mixed> $session
     * @return array<string, mixed> the device participant
     */
    public function joinAsHost(array $session, DevicePrincipal $principal): array
    {
        $device = $this->devices->findByUuidOrFail($principal->deviceUuid);

        if (! self::sameScope($device, $session)) {
            throw ApiException::notFound('That Remote session could not be found.');
        }

        if (! SessionStatus::isLive((string) $session['status'])) {
            throw ApiException::conflict('SESSION_ALREADY_ENDED', 'This Remote session has already finished.');
        }

        $owner = $this->ownerIdentity($device);
        if ($owner === null) {
            throw ApiException::conflict(
                'DEVICE_OWNER_MISSING',
                'This device is not linked to an AICOUNTLY user any more. Register it again.',
            );
        }

        // Same company is not enough. A device may host only the session it was asked to host (unattended: the
        // session names it), or an attended session its own owner started. Without this, any enrolled machine in a
        // company could join any live session of that company as its controllable sharer. Refused as "not found",
        // like a session in another company, so the answer does not confirm that a session exists.
        $namedForThisDevice = $session['device_id'] !== null && (int) $session['device_id'] === (int) $device['id'];
        $startedByOwner     = ($session['access_mode'] ?? 'ATTENDED') === 'ATTENDED'
            && (int) $session['initiator_user_id'] === $owner->id;

        if (! $namedForThisDevice && ! $startedByOwner) {
            throw ApiException::notFound('That Remote session could not be found.');
        }

        $policy = $this->policies->resolveForDevice($owner, $device);

        $existing = $this->findDeviceParticipant((int) $session['id'], (int) $device['id']);

        // A machine serving this session as a control companion is not its shared screen, and this route
        // must not hand it a token as one: the companion has its own join (devices/me/companions/{uuid}).
        if ($existing !== null && (string) $existing['participant_role'] === ParticipantService::ROLE_COMPANION) {
            throw ApiException::conflict(
                'DEVICE_IS_COMPANION',
                'This computer is linked to that session as a control companion.',
            );
        }

        if ($existing !== null) {
            return $existing;
        }

        return $this->attachDeviceParticipant(
            $session,
            $device,
            $this->devices->effectiveCapabilities($device, $policy),
            // Attended: the machine is the sharer and is its own host, because
            // the person deciding is sitting at it.
            true,
        ) ?? [];
    }

    /**
     * Unattended sessions this device has been asked to host and has not yet
     * joined.
     *
     * The agent reads this when its presence connection comes up and whenever
     * it is woken, so a connection request survives a dropped WebSocket, a
     * sleep/wake cycle and a service restart. The push over the presence room
     * is what makes it fast; this is what makes it reliable.
     *
     * Unattended only, by the `access_mode` predicate below, and that matters
     * now that an attended session can name a device too: one started from
     * the desktop app ({@see startAttended()}) carries this device's id, but
     * the app that asked for it already has it, and an agent that found it
     * here would treat it as somebody connecting with nobody at the machine.
     *
     * @return list<array<string, mixed>>
     */
    public function pendingFor(DevicePrincipal $principal): array
    {
        $device = $this->devices->findByUuidOrFail($principal->deviceUuid);

        $rows = $this->db->table('remote_sessions s')
            ->select('s.*, c.name AS company_name, i.display_name AS owner_name')
            ->join('remote_company_directory c', 'c.company_id = s.company_id', 'left')
            ->join('remote_identities i', 'i.id = s.initiator_user_id', 'left')
            ->where('s.device_id', $device['id'])
            ->where('s.access_mode', 'UNATTENDED')
            ->whereNotIn('s.status', [
                SessionStatus::ENDED,
                SessionStatus::EXPIRED,
                SessionStatus::FAILED,
                SessionStatus::DECLINED,
            ])
            ->where('s.expires_at >', Clock::now())
            ->orderBy('s.created_at', 'DESC')
            ->limit(5)
            ->get()
            ->getResultArray();

        return array_map(fn (array $row) => $this->sessions->castRow($row), $rows);
    }

    /**
     * A device's recent sessions, for its detail page.
     *
     * @param  array<string, mixed> $device
     * @return list<array<string, mixed>>
     */
    public function recentFor(array $device, int $limit = 10): array
    {
        $rows = $this->db->table('remote_sessions s')
            ->select('s.*, c.name AS company_name, i.display_name AS owner_name')
            ->join('remote_company_directory c', 'c.company_id = s.company_id', 'left')
            ->join('remote_identities i', 'i.id = s.owner_user_id', 'left')
            ->where('s.device_id', $device['id'])
            ->orderBy('s.created_at', 'DESC')
            ->limit(max(1, min(50, $limit)))
            ->get()
            ->getResultArray();

        return array_map(fn (array $row) => $this->sessions->castRow($row), $rows);
    }

    // ------------------------------------------- sessions the desktop hosts

    /**
     * "Give your code": somebody at this computer starts a session from the
     * desktop app, reads its code to a helper, and admits them when they ask.
     *
     * The attended counterpart of {@see startUnattended()}, and an ordinary
     * session for the same reason that one is: it goes through
     * `SessionService::create()`, so it gets the same quota, policy snapshot,
     * expiry, join code and SESSION_CREATED audit as one started in a browser,
     * and the person it belongs to is the computer's owner — the only person
     * a device credential can speak for. Nothing about how a helper gets in
     * changes: they join by the code like any other session's, land in the
     * waiting room, and are admitted or declined by the person at the machine
     * ({@see admit()}, {@see decline()}). Control stays attended as well —
     * the helper asks, the computer decides, through the same control route as
     * any session it shares.
     *
     * Three things differ from a browser session, and they are what make it a
     * session *this computer* hosts:
     *
     *   * `device_id` names this device, which is what lets it join as the
     *     session's sharer (`joinAsHost`'s `namedForThisDevice`) and what every
     *     route below checks before acting. `access_mode` stays ATTENDED, so
     *     {@see pendingFor()} never offers it back as an unattended request.
     *   * The whole screen is the requested share: an agent shares a monitor,
     *     not a browser tab, and a scope that forbids that refuses here rather
     *     than producing a session the computer cannot share in.
     *   * The owner's browser seat goes. `create()` makes its creator the
     *     joined, sharing host, because creating a session in a browser means
     *     that; here the owner is at the machine, not in a browser, and the
     *     machine is the host once it joins. Left as it was, a helper's browser
     *     would wait for a picture from a sharer who is not there. It is kept as
     *     a row — the session's owner always has one — but as a viewer who
     *     left without ever joining, which is the truth.
     *
     * A company device (dormant: every account is personal now) would get a
     * session in its own company, exactly as {@see startUnattended()} gives
     * one, because a device can host only a session in its own scope.
     *
     * @return array<string, mixed> the session
     */
    public function startAttended(DevicePrincipal $principal, ?string $issueSummary = null): array
    {
        $device    = $this->devices->findByUuidOrFail($principal->deviceUuid);
        $companyId = $device['company_id'] !== null ? (int) $device['company_id'] : null;
        $owner     = $this->requireOwner($device);

        // The owner's policy in the device's own scope: a personal computer's
        // session is its owner's personal session.
        $policy = $this->policies->resolveForDevice($owner, $device);

        $this->db->transException(true)->transStart();

        try {
            $session = $this->sessions->create($owner, [
                'scopeType'          => $companyId === null ? 'PERSONAL' : 'COMPANY',
                'companyId'          => $companyId,
                'sessionType'        => $companyId === null ? 'ASSISTANCE' : 'INTERNAL',
                'requestedShareMode' => 'ENTIRE_MONITOR',
                'issueSummary'       => $issueSummary,
            ], $policy);

            $this->db->table('remote_sessions')->where('id', $session['id'])->update([
                'access_mode' => 'ATTENDED',
                'device_id'   => $device['id'],
                'updated_at'  => Clock::now(),
            ]);

            $seat = $this->participants->findByUser((int) $session['id'], $owner->id);
            if ($seat !== null) {
                $this->db->table('remote_participants')->where('id', $seat['id'])->update([
                    'participant_role' => ParticipantService::ROLE_VIEWER,
                    'is_host'          => false,
                    'is_sharing'       => false,
                    'status'           => 'LEFT',
                    'joined_at'        => null,
                    'left_at'          => Clock::now(),
                    'connection_state' => 'CLOSED',
                    'updated_at'       => Clock::now(),
                ]);
            }

            // What SESSION_CREATED cannot say: that a machine asked for this,
            // and which one. Recorded as the device acting for its owner, as
            // every other decision a device reports is.
            $this->audit->record(
                $session,
                EventType::DEVICE_SESSION_STARTED,
                $owner->id,
                'DEVICE',
                null,
                null,
                [
                    'deviceUuid' => (string) $device['uuid'],
                    'deviceName' => (string) $device['device_name'],
                ],
            );

            $this->db->transComplete();
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }

        return $this->sessions->findByUuidOrFail((string) $session['uuid']);
    }

    /**
     * Who is waiting to be let in, and who is in, for the session this
     * computer hosts.
     *
     * People only. The computer's own participant is not listed — it is the
     * thing being joined — and nor is any other machine; the owner's browser
     * seat in a session started here never joined, so it is in neither list.
     *
     * @return array{session: array<string, mixed>, waiting: list<array<string, mixed>>, joined: list<array<string, mixed>>}
     */
    public function participantsOfHosted(DevicePrincipal $principal, string $sessionUuid): array
    {
        ['session' => $session] = $this->hostedSession($principal, $sessionUuid);

        $waiting = [];
        $joined  = [];

        foreach ($this->participants->forSession((int) $session['id']) as $participant) {
            if ($participant['device_id'] !== null) {
                continue;
            }

            $status = (string) $participant['status'];
            if ($status === 'REQUESTED') {
                $waiting[] = $participant;
            } elseif ($status === 'APPROVED' || $status === 'JOINED') {
                $joined[] = $participant;
            }
        }

        // Longest-waiting first, as the browser host's queue is.
        usort($waiting, static fn (array $a, array $b): int => strcmp((string) $a['requested_at'], (string) $b['requested_at']));

        return ['session' => $session, 'waiting' => $waiting, 'joined' => $joined];
    }

    /**
     * Let a waiting person in, as the computer's owner.
     *
     * `ParticipantService::approve()` with the owner as the host, which is the
     * same call, the same guarded update and the same PARTICIPANT_APPROVED
     * record as the browser host's Admit — the person at the machine is that
     * host; they are only using a different screen to say so.
     *
     * @return array<string, mixed> the participant
     */
    public function admit(DevicePrincipal $principal, string $sessionUuid, string $participantUuid): array
    {
        ['session' => $session, 'owner' => $owner] = $this->attendedHostedSession($principal, $sessionUuid);
        $this->requirePerson($session, $participantUuid);

        return $this->participants->approve($session, $participantUuid, $owner);
    }

    /**
     * Turn a waiting person away, as the computer's owner — the browser host's
     * Decline, through the same call.
     *
     * @return array<string, mixed> the participant
     */
    public function decline(DevicePrincipal $principal, string $sessionUuid, string $participantUuid): array
    {
        ['session' => $session, 'owner' => $owner] = $this->attendedHostedSession($principal, $sessionUuid);
        $this->requirePerson($session, $participantUuid);

        return $this->participants->deny($session, $participantUuid, $owner);
    }

    /**
     * End the session this computer hosts, as its owner.
     *
     * `SessionService::end()`, so it closes exactly what ending from a browser
     * closes — everybody still in it or waiting, the join code, any companion
     * link, the invitations — and records SESSION_ENDED against the owner.
     * Attended or not: the tray shows a session is running either way, and
     * whoever is at the machine must be able to stop one.
     *
     * @return array<string, mixed> the session
     */
    public function endHosted(DevicePrincipal $principal, string $sessionUuid): array
    {
        ['session' => $session, 'owner' => $owner] = $this->hostedSession($principal, $sessionUuid);

        return $this->sessions->end($session, $owner);
    }

    /**
     * A session this device hosts, or 404.
     *
     * "Hosts" means the session names this device (`device_id`), in the
     * device's own scope. That is narrower than "a session the device could
     * join": an attended browser session the owner started can take their
     * computer as its shared screen, but its waiting room is the browser
     * host's, and a machine must not be able to admit people to a session
     * somebody else is running. Refused as not found, like a session in
     * another scope, so the answer does not confirm a session exists.
     *
     * @return array{session: array<string, mixed>, device: array<string, mixed>, owner: RemoteIdentity}
     */
    private function hostedSession(DevicePrincipal $principal, string $sessionUuid): array
    {
        $device  = $this->devices->findByUuidOrFail($principal->deviceUuid);
        $session = Ids::isUuid($sessionUuid) ? $this->sessions->findByUuid($sessionUuid) : null;

        if ($session === null
            || ! self::sameScope($device, $session)
            || $session['device_id'] === null
            || (int) $session['device_id'] !== (int) $device['id']
        ) {
            throw ApiException::notFound('That Remote session could not be found.');
        }

        return ['session' => $session, 'device' => $device, 'owner' => $this->requireOwner($device)];
    }

    /**
     * A hosted session that somebody is sitting at.
     *
     * An unattended session has nobody at the machine, by definition — that is
     * what the owner agreed to when they switched it on. Whoever connected to
     * it decides who else may watch, from their browser; the machine letting
     * people in on its own would be an empty chair admitting strangers.
     *
     * @return array{session: array<string, mixed>, device: array<string, mixed>, owner: RemoteIdentity}
     */
    private function attendedHostedSession(DevicePrincipal $principal, string $sessionUuid): array
    {
        $hosted = $this->hostedSession($principal, $sessionUuid);

        if ((string) ($hosted['session']['access_mode'] ?? 'ATTENDED') !== 'ATTENDED') {
            throw ApiException::forbidden(
                'SESSION_NOT_ATTENDED',
                'Nobody is at this computer in an unattended session, so it does not admit anyone. The person who connected decides who joins.',
            );
        }

        return $hosted;
    }

    /**
     * The participant is a person in this session — not the computer itself
     * and not another machine. A device row is never "waiting to join", and
     * declining this computer's own participant would take the shared screen
     * out of its own session.
     *
     * @param array<string, mixed> $session
     */
    private function requirePerson(array $session, string $participantUuid): void
    {
        $participant = $this->participants->findByUuid($participantUuid);

        if ($participant === null
            || (int) $participant['session_id'] !== (int) $session['id']
            || $participant['device_id'] !== null
        ) {
            throw ApiException::notFound('That participant could not be found.');
        }
    }

    /** @param array<string, mixed> $device */
    private function requireOwner(array $device): RemoteIdentity
    {
        $owner = $this->ownerIdentity($device);

        if ($owner === null) {
            throw ApiException::conflict(
                'DEVICE_OWNER_MISSING',
                'This device is not linked to an AICOUNTLY user any more. Register it again.',
            );
        }

        return $owner;
    }

    // ------------------------------------------------------------- internals

    /**
     * Create the DESKTOP_AGENT participant that represents the machine.
     *
     * `user_id` is the device's enrolled owner, because the participants table
     * requires a person for anything that is not a guest — but the participant
     * also carries `device_id`, which is what every device-aware code path
     * actually keys on. The narrowed unique index is what lets the owner also
     * be present as themselves in the same session.
     *
     * @param  array<string, mixed> $session
     * @param  array<string, mixed> $device
     * @param  array<string, bool>  $capabilities
     * @return array<string, mixed>|null
     */
    private function attachDeviceParticipant(array $session, array $device, array $capabilities, bool $isHost): ?array
    {
        $owner = $this->ownerIdentity($device);
        if ($owner === null) {
            return null;
        }

        $uuid = Ids::uuid4();

        $this->db->table('remote_participants')->insert([
            'uuid'             => $uuid,
            'session_id'       => $session['id'],
            'user_id'          => $owner->id,
            'device_id'        => $device['id'],
            'participant_role' => ParticipantService::ROLE_SHARER,
            'client_type'      => ClientCapabilities::CLIENT_DESKTOP_AGENT,
            // The intersection, not the declaration. An agent that claims
            // `remote_control: true` in an organisation that forbids it is
            // stored here with `remote_control: false`, and every downstream
            // check — including the one that decides whether control can even
            // be requested — reads this row rather than the claim.
            'capabilities'     => json_encode($capabilities, JSON_UNESCAPED_SLASHES),
            'display_name'     => (string) $device['device_name'],
            'status'           => 'APPROVED',
            'is_host'          => $isHost,
            'requested_at'     => Clock::now(),
            'last_seen_at'     => Clock::now(),
            'os_name'          => $device['operating_system'] !== null ? (string) $device['operating_system'] : null,
        ]);

        return $this->participants->findByUuidOrFail($uuid);
    }

    /** @return array<string, mixed>|null */
    /**
     * Control state, as the machine needs to render it.
     *
     * The agent polls this while a session is running rather than believing
     * anything the peer says over the data channel. A control request that
     * reached the API is the only kind that can be granted — so it is the only
     * kind that should be able to put a consent dialog in front of somebody.
     *
     * @param  array<string, mixed> $session
     * @return array<string, mixed>
     */
    public function controlStateFor(array $session, DevicePrincipal $principal): array
    {
        $device = $this->devices->findByUuidOrFail($principal->deviceUuid);

        if (! self::sameScope($device, $session)) {
            throw ApiException::notFound('That Remote session could not be found.');
        }

        if ($this->findDeviceParticipant((int) $session['id'], (int) $device['id']) === null) {
            throw ApiException::conflict(
                'DEVICE_NOT_IN_SESSION',
                'This computer is not part of that Remote session.',
            );
        }

        $owner = $this->ownerIdentity($device);

        $policy = $owner !== null
            ? $this->policies->resolveForDevice($owner, $device)
            : null;

        $state = $this->control->stateFor($session);

        // A companion reads its own link, not whichever link is the session's latest: if the person linked
        // a different machine since, this one must see that its own has ended.
        $links = \Config\Services::companionLinks();
        $own   = $links->latestFor($session, (int) $device['id']);
        if ($own !== null) {
            $state['companion'] = $links->present($own, $session);
        } elseif (($state['companion']['deviceUuid'] ?? null) !== (string) $device['uuid']) {
            $state['companion'] = null;
        }

        $isCompanion = $this->findDeviceParticipant((int) $session['id'], (int) $device['id'])['participant_role'] === ParticipantService::ROLE_COMPANION;

        return array_merge($state, [
            // The organisation's answer, so the agent's consent dialog cannot
            // offer something the server would refuse.
            'allowRemoteControl' => $policy?->allowRemoteControl ?? false,
            // Owner decision 3: no clipboard through a companion in v1.
            'allowClipboardSync' => ! $isCompanion && ($policy?->allowClipboardSync ?? false),
            'allowDeviceReboot'  => ! $isCompanion && ($policy?->allowDeviceReboot ?? false),
        ]);
    }

    /**
     * Whether a device belongs where a session does: a personal device to a
     * personal session, a company's device to that company's sessions.
     *
     * Compared as nullable ids. These used to compare `(int) company_id`
     * against -1 for a personal session — and `(int) null` is 0, so every
     * personal device was "not found" in every personal session.
     *
     * @param array<string, mixed> $device
     * @param array<string, mixed> $session
     */
    private static function sameScope(array $device, array $session): bool
    {
        $deviceCompany  = $device['company_id'] !== null ? (int) $device['company_id'] : null;
        $sessionCompany = $session['company_id'] !== null ? (int) $session['company_id'] : null;

        return $deviceCompany === $sessionCompany;
    }

    /**
     * The machine answering a control request from the desktop agent.
     *
     * The agent's gate has already taken effect locally — pressing Stop
     * control stops input on the next message, with no network involved. This
     * is how the server learns, so the browser stops sending, the session
     * screen updates and the audit trail records who decided.
     *
     * Everything is re-read here rather than taken from the agent: the device,
     * its company, its participant row in this session and its owner's
     * effective policy. A device that declared itself controllable at
     * enrolment still cannot consent more widely than the person it belongs to
     * may — the capability was only ever an upper bound.
     *
     * @param  array<string, mixed> $session
     * @return array{participant: array<string, mixed>, device: array<string, mixed>}
     */
    public function decideControl(
        array $session,
        DevicePrincipal $principal,
        string $requesterUuid,
        string $decision,
        bool $allowClipboard = false,
    ): array {
        $device = $this->devices->findByUuidOrFail($principal->deviceUuid);

        if (! self::sameScope($device, $session)) {
            throw ApiException::notFound('That Remote session could not be found.');
        }

        $participant = $this->findDeviceParticipant((int) $session['id'], (int) $device['id']);

        if ($participant === null) {
            throw ApiException::conflict(
                'DEVICE_NOT_IN_SESSION',
                'This computer is not part of that Remote session.',
            );
        }

        $owner = $this->ownerIdentity($device);

        if ($owner === null) {
            throw ApiException::conflict(
                'DEVICE_OWNER_MISSING',
                'This device is not linked to an AICOUNTLY user any more. Register it again.',
            );
        }

        $updated = $this->control->decideAsDevice(
            $session,
            $participant,
            $requesterUuid,
            $decision,
            $this->policies->resolveForDevice($owner, $device),
            $allowClipboard,
        );

        return ['participant' => $updated, 'device' => $device];
    }

    private function findDeviceParticipant(int $sessionId, int $deviceId): ?array
    {
        $row = $this->db->table('remote_participants')
            ->where('session_id', $sessionId)
            ->where('device_id', $deviceId)
            ->get()
            ->getRowArray();

        return $row === null ? null : $this->participants->castRow($row);
    }

    /** @param array<string, mixed> $device */
    private function ownerIdentity(array $device): ?RemoteIdentity
    {
        if ($device['user_id'] === null) {
            return null;
        }

        $row = $this->db->table('remote_identities')
            ->select('id, platform_uuid, display_name, email, is_support_agent, is_platform_admin')
            ->where('id', (int) $device['user_id'])
            ->get()
            ->getRowArray();

        if ($row === null) {
            return null;
        }

        return new RemoteIdentity(
            (int) $row['id'],
            (string) $row['platform_uuid'],
            (string) $row['display_name'],
            $row['email'] !== null ? (string) $row['email'] : null,
        );
    }

    /**
     * Which sharing mode an unattended session is created with.
     *
     * A machine nobody is sitting at has no browser tab to pick, so the whole
     * screen is the only mode that means anything — but the organisation still
     * decides. Where it forbids entire-monitor sharing, the widest mode it does
     * permit is used and the agent shares that instead of failing outright.
     *
     * @param list<string> $allowed
     */
    private function shareModeFor(array $allowed): string
    {
        foreach (['ENTIRE_MONITOR', 'APPLICATION_WINDOW', 'BROWSER_TAB', 'SAFE_SHARE'] as $mode) {
            if (in_array($mode, $allowed, true)) {
                return $mode;
            }
        }

        throw ApiException::forbidden(
            'SHARE_MODE_NOT_ALLOWED',
            'This organisation permits no sharing mode, so a device cannot share its screen.',
        );
    }
}
