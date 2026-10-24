<?php

declare(strict_types=1);

namespace App\Domain\Session;

use App\Domain\Audit\AuditService;
use App\Domain\Audit\EventType;
use App\Domain\Auth\RemoteIdentity;
use App\Domain\Policy\EffectivePolicy;
use App\Domain\Policy\PermissionCatalog;
use App\Domain\Support\ApiException;
use App\Domain\Support\Clock;
use CodeIgniter\Database\BaseConnection;

/**
 * Attended remote control: asking for it, granting it, and taking it back.
 *
 * ```
 *   viewer                        host (the machine being controlled)
 *   ------                        ---------------------------------
 *   request  ─────────────────▶   CONTROL_REQUESTED
 *                                 person at the keyboard sees who, and from
 *                                 which organisation, and what control means
 *                    ◀─────────   grant   → CONTROL_GRANTED
 *                                 or deny → CONTROL_DENIED
 *   input flows on the data channel while the state is GRANTED — and only then
 *                    ◀─────────   revoke  → CONTROL_REVOKED   (either side)
 * ```
 *
 * Five things have to be true before a keystroke moves, and all five are
 * checked here rather than in the agent:
 *
 *   1. the host participant's negotiated capabilities include `remote_control`
 *      (a browser's never do, so a browser can never be controlled);
 *   2. the organisation permits remote control;
 *   3. the requester holds `remote.control.request`;
 *   4. the host holds `remote.control.accept` — or the session is an
 *      authorised unattended one, where the enrolled device is the consent;
 *   5. the grant is recorded, and is revocable instantly by either side.
 *
 * There is no hidden mode. A granted session is visible in the agent, in the
 * browser, in the session timeline and in the audit trail, and the person at
 * the machine can end it without anyone's cooperation.
 */
class ControlService
{
    public const STATE_NONE      = 'NONE';
    public const STATE_REQUESTED = 'REQUESTED';
    public const STATE_GRANTED   = 'GRANTED';
    public const STATE_DENIED    = 'DENIED';
    public const STATE_REVOKED   = 'REVOKED';

    /** Why nobody can be controlled right now — see {@see blockedReason()}. */
    public const BLOCKED_NO_COMPANION        = 'NO_COMPANION';
    public const BLOCKED_COMPANION_NOT_READY = 'COMPANION_NOT_READY';
    public const BLOCKED_NOT_SHARING         = 'NOT_SHARING';
    public const BLOCKED_SURFACE_NOT_MONITOR = 'SURFACE_NOT_MONITOR';

    public function __construct(
        private readonly BaseConnection $db,
        private readonly ParticipantService $participants,
        private readonly AuditService $audit,
        private readonly CompanionLinks $companions,
    ) {
    }

    /**
     * A viewer asks the person at the machine for control.
     *
     * @param  array<string, mixed> $session
     * @return array<string, mixed> the requesting participant, updated
     */
    public function request(array $session, RemoteIdentity $identity, EffectivePolicy $policy): array
    {
        if (! SessionStatus::isLive((string) $session['status'])) {
            throw ApiException::conflict('SESSION_ALREADY_ENDED', 'This Remote session has already finished.');
        }

        if (! $policy->allowRemoteControl) {
            throw ApiException::forbidden(
                'REMOTE_CONTROL_NOT_ALLOWED',
                'Remote control is not enabled for this organisation.',
                ['restrictions' => $policy->restrictions],
            );
        }

        if (! $policy->can(PermissionCatalog::CONTROL_REQUEST)) {
            throw ApiException::forbidden(
                'CONTROL_REQUEST_DENIED',
                'You do not have permission to request control of a computer.',
                ['permission' => PermissionCatalog::CONTROL_REQUEST],
            );
        }

        $requester = $this->requireParticipant($session, $identity);

        if (! in_array((string) $requester['status'], ['APPROVED', 'JOINED'], true)) {
            throw ApiException::forbidden(
                'AWAITING_APPROVAL',
                'The host has not admitted you to this session yet.',
            );
        }

        // The whole feature rests on this: a participant whose client cannot be
        // controlled cannot be controlled. A browser host reports
        // `remote_control: false`, so asking for control of one is refused
        // here rather than producing a request nobody can ever answer.
        $host = $this->controllableHost($session);

        if ($host === null) {
            throw $this->notControllable($session);
        }

        // The person a companion serves is, for control, the machine itself.
        if ((int) $host['id'] === (int) $requester['id']
            || (int) ($host['companion_of_participant_id'] ?? 0) === (int) $requester['id']) {
            throw ApiException::badRequest(
                'CONTROL_SELF',
                'You are the computer being shared, so there is nothing to request.',
            );
        }

        if ((string) $requester['control_state'] === self::STATE_GRANTED) {
            return $requester;
        }

        $this->setState((int) $requester['id'], self::STATE_REQUESTED, [
            'control_requested_at' => Clock::now(),
            'control_granted_at'   => null,
            'control_revoked_at'   => null,
            'clipboard_enabled'    => false,
        ]);

        $updated = $this->participants->findByIdOrFail((int) $requester['id']);

        $this->audit->record(
            $session,
            EventType::CONTROL_REQUESTED,
            $identity->id,
            'USER',
            (int) $updated['id'],
            (string) $updated['uuid'],
            ['hostParticipantUuid' => (string) $host['uuid']],
        );

        return $updated;
    }

