<?php

declare(strict_types=1);

namespace App\Domain\Device;

use App\Domain\Audit\AuditService;
use App\Domain\Audit\EventType;
use App\Domain\Auth\RemoteIdentity;
use App\Domain\Policy\EffectivePolicy;
use App\Domain\Policy\EffectivePolicyResolver;
use App\Domain\Policy\PermissionCatalog;
use App\Domain\Session\ClientCapabilities;
use App\Domain\Session\CompanionLinks;
use App\Domain\Session\ControlService;
use App\Domain\Session\ParticipantService;
use App\Domain\Session\SessionService;
use App\Domain\Session\SessionStatus;
use App\Domain\Support\ApiException;
use App\Domain\Support\Clock;
use App\Domain\Support\Ids;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;

/**
 * Control companions: control of a computer whose screen is shared from Chrome or Edge
 * (docs/desktop/CONTROL_COMPANION.md).
 *
 * ```
 *   A (browser, sharing an entire screen)        A's own desktop app                B (viewer)
 *   ─────────────────────────────────────        ───────────────────                ──────────
 *   link(device)            → PENDING
 *                                                confirms at the machine
 *                                                join      → JOINED (participant)
 *                                                monitor   → ACTIVE
 *                                                                                   request
 *   grant (A, in the browser) ────────────────────────────────────────────────────▶ GRANTED
 *                                                applies B's input, nothing else
 * ```
 *
 * The picture still comes from A's browser; the companion is an input-only participant with one capability,
 * `remote_control`, linked to A's browser participant. What has to hold before a keystroke moves:
 *
 *   1. the session is a COMPANY session whose organisation permits remote control (policy ∧ the
 *      `desktop_devices` entitlement ∧ the desktop feature) — never a personal session, never a guest;
 *   2. A holds `remote.control.accept` and is the joined, sharing host; the device is A's own, in the
 *      session's company, ACTIVE, online and declares remote control;
 *   3. somebody at that machine confirmed the link there (decision 2), within five minutes;
 *   4. the link is ACTIVE (it knows the monitor), A is still sharing, and A shares an entire screen;
 *   5. A granted B's request in the browser — the companion can stop control but never grant it.
 *
 * One live link per session and one per device, enforced by the database. Everything is audited, and any
 * change the grant depended on — the surface, the share, A leaving, the link ending — revokes it.
 */
class CompanionService
{
    public const MATCH_AUTO_SINGLE = 'AUTO_SINGLE';
    public const MATCH_AUTO_ASPECT = 'AUTO_ASPECT';
    public const MATCH_USER        = 'USER';

    public function __construct(
        private readonly BaseConnection $db,
        private readonly DeviceService $devices,
        private readonly SessionService $sessions,
        private readonly ParticipantService $participants,
        private readonly ControlService $control,
        private readonly CompanionLinks $links,
        private readonly EffectivePolicyResolver $policies,
        private readonly AuditService $audit,
    ) {
    }

    // ------------------------------------------------------------ the browser

    /**
     * A's own devices that could serve this session as a control companion.
     *
     * Never another person's machine, never another company's: the list is the caller's own enrolled
     * devices in the session's company, and only those that are ACTIVE, online and declare remote control.
     *
     * @param  array<string, mixed> $session
     * @return array{devices: list<array<string, mixed>>, restrictions: list<string>}
     */
    public function eligibleDevices(array $session, RemoteIdentity $identity, EffectivePolicy $policy): array
    {
        $restrictions = $this->restrictionsFor($session, $policy);

        // A personal session can link a personal device: the person sharing
        // their screen is the person whose computer it is, which is the only
        // relationship this has ever required. What it cannot do is reach
        // across that line — a company session lists that company's devices,
        // a personal session lists the ones belonging to nobody but its owner.
        $personal = $session['company_id'] === null && (string) $session['scope_type'] === 'PERSONAL';

        if (! $personal && ($session['company_id'] === null || (string) $session['scope_type'] !== 'COMPANY')) {
            return ['devices' => [], 'restrictions' => $restrictions];
        }

        $rows = $this->db->table('remote_devices')
            ->where('company_id', $personal ? null : (int) $session['company_id'])
            ->where('user_id', $identity->id)
            ->where('status', DeviceService::STATUS_ACTIVE)
            ->orderBy('device_name', 'ASC')
            ->limit(50)
            ->get()
            ->getResultArray();

        $devices = [];
        foreach ($rows as $row) {
            $device = $this->devices->castRow($row);

            if (($device['capabilities']['remote_control'] ?? false) !== true || ! $this->devices->isOnline($device)) {
                continue;
            }

            $devices[] = $device;
        }

        if ($devices === [] && $restrictions === []) {
            $restrictions[] = 'NO_ELIGIBLE_DEVICE';
        }

        return ['devices' => $devices, 'restrictions' => $restrictions];
    }