    /**
     * The person at the machine says yes.
     *
     * Only the host does this. Not a company administrator, not a support
     * technician, not the requester — for the same reason only the host admits
     * a viewer (§71). Consent for control belongs to whoever is sitting there.
     *
     * @param  array<string, mixed> $session
     * @return array<string, mixed>
     */
    public function grant(
        array $session,
        RemoteIdentity $host,
        string $requesterUuid,
        EffectivePolicy $policy,
        bool $allowClipboard = false,
    ): array {
        if (! SessionStatus::isLive((string) $session['status'])) {
            throw ApiException::conflict('SESSION_ALREADY_ENDED', 'This Remote session has already finished.');
        }

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
                'You do not have permission to hand over control of this computer.',
                ['permission' => PermissionCatalog::CONTROL_ACCEPT],
            );
        }

        $hostParticipant = $this->requireHost($session, $host);
        $requester       = $this->requireParticipantByUuid($session, $requesterUuid);

        if ((string) $requester['control_state'] !== self::STATE_REQUESTED) {
            throw ApiException::conflict(
                'CONTROL_NOT_REQUESTED',
                'That person is not waiting for control of this computer.',
                ['controlState' => $requester['control_state']],
            );
        }

        // Nothing to hand over is nothing to grant: a request outlives the machine
        // that could have answered it (the companion's link ended, the share
        // stopped, the agent left), and a grant written then would wait for the
        // next machine to join and arm it without anyone deciding again.
        $controllable = $this->controllableHost($session);
        if ($controllable === null) {
            throw $this->notControllable($session);
        }

        $viaCompanion = (string) ($controllable['participant_role'] ?? '') === ParticipantService::ROLE_COMPANION;

        // A control companion serves one person's screen. Only that person — the
        // participant the companion is linked to — may hand control of it over:
        // not the session's owner, not another host, not an administrator.
        if ($viaCompanion && (int) $controllable['companion_of_participant_id'] !== (int) $hostParticipant['id']) {
            throw ApiException::forbidden(
                'NOT_SESSION_HOST',
                'Only the person sharing this screen can hand over control of it.',
            );
        }

        // One controller at a time. Two people typing into the same desktop is
        // not a feature, and deciding which keystroke wins is not a decision
        // anybody should have to make afterwards.
        $existing = $this->currentController((int) $session['id']);
        if ($existing !== null && (int) $existing['id'] !== (int) $requester['id']) {
            throw ApiException::conflict(
                'CONTROL_ALREADY_GRANTED',
                'Someone else is already controlling this computer. Stop their control first.',
                ['controllerName' => (string) $existing['display_name']],
            );
        }

        return $this->applyGrant(
            $session,
            $requester,
            // Owner decision 3: no clipboard through a companion in v1, whatever the policy says.
            ! $viaCompanion && $allowClipboard && $policy->allowClipboardSync,
            $host->id,
            'USER',
            (string) $hostParticipant['uuid'],
            $viaCompanion ? ['companionParticipantUuid' => (string) $controllable['uuid']] : [],
        );
    }

    /**
     * Write the grant and record it, whoever decided.
     *
     * Shared by the browser host and by a desktop agent answering for its own
     * machine — the decision is the same one and must leave the same row and
     * the same audit entry behind, or the two paths would drift.
     *
     * @param  array<string, mixed> $session
     * @param  array<string, mixed> $requester
     * @return array<string, mixed>
     */
    private function applyGrant(
        array $session,
        array $requester,
        bool $clipboard,
        ?int $actorUserId,
        string $actorType,
        string $hostParticipantUuid,
        array $metadata = [],
    ): array {
        // Guarded on the state it was read in, so two Allow taps produce one
        // grant and one no-op rather than two audit entries.
        $this->db->table('remote_participants')
            ->where('id', $requester['id'])
            ->where('control_state', self::STATE_REQUESTED)
            ->update([
                'control_state'              => self::STATE_GRANTED,
                'control_granted_at'         => Clock::now(),
                'control_granted_by_user_id' => $actorUserId,
                'control_revoked_at'         => null,
                'clipboard_enabled'          => $clipboard,
                'updated_at'                 => Clock::now(),
            ]);

        if ($this->db->affectedRows() === 0) {
            return $this->participants->findByIdOrFail((int) $requester['id']);
        }

        $updated = $this->participants->findByIdOrFail((int) $requester['id']);

        $this->audit->record(
            $session,
            EventType::CONTROL_GRANTED,
            $actorUserId,
            $actorType,
            (int) $updated['id'],
            (string) $updated['uuid'],
            array_merge([
                'hostParticipantUuid' => $hostParticipantUuid,
                'clipboard'           => $clipboard,
            ], $metadata),
        );

        return $updated;
    }

    /**
     * Move a participant out of control, whoever decided and for whichever
     * of the two reasons.
     *
     * @param  array<string, mixed> $session
     * @param  array<string, mixed> $target
     * @return array<string, mixed>
     */
    private function applyEnd(
        array $session,
        array $target,
        string $state,
        string $eventType,
        ?int $actorUserId,
        string $actorType,
        array $metadata = [],
    ): array {
        $this->db->table('remote_participants')
            ->where('id', $target['id'])
            ->whereIn('control_state', [self::STATE_REQUESTED, self::STATE_GRANTED])
            ->update([
                'control_state'      => $state,
                'control_revoked_at' => Clock::now(),
                'clipboard_enabled'  => false,
                'updated_at'         => Clock::now(),
            ]);

        $updated = $this->participants->findByIdOrFail((int) $target['id']);

        $this->audit->record(
            $session,
            $eventType,
            $actorUserId,
            $actorType,
            (int) $updated['id'],
            (string) $updated['uuid'],
            $metadata,
        );

        return $updated;
    }

    /**
     * The person at the machine says no.
     *
     * @param  array<string, mixed> $session
     * @return array<string, mixed>
     */
    public function deny(array $session, RemoteIdentity $host, string $requesterUuid): array
    {
        $this->requireHost($session, $host);
        $requester = $this->requireParticipantByUuid($session, $requesterUuid);

        return $this->applyEnd(
            $session,
            $requester,
            self::STATE_DENIED,
            EventType::CONTROL_DENIED,
            $host->id,
            'USER',
        );
    }

    /**
     * Stop control. Either side, immediately, no permission required.
     *
     * "Immediately" is the property: the person at the machine presses Stop
     * control, this row changes, and the agent stops accepting input on the
     * data channel the moment it sees the revocation — it does not wait for
     * the browser to cooperate, and it does not wait for a poll.
     *
     * Requiring a permission to *stop* being controlled would be an obvious
     * mistake, so there is none.
     *
     * @param  array<string, mixed> $session
     * @return array<string, mixed>
     */
    public function revoke(array $session, RemoteIdentity $identity, ?string $participantUuid = null): array
    {
        $target = $participantUuid !== null
            ? $this->requireParticipantByUuid($session, $participantUuid)
            : $this->currentController((int) $session['id']);

        if ($target === null) {
            throw ApiException::conflict('CONTROL_NOT_ACTIVE', 'Nobody is controlling this computer.');
        }

        $self = $this->participants->findByUser((int) $session['id'], $identity->id);
        $isHost = $self !== null && $self['is_host'] === true;
        $isOwner = (int) $session['owner_user_id'] === $identity->id;
        $isController = $self !== null && (int) $self['id'] === (int) $target['id'];

        if (! $isHost && ! $isOwner && ! $isController) {
            throw ApiException::forbidden(
                'CONTROL_REVOKE_DENIED',
                'Only the person at the computer, or whoever is controlling it, can stop remote control.',
            );
        }

        return $this->applyEnd(
            $session,
            $target,
            self::STATE_REVOKED,
            EventType::CONTROL_REVOKED,
            $identity->id,
            'USER',
            ['by' => $isController && ! $isHost ? 'CONTROLLER' : 'HOST'],
        );
    }

    /**
     * The machine itself answering: the person at the keyboard pressed Allow,
     * Not now, or Stop control in the desktop agent.
     *
     * The agent's own gate has *already* taken effect by the time this is
     * called — it is local, it needs no network, and that is the property the
     * whole design rests on. This is how the server and the other participant
     * find out, so the browser can stop sending and the audit trail records
     * who decided.
     *
     * The consent belongs to the machine, so the checks are the machine's:
     *
     *   1. the session is live;
     *   2. the organisation permits remote control;
     *   3. the device's own owner holds `remote.control.accept` — a machine
     *      cannot consent more widely than the person it belongs to may;
     *   4. the participant answering *is* the session's controllable host, so
     *      one device cannot answer for another;
     *   5. the clipboard is a separate decision, and still bounded by policy.
     *
     * @param  array<string, mixed> $session
     * @param  array<string, mixed> $deviceParticipant the device's own row
     * @return array<string, mixed> the requester, updated
     */
    public function decideAsDevice(
        array $session,
        array $deviceParticipant,
        string $requesterUuid,
        string $decision,
        EffectivePolicy $ownerPolicy,
        bool $allowClipboard = false,
    ): array {
        if (! SessionStatus::isLive((string) $session['status'])) {
            throw ApiException::conflict('SESSION_ALREADY_ENDED', 'This Remote session has already finished.');
        }

        // A control companion never hands control over: that is the person at
        // the browser's decision, made in the browser (owner decision 2). It may
        // still say no and stop — ending control needs no permission, and a
        // machine that could not stop its own control is not one to trust.
        if ((string) ($deviceParticipant['participant_role'] ?? '') === ParticipantService::ROLE_COMPANION) {
            return $this->decideAsCompanion($session, $deviceParticipant, $requesterUuid, $decision);
        }

        // A device answering for a session whose controllable host is some
        // other participant is a device answering a question it was not asked.
        $host = $this->controllableHost($session);

        if ($host === null || (int) $host['id'] !== (int) $deviceParticipant['id']) {
            throw ApiException::forbidden(
                'NOT_SESSION_HOST',
                'Only the computer being shared can decide about remote control.',
            );
        }

        $requester = $this->requireParticipantByUuid($session, $requesterUuid);
        $ownerId   = $deviceParticipant['user_id'] !== null ? (int) $deviceParticipant['user_id'] : null;

        // Ending control needs no permission at all, in either direction. A
        // machine that could be stopped only by somebody holding a grant would
        // be a machine whose owner cannot stop it.
        if ($decision === 'DENY' || $decision === 'REVOKE') {
            return $this->applyEnd(
                $session,
                $requester,
                $decision === 'DENY' ? self::STATE_DENIED : self::STATE_REVOKED,
                $decision === 'DENY' ? EventType::CONTROL_DENIED : EventType::CONTROL_REVOKED,
                $ownerId,
                'DEVICE',
                ['by' => 'DEVICE', 'deviceParticipantUuid' => (string) $deviceParticipant['uuid']],
            );
        }

        if ($decision !== 'GRANT') {
            throw ApiException::badRequest(
                'VALIDATION_FAILED',
                'Some of the details sent were not valid.',
                ['fields' => ['decision' => 'This must be GRANT, DENY or REVOKE.']],
            );
        }

        if (! $ownerPolicy->allowRemoteControl) {
            throw ApiException::forbidden(
                'REMOTE_CONTROL_NOT_ALLOWED',
                'Remote control is not enabled for this organisation.',
                ['restrictions' => $ownerPolicy->restrictions],
            );
        }

        if (! $ownerPolicy->can(PermissionCatalog::CONTROL_ACCEPT)) {
            throw ApiException::forbidden(
                'CONTROL_ACCEPT_DENIED',
                'This computer is not permitted to hand over control.',
                ['permission' => PermissionCatalog::CONTROL_ACCEPT],
            );
        }

        if ((string) $requester['control_state'] !== self::STATE_REQUESTED) {
            throw ApiException::conflict(
                'CONTROL_NOT_REQUESTED',
                'That person is not waiting for control of this computer.',
                ['controlState' => $requester['control_state']],
            );
        }

        // One controller at a time, for the same reason as the browser path:
        // two people typing into one desktop is not a feature.
        $existing = $this->currentController((int) $session['id']);
        if ($existing !== null && (int) $existing['id'] !== (int) $requester['id']) {
            throw ApiException::conflict(
                'CONTROL_ALREADY_GRANTED',
                'Someone else is already controlling this computer. Stop their control first.',
                ['controllerName' => (string) $existing['display_name']],
            );
        }

        return $this->applyGrant(
            $session,
            $requester,
            $allowClipboard && $ownerPolicy->allowClipboardSync,
            $ownerId,
            'DEVICE',
            (string) $deviceParticipant['uuid'],
        );
    }

    /**
     * A control companion answering: Not now, or Stop control. Never Allow.
     *
     * @param  array<string, mixed> $session
     * @param  array<string, mixed> $companion
     * @return array<string, mixed>
     */
    private function decideAsCompanion(array $session, array $companion, string $requesterUuid, string $decision): array
    {
        if ($decision === 'GRANT') {
            throw ApiException::forbidden(
                'COMPANION_CANNOT_GRANT',
                'Control is handed over in the browser, by the person sharing this screen.',
            );
        }

        if ($decision !== 'DENY' && $decision !== 'REVOKE') {
            throw ApiException::badRequest(
                'VALIDATION_FAILED',
                'Some of the details sent were not valid.',
                ['fields' => ['decision' => 'This must be DENY or REVOKE.']],
            );
        }

        if (! in_array((string) $companion['status'], ['APPROVED', 'JOINED'], true)) {
            throw ApiException::forbidden(
                'NOT_SESSION_HOST',
                'Only the computer being shared can decide about remote control.',
            );
        }

        $requester = $this->requireParticipantByUuid($session, $requesterUuid);

        return $this->applyEnd(
            $session,
            $requester,
            $decision === 'DENY' ? self::STATE_DENIED : self::STATE_REVOKED,
            $decision === 'DENY' ? EventType::CONTROL_DENIED : EventType::CONTROL_REVOKED,
            $companion['user_id'] !== null ? (int) $companion['user_id'] : null,
            'DEVICE',
            ['by' => 'DEVICE', 'deviceParticipantUuid' => (string) $companion['uuid'], 'companion' => true],
        );
    }

    /**
     * Take control away because what it depended on changed — the shared surface, the share itself, the
     * person sharing, the companion's link. Nobody pressed Stop, so it is recorded as the system (or as
     * whoever ended the link) with the reason, and it applies to whoever holds control right now.
     *
     * @param  array<string, mixed> $session
     * @return array<string, mixed>|null the former controller, or null when nobody had control
     */
    public function revokeBecause(array $session, string $reason, ?int $actorUserId = null, string $actorType = 'SYSTEM'): ?array
    {
        $controller = $this->currentController((int) $session['id']);
        if ($controller === null) {
            return null;
        }

        return $this->applyEnd(
            $session,
            $controller,
            self::STATE_REVOKED,
            EventType::CONTROL_REVOKED,
            $actorUserId,
            $actorType,
            ['by' => $actorType, 'reason' => $reason],
        );
    }

    /**
     * Why there is nobody to control in this session right now, or null when there is.
     *
     * @param  array<string, mixed> $session
     * @return self::BLOCKED_*|null
     */
    public function blockedReason(array $session): ?string
    {
        if ($this->controllableHost($session) !== null) {
            return null;
        }

        $link = $this->companions->liveFor($session);

        return $link === null ? self::BLOCKED_NO_COMPANION : $this->companions->blockedReason($session, $link);
    }

    /** @param array<string, mixed> $session */
    private function notControllable(array $session): ApiException
    {
        $reason = $this->blockedReason($session);

        return ApiException::conflict(
            'HOST_NOT_CONTROLLABLE',
            match ($reason) {
                self::BLOCKED_NOT_SHARING         => 'This computer can be controlled only while its screen is being shared.',
                self::BLOCKED_SURFACE_NOT_MONITOR => 'This computer can be controlled only while an entire screen is being shared.',
                self::BLOCKED_COMPANION_NOT_READY => 'The AICOUNTLY Remote app on that computer is not ready yet.',
                default                           => 'The computer in this session cannot be controlled. The AICOUNTLY Remote app is needed at that end.',
            },
            ['controlBlockedReason' => $reason],
        );
    }

    /**
     * Turn clipboard synchronisation on or off within a granted control
     * session.
     *
     * Separate from the grant on purpose: control and clipboard are different
     * exposures, and starting to control a machine must not silently start
     * copying whatever is on its clipboard. The database enforces the
     * dependency (`clipboard_enabled` requires `control_state = 'GRANTED'`);
     * this enforces the policy.
     *
     * @param  array<string, mixed> $session
     * @return array<string, mixed>
     */
    public function setClipboard(
        array $session,
        RemoteIdentity $host,
        string $participantUuid,
        bool $enabled,
        EffectivePolicy $policy,
    ): array {
        $this->requireHost($session, $host);
        $participant = $this->requireParticipantByUuid($session, $participantUuid);

        if ($enabled && ! $policy->allowClipboardSync) {
            throw ApiException::forbidden(
                'CLIPBOARD_SYNC_NOT_ALLOWED',
                'Clipboard sharing is not enabled for this organisation.',
                ['restrictions' => $policy->restrictions],
            );
        }

        // Owner decision 3: a control companion carries input only, never the clipboard, in v1.
        $controllable = $enabled ? $this->controllableHost($session) : null;
        if ($controllable !== null && (string) $controllable['participant_role'] === ParticipantService::ROLE_COMPANION) {
            throw ApiException::forbidden(
                'CLIPBOARD_SYNC_NOT_ALLOWED',
                'Clipboard sharing is not available when control comes from the browser share.',
            );
        }

        if ($enabled && (string) $participant['control_state'] !== self::STATE_GRANTED) {
            throw ApiException::conflict(
                'CONTROL_NOT_ACTIVE',
                'Clipboard sharing needs an active remote control session.',
            );
        }

        $this->db->table('remote_participants')->where('id', $participant['id'])->update([
            'clipboard_enabled' => $enabled,
            'updated_at'        => Clock::now(),
        ]);

        $updated = $this->participants->findByIdOrFail((int) $participant['id']);

        // Recorded as a capability change, with no clipboard content anywhere
        // near it. `AuditService::scrub()` would drop a `body` key even if a
        // caller passed one; not passing one is the actual rule (§60).
        $this->audit->record(
            $session,
            EventType::CLIPBOARD_SYNCED,
            $host->id,
            'USER',
            (int) $updated['id'],
            (string) $updated['uuid'],
            ['enabled' => $enabled],
        );

        return $updated;
    }

    /**
     * Whoever currently holds control of this session, if anyone.
     *
     * @return array<string, mixed>|null
     */
    public function currentController(int $sessionId): ?array
    {
        $row = $this->db->table('remote_participants')
            ->where('session_id', $sessionId)
            ->where('control_state', self::STATE_GRANTED)
            ->whereIn('status', ['APPROVED', 'JOINED'])
            ->orderBy('control_granted_at', 'DESC')
            ->get()
            ->getRowArray();

        return $row === null ? null : $this->participants->castRow($row);
    }

    /**
     * The participant whose machine can be controlled, if there is one.
     *
     * Negotiated capabilities decide this, never `client_type`. That is the
     * rule the whole desktop story rests on (§51): the session grows control
     * because a participant reported it can be controlled, not because some
     * code branched on the string 'DESKTOP_AGENT'.
     *
     * @param  array<string, mixed> $session
     * @return array<string, mixed>|null
     */
    public function controllableHost(array $session): ?array
    {
        foreach ($this->participants->forSession((int) $session['id']) as $participant) {
            if (! in_array((string) $participant['status'], ['APPROVED', 'JOINED'], true)) {
                continue;
            }

            $capabilities = $participant['capabilities'] ?? '{}';
            if (is_string($capabilities)) {
                $capabilities = json_decode($capabilities, true) ?: [];
            }

            if (($capabilities['remote_control'] ?? false) !== true) {
                continue;
            }

            // A control companion is controllable only while everything it
            // depends on holds at once: its link is ACTIVE (it knows which
            // monitor the picture is), the person it serves is in the session and
            // sharing, and what they share is an entire screen. Input onto a
            // window or a tab would land on whatever is beside it on the monitor,
            // which the viewer cannot see.
            if ((string) $participant['participant_role'] === ParticipantService::ROLE_COMPANION) {
                $link = $this->companions->activeForCompanion((int) $participant['id']);

                if ($link === null || (int) $link['session_id'] !== (int) $session['id']
                    || ! $this->companions->isReady($session, $link)) {
                    continue;
                }
            }

            return $participant;
        }

        return null;
    }

    /**
     * Everything the session screen needs to render control state, in one shape.
     *
     * @param  array<string, mixed> $session
     * @return array<string, mixed>
     */
    public function stateFor(array $session): array
    {
        $host       = $this->controllableHost($session);
        $controller = $this->currentController((int) $session['id']);

        $pending = array_values(array_filter(
            $this->participants->forSession((int) $session['id']),
            static fn (array $row) => (string) $row['control_state'] === self::STATE_REQUESTED,
        ));

        $link = $this->companions->latestFor($session);

        return [
            'controllableHostUuid' => $host !== null ? (string) $host['uuid'] : null,
            'controllerUuid'       => $controller !== null ? (string) $controller['uuid'] : null,
            'controllerName'       => $controller !== null ? (string) $controller['display_name'] : null,
            'clipboardEnabled'     => $controller !== null && ($controller['clipboard_enabled'] ?? false) === true,
            'pendingRequests'      => array_map(static fn (array $row) => [
                'participantUuid' => (string) $row['uuid'],
                'displayName'     => (string) $row['display_name'],
                'requestedAt'     => \App\Domain\Support\Clock::iso($row['control_requested_at'] ?? null),
            ], $pending),
            // Control companions (docs/desktop/CONTROL_COMPANION.md): the session's most recent link, what the
            // browser reports it is sharing, and why nobody can be controlled when nobody can.
            'companion'            => $this->companions->present($link, $session),
            'sharedSurface'        => $this->companions->surfaceOf((int) $session['id']),
            'controlBlockedReason' => $host !== null ? null : $this->blockedReason($session),
            'grantId'              => self::grantId($controller),
        ];
    }

    /**
     * An opaque name for one particular grant: the controller and the moment control was granted to them.
     *
     * The desktop agent arms its gate for a grant, and after the person at the machine presses Stop it
     * stays disarmed until it sees a *different* grant — so a stale poll that still reads GRANTED cannot
     * re-arm what was just stopped.
     *
     * @param array<string, mixed>|null $controller
     */
    public static function grantId(?array $controller): ?string
    {
        if ($controller === null || ($controller['control_granted_at'] ?? null) === null) {
            return null;
        }

        $at = strtotime((string) $controller['control_granted_at']);

        return substr(hash('sha256', (string) $controller['uuid'] . '|' . (string) $at), 0, 32);
    }

    // ------------------------------------------------------------- internals

    /** @param array<string, mixed> $extra */
    private function setState(int $participantId, string $state, array $extra = []): void
    {
        $this->db->table('remote_participants')->where('id', $participantId)->update(array_merge($extra, [
            'control_state' => $state,
            'updated_at'    => Clock::now(),
        ]));
    }

    /**
     * @param  array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function requireParticipant(array $session, RemoteIdentity $identity): array
    {
        $participant = $this->participants->findByUser((int) $session['id'], $identity->id);

        if ($participant === null) {
            throw ApiException::forbidden('NOT_A_PARTICIPANT', 'You are not part of this Remote session.');
        }

        return $participant;
    }

    /**
     * @param  array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function requireParticipantByUuid(array $session, string $uuid): array
    {
        $participant = $this->participants->findByUuidOrFail($uuid);

        if ((int) $participant['session_id'] !== (int) $session['id']) {
            throw ApiException::notFound('That participant could not be found.');
        }

        return $participant;
    }

    /**
     * @param  array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function requireHost(array $session, RemoteIdentity $identity): array
    {
        $self = $this->participants->findByUser((int) $session['id'], $identity->id);

        if ($self !== null && $self['is_host'] === true) {
            return $self;
        }

        if ((int) $session['owner_user_id'] === $identity->id && $self !== null) {
            return $self;
        }

        throw ApiException::forbidden(
            'NOT_SESSION_HOST',
            'Only the person at the computer being shared can decide about remote control.',
        );
    }
}