    /**
     * A links one of their own devices to the screen they are sharing.
     *
     * @param  array<string, mixed> $session
     * @return array{link: array<string, mixed>, device: array<string, mixed>}
     */
    public function link(array $session, RemoteIdentity $identity, EffectivePolicy $policy, string $deviceUuid): array
    {
        if (! SessionStatus::isLive((string) $session['status'])) {
            throw ApiException::conflict('SESSION_ALREADY_ENDED', 'This Remote session has already finished.');
        }

        if ((string) $session['scope_type'] !== 'COMPANY' || $session['company_id'] === null || ! $policy->allowRemoteControl) {
            throw ApiException::forbidden(
                'REMOTE_CONTROL_NOT_ALLOWED',
                'Remote control is not enabled for this organisation.',
                ['restrictions' => $policy->restrictions],
            );
        }

        if (! $policy->can(PermissionCatalog::CONTROL_ACCEPT)) {
            throw ApiException::forbidden(
                'CONTROL_ACCEPT_DENIED',
                'You do not have permission to hand over control of this computer.',
                ['permission' => PermissionCatalog::CONTROL_ACCEPT],
            );
        }

        $sharer = $this->participants->findByUser((int) $session['id'], $identity->id);

        if ($sharer === null || $sharer['is_host'] !== true || (string) $sharer['status'] !== 'JOINED' || $sharer['is_sharing'] !== true) {
            throw ApiException::forbidden(
                'NOT_SESSION_HOST',
                'Only the person sharing their screen in this session can enable control from their computer.',
            );
        }

        // The caller's own machine, in this session's company — anything else is "not found", so a device
        // uuid from another person or another tenant cannot be probed for.
        $device = $this->devices->findByUuid($deviceUuid);

        if ($device === null
            || self::companyOf($device) !== self::companyOf($session)
            || $device['user_id'] === null
            || (int) $device['user_id'] !== $identity->id) {
            throw ApiException::notFound('That device could not be found.');
        }

        if ((string) $device['status'] !== DeviceService::STATUS_ACTIVE) {
            throw ApiException::conflict('DEVICE_NOT_ACTIVE', 'That device is not active in AICOUNTLY Remote.');
        }

        if (! $this->devices->isOnline($device)) {
            throw ApiException::conflict(
                'DEVICE_OFFLINE',
                'That computer is not reachable at the moment. Open AICOUNTLY Remote on it and try again.',
                ['lastSeenAt' => Clock::iso($device['last_seen_at'] ?? null)],
            );
        }

        if (($this->devices->effectiveCapabilities($device, $policy)['remote_control'] ?? false) !== true) {
            throw ApiException::conflict(
                'DEVICE_NOT_CONTROLLABLE',
                'That computer cannot be controlled. Update AICOUNTLY Remote on it.',
            );
        }

        if ($this->links->liveFor($session) !== null) {
            throw ApiException::conflict(
                'COMPANION_ALREADY_LINKED',
                'A computer is already linked to this session. Stop it first.',
            );
        }

        // A session the machine is already in some other way (as the shared screen itself), or a live link
        // to another session, is a machine that is busy.
        $inSession = $this->db->table('remote_participants')
            ->where('session_id', $session['id'])
            ->where('device_id', $device['id'])
            ->where('participant_role <>', ParticipantService::ROLE_COMPANION)
            ->countAllResults();

        $elsewhere = $this->db->table('remote_control_companions')
            ->where('device_id', $device['id'])
            ->whereIn('status', CompanionLinks::LIVE)
            ->get()
            ->getResultArray();

        foreach ($elsewhere as $other) {
            // Expired on read, like everything else that lapses.
            $otherSession = $this->sessions->findById((int) $other['session_id']);
            if ($otherSession !== null) {
                $this->links->sweep($otherSession);
            }
        }

        $stillLive = $this->db->table('remote_control_companions')
            ->where('device_id', $device['id'])
            ->whereIn('status', CompanionLinks::LIVE)
            ->countAllResults();

        if ($inSession > 0 || $stillLive > 0) {
            throw ApiException::conflict('DEVICE_BUSY', 'That computer is already in use in another Remote session.');
        }

        // A session that already has a controllable machine (the agent sharing its own screen) needs no
        // companion, and two would make "who is being controlled" ambiguous.
        $host = $this->control->controllableHost($session);
        if ($host !== null) {
            throw ApiException::conflict(
                'COMPANION_ALREADY_LINKED',
                'This session can already be controlled.',
            );
        }

        $uuid = Ids::uuid4();

        try {
            $this->db->table('remote_control_companions')->insert([
                'uuid'                  => $uuid,
                'session_id'            => $session['id'],
                'device_id'             => $device['id'],
                'sharer_participant_id' => $sharer['id'],
                'requested_by_user_id'  => $identity->id,
                'status'                => CompanionLinks::STATUS_PENDING,
                'expires_at'            => Clock::inMinutes(CompanionLinks::PENDING_MINUTES),
                'created_at'            => Clock::now(),
                'updated_at'            => Clock::now(),
            ]);
        } catch (DatabaseException $exception) {
            // Two links racing: the partial unique indexes let exactly one in.
            throw ApiException::conflict('COMPANION_ALREADY_LINKED', 'A computer is already linked to this session.');
        }

        if ($this->db->affectedRows() === 0) {
            throw ApiException::conflict('COMPANION_ALREADY_LINKED', 'A computer is already linked to this session.');
        }

        $link = $this->links->findByUuid($uuid) ?? throw ApiException::notFound();

        $this->audit->record(
            $session,
            EventType::CONTROL_COMPANION_REQUESTED,
            $identity->id,
            'USER',
            (int) $sharer['id'],
            (string) $sharer['uuid'],
            [
                'companionUuid' => $uuid,
                'deviceUuid'    => (string) $device['uuid'],
                'deviceName'    => (string) $device['device_name'],
                'expiresAt'     => Clock::iso($link['expires_at']),
            ],
        );

        return ['link' => $link, 'device' => $device];
    }

    /**
     * End the session's link from the browser: the host, the session's owner, or the person it serves.
     *
     * Idempotent — ending a link that already ended changes nothing and records nothing.
     *
     * @param  array<string, mixed> $session
     * @return array<string, mixed>|null the link as it now stands
     */
    public function unlink(array $session, RemoteIdentity $identity): ?array
    {
        $link = $this->links->liveFor($session);

        if ($link === null) {
            return $this->links->latestFor($session);
        }

        $self    = $this->participants->findByUser((int) $session['id'], $identity->id);
        $isHost  = $self !== null && $self['is_host'] === true;
        $isOwner = (int) $session['owner_user_id'] === $identity->id;
        $isSharer = $self !== null && (int) $self['id'] === (int) $link['sharer_participant_id'];

        if (! $isHost && ! $isOwner && ! $isSharer) {
            throw ApiException::forbidden(
                'NOT_SESSION_HOST',
                'Only the person sharing their screen can stop control from their computer.',
            );
        }

        $this->endLink($session, $link, 'ENDED_BY_USER', $identity->id, 'USER');

        return $this->links->findById((int) $link['id']);
    }

    // ------------------------------------------------------------- the machine

    /**
     * Links waiting for this machine's confirmation, for `GET /devices/me`.
     *
     * @return list<array<string, mixed>>
     */
    public function pendingFor(DevicePrincipal $principal): array
    {
        $device = $this->devices->findByUuidOrFail($principal->deviceUuid);

        $sessionIds = $this->db->table('remote_control_companions')
            ->select('session_id')
            ->where('device_id', $device['id'])
            ->where('status', CompanionLinks::STATUS_PENDING)
            ->get()
            ->getResultArray();

        foreach ($sessionIds as $row) {
            $session = $this->sessions->findById((int) $row['session_id']);
            if ($session !== null) {
                $this->links->sweep($session);
            }
        }

        $rows = $this->db->table('remote_control_companions l')
            ->select('l.uuid, l.created_at, l.expires_at, s.uuid AS session_uuid, s.display_id,
                      c.name AS company_name, i.display_name AS requested_by_name')
            ->join('remote_sessions s', 's.id = l.session_id')
            ->join('remote_company_directory c', 'c.company_id = s.company_id', 'left')
            ->join('remote_identities i', 'i.id = l.requested_by_user_id', 'left')
            ->where('l.device_id', $device['id'])
            ->where('l.status', CompanionLinks::STATUS_PENDING)
            // The device's own company's sessions — or, for a personal device,
            // personal ones (`company_id IS NULL`), never "company 0".
            ->where('s.company_id', self::companyOf($device))
            ->orderBy('l.created_at', 'DESC')
            ->limit(5)
            ->get()
            ->getResultArray();

        return array_map(static fn (array $row): array => [
            'companionUuid'   => (string) $row['uuid'],
            'sessionUuid'     => (string) $row['session_uuid'],
            'displayId'       => (string) $row['display_id'],
            'companyName'     => $row['company_name'] ?? null,
            'requestedByName' => $row['requested_by_name'] ?? null,
            'requestedAt'     => Clock::iso($row['created_at']),
            'expiresAt'       => Clock::iso($row['expires_at']),
        ], $rows);
    }

    /**
     * The machine joins, after somebody at it confirmed the link there.
     *
     * Idempotent: a second call (a reconnect, a renewed token) returns the same participant, so the
     * caller can mint it a fresh signalling token. Everything is re-read and re-checked here — the session,
     * the person it serves, the device's owner and their policy — rather than taken from the link.
     *
     * @return array{link: array<string, mixed>, session: array<string, mixed>, participant: array<string, mixed>}
     */
    public function join(DevicePrincipal $principal, string $companionUuid): array
    {
        [$device, $link, $session] = $this->requireOwnLink($principal, $companionUuid);

        if (in_array((string) $link['status'], [CompanionLinks::STATUS_JOINED, CompanionLinks::STATUS_ACTIVE], true)
            && $link['companion_participant_id'] !== null) {
            return [
                'link'        => $link,
                'session'     => $session,
                'participant' => $this->participants->findByIdOrFail((int) $link['companion_participant_id']),
            ];
        }

        if ((string) $link['status'] !== CompanionLinks::STATUS_PENDING) {
            throw $this->notPending($link);
        }

        $sharer = $this->participants->findByIdOrFail((int) $link['sharer_participant_id']);

        if ((string) $sharer['status'] !== 'JOINED') {
            $this->endLink($session, $link, 'SHARER_LEFT', null, 'SYSTEM');

            throw ApiException::conflict('COMPANION_NOT_PENDING', 'The person who asked for this has left the session.');
        }

        // The machine must still be the person's own, and they must still be allowed to hand it over.
        $owner = $this->ownerIdentity($device);
        if ($owner === null || $sharer['user_id'] === null || $owner->id !== (int) $sharer['user_id']) {
            throw ApiException::notFound('That control request could not be found.');
        }

        $policy = $this->policies->resolveForDevice($owner, $device);

        if (! $policy->allowRemoteControl) {
            throw ApiException::forbidden(
                'REMOTE_CONTROL_NOT_ALLOWED',
                'Remote control is not enabled for this organisation.',
                ['restrictions' => $policy->restrictions],
            );
        }

        if (! $policy->can(PermissionCatalog::CONTROL_ACCEPT)) {
            throw ApiException::forbidden(
                'CONTROL_ACCEPT_DENIED',
                'This computer is not permitted to hand over control.',
                ['permission' => PermissionCatalog::CONTROL_ACCEPT],
            );
        }

        $capabilities = $this->companionCapabilities($device, $policy);
        if ($capabilities['remote_control'] !== true) {
            throw ApiException::conflict('DEVICE_NOT_CONTROLLABLE', 'This computer cannot be controlled.');
        }

        $participant = $this->attachCompanion($session, $device, $owner, $sharer, $capabilities);

        $this->db->table('remote_control_companions')
            ->where('id', $link['id'])
            ->where('status', CompanionLinks::STATUS_PENDING)
            ->update([
                'status'                   => CompanionLinks::STATUS_JOINED,
                'companion_participant_id' => $participant['id'],
                'joined_at'                => Clock::now(),
                'updated_at'               => Clock::now(),
            ]);

        if ($this->db->affectedRows() > 0) {
            $this->audit->record(
                $session,
                EventType::CONTROL_COMPANION_JOINED,
                $owner->id,
                'DEVICE',
                (int) $participant['id'],
                (string) $participant['uuid'],
                [
                    'companionUuid'         => (string) $link['uuid'],
                    'deviceUuid'            => (string) $device['uuid'],
                    'linkedParticipantUuid' => (string) $sharer['uuid'],
                ],
            );
        }

        $link = $this->links->findById((int) $link['id']) ?? $link;

        return ['link' => $link, 'session' => $session, 'participant' => $participant];
    }

    /**
     * Somebody at the machine said no.
     *
     * @return array{link: array<string, mixed>, session: array<string, mixed>}
     */
    public function decline(DevicePrincipal $principal, string $companionUuid): array
    {
        [$device, $link, $session] = $this->requireOwnLink($principal, $companionUuid);

        if ((string) $link['status'] === CompanionLinks::STATUS_DECLINED) {
            return ['link' => $link, 'session' => $session];
        }

        if ((string) $link['status'] !== CompanionLinks::STATUS_PENDING) {
            throw $this->notPending($link);
        }

        $this->links->finish($session, $link, CompanionLinks::STATUS_DECLINED, 'DECLINED_AT_DEVICE', $this->ownerId($device), 'DEVICE');

        return ['link' => $this->links->findById((int) $link['id']) ?? $link, 'session' => $session];
    }

    /**
     * The machine says which of its monitors the shared picture is, and the link becomes ACTIVE.
     *
     * @param  array<string, mixed> $monitor
     * @return array{link: array<string, mixed>, session: array<string, mixed>}
     */
    public function selectMonitor(DevicePrincipal $principal, string $companionUuid, array $monitor, string $matchedBy): array
    {
        [$device, $link, $session] = $this->requireOwnLink($principal, $companionUuid);

        if (! in_array((string) $link['status'], [CompanionLinks::STATUS_JOINED, CompanionLinks::STATUS_ACTIVE], true)) {
            throw ApiException::conflict(
                'COMPANION_NOT_JOINED',
                'This computer has not joined that session.',
                ['status' => (string) $link['status']],
            );
        }

        if (! in_array($matchedBy, [self::MATCH_AUTO_SINGLE, self::MATCH_AUTO_ASPECT, self::MATCH_USER], true)) {
            throw ApiException::badRequest('VALIDATION_FAILED', 'Some of the details sent were not valid.', [
                'fields' => ['matchedBy' => 'Must be one of: AUTO_SINGLE, AUTO_ASPECT, USER'],
            ]);
        }

        $clean = self::cleanMonitor($monitor);

        $previous = $link['monitor'] ?? null;
        if (is_string($previous)) {
            $previous = json_decode($previous, true) ?: null;
        }

        if ((string) $link['status'] === CompanionLinks::STATUS_ACTIVE && $previous === $clean && $link['monitor_match'] === $matchedBy) {
            return ['link' => $link, 'session' => $session];
        }

        // Input mapped onto a different monitor while somebody is controlling would land somewhere they
        // cannot see. Control stops; the next grant is made against the new mapping.
        if ((string) $link['status'] === CompanionLinks::STATUS_ACTIVE) {
            $this->control->revokeBecause($session, 'MONITOR_CHANGED', $this->ownerId($device), 'DEVICE');
        }

        $this->db->table('remote_control_companions')
            ->where('id', $link['id'])
            ->whereIn('status', [CompanionLinks::STATUS_JOINED, CompanionLinks::STATUS_ACTIVE])
            ->update([
                'status'        => CompanionLinks::STATUS_ACTIVE,
                'monitor'       => json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'monitor_match' => $matchedBy,
                'updated_at'    => Clock::now(),
            ]);

        $this->audit->record(
            $session,
            EventType::CONTROL_COMPANION_READY,
            $this->ownerId($device),
            'DEVICE',
            $link['companion_participant_id'] !== null ? (int) $link['companion_participant_id'] : null,
            $link['companion_participant_uuid'] ?? null,
            [
                'companionUuid' => (string) $link['uuid'],
                'matchedBy'     => $matchedBy,
                'monitor'       => [
                    'id'     => $clean['id'],
                    'width'  => $clean['width'],
                    'height' => $clean['height'],
                    'scale'  => $clean['scale'],
                ],
            ],
        );

        return ['link' => $this->links->findById((int) $link['id']) ?? $link, 'session' => $session];
    }

    /**
     * The machine leaves: Stop at the machine, the app quitting, the person signing out of it.
     *
     * @return array{link: array<string, mixed>, session: array<string, mixed>}
     */
    public function leave(DevicePrincipal $principal, string $companionUuid): array
    {
        [$device, $link, $session] = $this->requireOwnLink($principal, $companionUuid);

        if (in_array((string) $link['status'], CompanionLinks::LIVE, true)) {
            $this->endLink($session, $link, 'LEFT_AT_DEVICE', $this->ownerId($device), 'DEVICE');
        }

        return ['link' => $this->links->findById((int) $link['id']) ?? $link, 'session' => $session];
    }

    // ------------------------------------------------- what a grant depends on

    /**
     * The browser reported a share. A different picture from the one the link was matched to — another
     * surface, or the same surface at another size — means the monitor has to be matched again: an ACTIVE
     * link goes back to JOINED, and control it carried is revoked (SURFACE_CHANGED).
     *
     * @param array<string, mixed>                                              $session
     * @param array{displaySurface: ?string, width: ?int, height: ?int}         $before
     * @param array{displaySurface: string, width: ?int, height: ?int}          $after
     */
    public function onShareStarted(array $session, RemoteIdentity $identity, array $before, array $after): void
    {
        $link = $this->linkServing($session, $identity->id);
        if ($link === null || $before === $after) {
            return;
        }

        $this->control->revokeBecause($session, 'SURFACE_CHANGED');
        $this->rematch($session, $link, 'SURFACE_CHANGED');
    }

    /**
     * The browser stopped sharing (or a context mismatch stopped it). Control ends (SHARE_STOPPED), and the
     * next share is matched afresh.
     *
     * @param array<string, mixed> $session
     */
    public function onShareStopped(array $session, RemoteIdentity $identity): void
    {
        $link = $this->linkServing($session, $identity->id);
        if ($link === null) {
            return;
        }

        $this->control->revokeBecause($session, 'SHARE_STOPPED');
        $this->rematch($session, $link, 'SHARE_STOPPED');
    }

    /**
     * Somebody left. If it was the person a companion serves, the link ends and control with it
     * (SHARER_LEFT).
     *
     * @param array<string, mixed> $session
     * @param array<string, mixed> $participant
     */
    public function onParticipantLeft(array $session, array $participant): void
    {
        $link = $this->links->liveFor($session);

        if ($link === null || (int) $link['sharer_participant_id'] !== (int) $participant['id']) {
            return;
        }

        $this->endLink($session, $link, 'SHARER_LEFT', null, 'SYSTEM');
    }

    // ------------------------------------------------------------- internals

    /**
     * End a live link and take away any control that ran through it.
     *
     * @param array<string, mixed> $session
     * @param array<string, mixed> $link
     */
    private function endLink(array $session, array $link, string $reason, ?int $actorUserId, string $actorType): void
    {
        // Control first: the moment the link ends there is nothing for a grant to point at.
        if ((string) $link['status'] === CompanionLinks::STATUS_ACTIVE) {
            $this->control->revokeBecause($session, $reason, $actorUserId, $actorType);
        }

        $this->links->finish($session, $link, CompanionLinks::STATUS_ENDED, $reason, $actorUserId, $actorType);
    }

    /**
     * ACTIVE → JOINED: the companion stays in the session but has to match the monitor again.
     *
     * @param array<string, mixed> $session
     * @param array<string, mixed> $link
     */
    private function rematch(array $session, array $link, string $reason): void
    {
        if ((string) $link['status'] !== CompanionLinks::STATUS_ACTIVE) {
            return;
        }

        $this->db->table('remote_control_companions')
            ->where('id', $link['id'])
            ->where('status', CompanionLinks::STATUS_ACTIVE)
            ->update(['status' => CompanionLinks::STATUS_JOINED, 'updated_at' => Clock::now()]);

        if ($this->db->affectedRows() === 0) {
            return;
        }

        // Recorded as the companion being joined-but-not-ready again, with why.
        $this->audit->record(
            $session,
            EventType::CONTROL_COMPANION_JOINED,
            null,
            'SYSTEM',
            $link['companion_participant_id'] !== null ? (int) $link['companion_participant_id'] : null,
            $link['companion_participant_uuid'] ?? null,
            ['companionUuid' => (string) $link['uuid'], 'reason' => $reason, 'from' => CompanionLinks::STATUS_ACTIVE],
        );
    }

    /**
     * The session's live link, when it serves this person's browser participant.
     *
     * @param  array<string, mixed> $session
     * @return array<string, mixed>|null
     */
    private function linkServing(array $session, int $userId): ?array
    {
        $link = $this->links->liveFor($session);

        if ($link === null || $link['sharer_user_id'] === null || (int) $link['sharer_user_id'] !== $userId) {
            return null;
        }

        return $link;
    }

    /**
     * This machine's own link, and its session, or "not found" — a link for another machine, or one in a
     * session of another company, does not exist as far as this device is concerned.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function requireOwnLink(DevicePrincipal $principal, string $companionUuid): array
    {
        $device = $this->devices->findByUuidOrFail($principal->deviceUuid);
        $link   = $this->links->findByUuid($companionUuid);

        if ($link === null || (int) $link['device_id'] !== (int) $device['id']) {
            throw ApiException::notFound('That control request could not be found.');
        }

        $session = $this->sessions->findById((int) $link['session_id']);

        if ($session === null || self::companyOf($session) !== self::companyOf($device)) {
            throw ApiException::notFound('That control request could not be found.');
        }

        if ((string) $device['status'] !== DeviceService::STATUS_ACTIVE) {
            throw ApiException::forbidden('DEVICE_NOT_ACTIVE', 'This device is not active in AICOUNTLY Remote.');
        }

        // Expired, or its session over: finished on read, then re-read.
        $this->links->sweep($session);
        $link = $this->links->findById((int) $link['id']) ?? $link;

        return [$device, $link, $session];
    }

    /**
     * Join the machine as the input-only participant, or bring its earlier one back.
     *
     * One machine appears at most once in a session (the participants' unique index), so a link made again
     * in the same session reuses the companion row rather than adding a second.
     *
     * @param  array<string, mixed> $session
     * @param  array<string, mixed> $device
     * @param  array<string, mixed> $sharer
     * @param  array<string, bool>  $capabilities
     * @return array<string, mixed>
     */
    private function attachCompanion(array $session, array $device, RemoteIdentity $owner, array $sharer, array $capabilities): array
    {
        $existing = $this->db->table('remote_participants')
            ->where('session_id', $session['id'])
            ->where('device_id', $device['id'])
            ->get()
            ->getRowArray();

        $columns = [
            'user_id'                     => $owner->id,
            'companion_of_participant_id' => $sharer['id'],
            'capabilities'                => json_encode($capabilities, JSON_UNESCAPED_SLASHES),
            'display_name'                => mb_substr((string) $device['device_name'] . ' (control)', 0, 160),
            'status'                      => 'JOINED',
            'is_host'                     => false,
            'is_sharing'                  => false,
            'control_state'               => ControlService::STATE_NONE,
            'clipboard_enabled'           => false,
            'joined_at'                   => Clock::now(),
            'left_at'                     => null,
            'last_seen_at'                => Clock::now(),
            'connection_state'            => 'CONNECTING',
            'os_name'                     => $device['operating_system'] !== null ? (string) $device['operating_system'] : null,
            'updated_at'                  => Clock::now(),
        ];

        if ($existing !== null) {
            if ((string) $existing['participant_role'] !== ParticipantService::ROLE_COMPANION) {
                throw ApiException::conflict('DEVICE_BUSY', 'This computer is already part of that session.');
            }

            $this->db->table('remote_participants')->where('id', $existing['id'])->update($columns);

            return $this->participants->findByIdOrFail((int) $existing['id']);
        }

        $uuid = Ids::uuid4();

        $this->db->table('remote_participants')->insert(array_merge($columns, [
            'uuid'             => $uuid,
            'session_id'       => $session['id'],
            'device_id'        => $device['id'],
            'participant_role' => ParticipantService::ROLE_COMPANION,
            'client_type'      => ClientCapabilities::CLIENT_DESKTOP_AGENT,
            'requested_at'     => Clock::now(),
            'created_at'       => Clock::now(),
        ]));

        return $this->participants->findByUuidOrFail($uuid);
    }

    /**
     * The device's declaration ∧ the owner's policy, then narrowed to the one thing a companion does.
     * No sharing, no files, no unattended access, no reboot — and no clipboard in v1 (decision 3).
     *
     * @param  array<string, mixed> $device
     * @return array<string, bool>
     */
    private function companionCapabilities(array $device, EffectivePolicy $policy): array
    {
        $effective = $this->devices->effectiveCapabilities($device, $policy);

        $capabilities = array_fill_keys(array_keys(ClientCapabilities::desktopAgent()), false);
        $capabilities['remote_control'] = ($effective['remote_control'] ?? false) === true;

        return $capabilities;
    }

    /**
     * A monitor as the machine described it, kept to the shape and ranges that make sense.
     *
     * @param  array<string, mixed> $monitor
     * @return array{id: int, name: string, x: int, y: int, width: int, height: int, scale: float}
     */
    public static function cleanMonitor(array $monitor): array
    {
        $fields = [];

        $int = static function (string $key, int $min, int $max) use ($monitor, &$fields): int {
            $value = $monitor[$key] ?? null;
            if (is_float($value) && floor($value) === $value) {
                $value = (int) $value;
            }
            if (! is_int($value) || $value < $min || $value > $max) {
                $fields[$key] = "Must be a whole number from {$min} to {$max}.";

                return 0;
            }

            return $value;
        };

        $clean = [
            'id'     => $int('id', 0, 2147483647),
            'name'   => '',
            'x'      => $int('x', -65536, 65536),
            'y'      => $int('y', -65536, 65536),
            'width'  => $int('width', 1, 16384),
            'height' => $int('height', 1, 16384),
            'scale'  => 1.0,
        ];

        $name = $monitor['name'] ?? '';
        $clean['name'] = is_string($name) ? mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name) ?? ''), 0, 120) : '';

        $scale = $monitor['scale'] ?? 1;
        if (! is_int($scale) && ! is_float($scale) || $scale < 0.25 || $scale > 8) {
            $fields['scale'] = 'Must be a number from 0.25 to 8.';
        } else {
            $clean['scale'] = (float) $scale;
        }

        if ($fields !== []) {
            throw ApiException::badRequest('VALIDATION_FAILED', 'Some of the details sent were not valid.', [
                'fields' => array_combine(array_map(static fn (string $k) => 'monitor.' . $k, array_keys($fields)), $fields),
            ]);
        }

        return $clean;
    }

    /**
     * What stops this person enabling a companion here, in the machine reasons the UI explains.
     *
     * @param  array<string, mixed> $session
     * @return list<string>
     */
    private function restrictionsFor(array $session, EffectivePolicy $policy): array
    {
        if ((string) $session['scope_type'] !== 'COMPANY' || $session['company_id'] === null) {
            return ['PERSONAL_SCOPE'];
        }

        $restrictions = [];

        if (! $policy->allowRemoteControl) {
            $restrictions[] = 'REMOTE_CONTROL_NOT_ALLOWED';
            foreach ($policy->restrictions as $restriction) {
                if (str_starts_with((string) $restriction, 'REMOTE_CONTROL')) {
                    $restrictions[] = (string) $restriction;
                }
            }
        }

        if (! $policy->can(PermissionCatalog::CONTROL_ACCEPT)) {
            $restrictions[] = 'CONTROL_ACCEPT_DENIED';
        }

        return array_values(array_unique($restrictions));
    }

    /** @param array<string, mixed> $link */
    private function notPending(array $link): ApiException
    {
        return ApiException::conflict(
            'COMPANION_NOT_PENDING',
            match ((string) $link['status']) {
                CompanionLinks::STATUS_EXPIRED  => 'That request timed out. Ask again from the browser.',
                CompanionLinks::STATUS_DECLINED => 'That request was declined.',
                default                         => 'That request has already ended.',
            },
            ['status' => (string) $link['status']],
        );
    }

    /** @param array<string, mixed> $device */
    private function ownerId(array $device): ?int
    {
        return $device['user_id'] !== null ? (int) $device['user_id'] : null;
    }

    /** @param array<string, mixed> $device */
    private function ownerIdentity(array $device): ?RemoteIdentity
    {
        if ($device['user_id'] === null) {
            return null;
        }

        $row = $this->db->table('remote_identities')
            ->select('id, platform_uuid, display_name, email')
            ->where('id', (int) $device['user_id'])
            ->get()
            ->getRowArray();

        return $row === null ? null : new RemoteIdentity(
            (int) $row['id'],
            (string) $row['platform_uuid'],
            (string) $row['display_name'],
            $row['email'] !== null ? (string) $row['email'] : null,
        );
    }

    /**
     * A row's company, or null for a personal one. Never `(int) null`, which
     * is 0 — a company nobody has — and made every personal device and
     * personal session look like it belonged to it.
     *
     * @param array<string, mixed> $row
     */
    private static function companyOf(array $row): ?int
    {
        return $row['company_id'] !== null ? (int) $row['company_id'] : null;
    }
}
