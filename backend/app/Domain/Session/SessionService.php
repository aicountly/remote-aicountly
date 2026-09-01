<?php

declare(strict_types=1);

namespace App\Domain\Session;

use App\Domain\Audit\AuditService;
use App\Domain\Audit\EventType;
use App\Domain\Auth\RemoteIdentity;
use App\Domain\Auth\SourceContext;
use App\Domain\Policy\EffectivePolicy;
use App\Domain\Policy\EffectivePolicyResolver;
use App\Domain\Policy\PermissionCatalog;
use App\Domain\Support\ApiException;
use App\Domain\Support\Clock;
use App\Domain\Support\Ids;
use App\Domain\Support\Presenter;
use CodeIgniter\Database\BaseConnection;
use Config\Remote as RemoteConfig;

/**
 * Session lifecycle (§21, §26).
 *
 * Everything that changes a session's state goes through here so that the
 * state machine, the policy snapshot and the audit trail cannot drift apart.
 * Controllers validate input and format output; they do not decide anything.
 */
class SessionService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly EffectivePolicyResolver $policies,
        private readonly AuditService $audit,
        private readonly RemoteConfig $config,
        private readonly ParticipantService $participants,
    ) {
    }

    /**
     * Create a session and its host participant, atomically.
     *
     * The policy in force at this moment is *snapshotted* onto the row. A
     * session that started when chat was permitted keeps chat for its lifetime;
     * an administrator turning chat off stops the next session, not this one.
     * Without the snapshot, a mid-session policy edit would silently change the
     * rules under two people who are already talking.
     *
     * @param  array{scopeType: string, companyId?: int|null, branchId?: int|null,
     *               financialYearId?: int|null, sessionType?: string,
     *               requestedShareMode?: string, allowAudio?: bool,
     *               supportTicketId?: string|null} $input
     * @return array<string, mixed> the created session row
     */
    public function create(
        RemoteIdentity $identity,
        array $input,
        EffectivePolicy $policy,
        ?SourceContext $context = null,
    ): array {
        $scopeType = $input['scopeType'];
        $companyId = $scopeType === 'PERSONAL' ? null : ($input['companyId'] ?? null);

        // §13 — a company-scoped workflow must never be escapable into a
        // personal session. When a verified context token names a company, that
        // company is the session's, and PERSONAL is refused outright rather
        // than quietly downgraded.
        if ($context !== null && $context->companyId !== null && $scopeType === 'PERSONAL') {
            throw ApiException::forbidden(
                'PERSONAL_SCOPE_NOT_ALLOWED',
                'This session was started from an organisation’s AICOUNTLY workspace, so it cannot be a personal session.',
            );
        }

        if (! $policy->remoteEnabled) {
            throw ApiException::forbidden(
                'COMPANY_REMOTE_DISABLED',
                'Remote assistance is turned off for this organisation.',
            );
        }

        if (! $policy->can(PermissionCatalog::SESSION_CREATE)) {
            throw ApiException::forbidden(
                'SESSION_CREATE_DENIED',
                'You do not have permission to start a Remote session.',
            );
        }

        $sessionType = $input['sessionType'] ?? 'ASSISTANCE';

        if ($sessionType === 'SUPPORT' && ! $policy->allowAicountlySupport) {
            throw ApiException::forbidden(
                'SUPPORT_SESSIONS_DISABLED',
                'This organisation has turned off AICOUNTLY Support sessions.',
            );
        }

        if ($sessionType === 'INTERNAL' && ! $policy->allowInternalSessions) {
            throw ApiException::forbidden(
                'INTERNAL_SESSIONS_DISABLED',
                'This organisation does not allow Remote sessions between its own users.',
            );
        }

        $shareMode = $input['requestedShareMode'] ?? 'SAFE_SHARE';
        if (! $policy->allowsShareMode($shareMode)) {
            throw ApiException::forbidden(
                'SHARE_MODE_NOT_ALLOWED',
                'That way of sharing is not available to you in this organisation.',
                ['shareMode' => $shareMode, 'allowed' => $policy->allowedShareModes()],
            );
        }

        $this->enforceMonthlySessionQuota($companyId);

        $durationMinutes = $policy->maxSessionDurationMinutes;

        $this->db->transException(true)->transStart();

        try {
            $uuid      = Ids::uuid4();
            $displayId = $this->nextDisplayId();
            $code      = $this->allocateJoinCode();

            $row = [
                'uuid'                   => $uuid,
                'display_id'             => $displayId,
                'session_code'           => $code,
                'session_code_expires_at' => $this->timestampIn($durationMinutes),
                'scope_type'             => $scopeType,
                'company_id'             => $companyId,
                'branch_id'              => $scopeType === 'PERSONAL' ? null : ($input['branchId'] ?? null),
                'financial_year_id'      => $scopeType === 'PERSONAL' ? null : ($input['financialYearId'] ?? null),
                'initiator_user_id'      => $identity->id,
                'owner_user_id'          => $identity->id,
                'source_product'         => $context?->product,
                'source_route'           => $context?->route,
                'source_reference'       => $context?->sourceReference,
                'source_agent'           => $context?->sourceAgent,
                'source_conversation_id' => $context?->sourceConversationId,
                'issue_summary'          => $input['issueSummary'] ?? $context?->issueSummary,
                'support_ticket_id'      => $input['supportTicketId'] ?? $context?->supportTicketId,
                'session_type'           => $sessionType,
                'status'                 => SessionStatus::WAITING,
                'requested_share_mode'   => $shareMode,
                // The session's own capability snapshot. Each is the policy
                // value AND what the creator actually asked for.
                'allow_audio'            => $policy->allowMicrophone && ($input['allowAudio'] ?? false),
                'allow_system_audio'     => $policy->allowSystemAudio && ($input['allowSystemAudio'] ?? false),
                'allow_chat'             => $policy->allowTextChat,
                'allow_annotation'       => $policy->allowAnnotation,
                'allow_file_transfer'    => $policy->allowFileTransfer,
                'allow_recording'        => $policy->allowRecording,
                'allow_external_guest'   => $policy->allowExternalGuest,
                'max_duration_minutes'   => $durationMinutes,
                'expires_at'             => $this->timestampIn($durationMinutes),
                'created_ip'             => $input['ip'] ?? null,
                'created_user_agent'     => $input['userAgent'] ?? null,
            ];

            $this->db->table('remote_sessions')->insert($row);
            $session = $this->findByUuidOrFail($uuid);

            // The creator is the host and, in V1, the sharer.
            $this->participants->createHost($session, $identity, $input['userAgent'] ?? null, $input['ip'] ?? null);

            $this->audit->record($session, EventType::SESSION_CREATED, $identity->id, 'USER', null, null, [
                'scopeType'   => $scopeType,
                'sessionType' => $sessionType,
                'shareMode'   => $shareMode,
                'sourceProduct' => $context?->product,
                'sourceRoute'   => $context?->route,
            ]);

            $this->db->transComplete();

            return $this->findByUuidOrFail($uuid);
        } catch (\Throwable $e) {
            // Every failure path rolls back, not only the ones we anticipated:
            // a database error here would otherwise leave a session row with no
            // host participant, which nothing downstream can make sense of.
            $this->db->transRollback();

            throw $e;
        }
    }

    /**
     * Load a session the caller is entitled to see, or 404.
     *
     * "Entitled" is deliberately narrow (§77, §78): being a member of the
     * company is not enough — you must be a participant, own the session, or
     * hold `remote.session.history.company` for that exact company. A caller
     * who fails every test gets 404, not 403, so session ids cannot be probed.
     *
     * @return array<string, mixed>
     */
    public function findForUser(string $uuid, RemoteIdentity $identity): array
    {
        if (! Ids::isUuid($uuid)) {
            throw ApiException::notFound('That Remote session could not be found.');
        }

        $session = $this->findByUuid($uuid);
        if ($session === null) {
            throw ApiException::notFound('That Remote session could not be found.');
        }

        if ($this->canAccess($session, $identity)) {
            return $session;
        }

        throw ApiException::notFound('That Remote session could not be found.');
    }

    /** @param array<string, mixed> $session */
    public function canAccess(array $session, RemoteIdentity $identity): bool
    {
        if ((int) $session['owner_user_id'] === $identity->id
            || (int) $session['initiator_user_id'] === $identity->id) {
            return true;
        }

        if ($this->participants->findByUser((int) $session['id'], $identity->id) !== null) {
            return true;
        }

        $companyId = $session['company_id'] !== null ? (int) $session['company_id'] : null;
        if ($companyId === null) {
            return false; // A personal session belongs to exactly one person.
        }

        // Company-wide visibility is a permission, resolved for that company —
        // never for whichever company the caller happens to be looking at.
        try {
            $policy = $this->policies->resolve($identity, 'COMPANY', $companyId);
        } catch (ApiException) {
            return false;
        }

        return $policy->can(PermissionCatalog::SESSION_HISTORY_COMPANY);
    }

    /** @return array<string, mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        $row = $this->db->table('remote_sessions')->where('uuid', $uuid)->get()->getRowArray();

        return $row === null ? null : $this->castRow($this->expireIfDue($row));
    }

    /** @return array<string, mixed> */
    public function findByUuidOrFail(string $uuid): array
    {
        $row = $this->findByUuid($uuid);
        if ($row === null) {
            throw ApiException::notFound('That Remote session could not be found.');
        }

        return $row;
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $row = $this->db->table('remote_sessions')->where('id', $id)->get()->getRowArray();

        return $row === null ? null : $this->castRow($this->expireIfDue($row));
    }

    /**
     * Normalise a session row's booleans, once, on the way out of the database.
     *
     * PostgreSQL hands booleans back as the strings `'t'` and `'f'`, and
     * `(bool) 'f'` is **true** in PHP. Casting at each call site is how a
     * session with chat switched off ends up allowing chat, so it is done here
     * instead and every consumer receives real booleans.
     *
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function castRow(array $row): array
    {
        foreach ([
            'allow_audio', 'allow_system_audio', 'allow_chat', 'allow_annotation',
            'allow_file_transfer', 'allow_recording', 'allow_external_guest',
        ] as $column) {
            if (array_key_exists($column, $row)) {
                $row[$column] = Presenter::bool($row[$column]);
            }
        }

        return $row;
    }

    /**
     * A session past `expires_at` is expired the moment anyone looks at it.
     *
     * Doing it on read rather than on a cron means an abandoned session cannot
     * sit joinable indefinitely on a host with no scheduler — which is the
     * normal cPanel case.
     *
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function expireIfDue(array $row): array
    {
        if (SessionStatus::isTerminal((string) $row['status'])) {
            return $row;
        }

        if (strtotime((string) $row['expires_at']) > time()) {
            return $row;
        }

        // whereNotIn, not a hand-written NOT IN: Postgres reads double quotes
        // as identifiers, so `status NOT IN ("ENDED")` asks for a column named
        // ENDED and errors instead of matching anything.
        $this->db->table('remote_sessions')
            ->where('id', $row['id'])
            ->whereNotIn('status', [
                SessionStatus::ENDED,
                SessionStatus::EXPIRED,
                SessionStatus::FAILED,
                SessionStatus::DECLINED,
            ])
            ->update([
                'status'       => SessionStatus::EXPIRED,
                'ended_at'     => Clock::now(),
                'end_reason'   => 'EXPIRED',
                'session_code' => null,
                'updated_at'   => Clock::now(),
            ]);

        $this->audit->recordEvent((int) $row['id'], EventType::SESSION_EXPIRED, null, 'SYSTEM');

        $row['status']       = SessionStatus::EXPIRED;
        $row['session_code'] = null;
        $row['end_reason']   = 'EXPIRED';

        return $row;
    }

    /**
     * Move a session to a new status, validating the transition first.
     *
     * @param  array<string, mixed> $session
     * @param  array<string, mixed> $extra   additional columns to write
     * @return array<string, mixed>
     */
    public function transition(array $session, string $to, array $extra = []): array
    {
        SessionStatus::assertTransition((string) $session['status'], $to);

        if ((string) $session['status'] === $to && $extra === []) {
            return $session;
        }

        $update = array_merge($extra, [
            'status'           => $to,
            'last_activity_at' => Clock::now(),
            'updated_at'       => Clock::now(),
        ]);

        // Guard the update with the status we validated against, so two
        // concurrent transitions cannot both apply (§59).
        $this->db->table('remote_sessions')
            ->where('id', $session['id'])
            ->where('status', $session['status'])
            ->update($update);

        if ($this->db->affectedRows() === 0) {
            $current = $this->findById((int) $session['id']);
            if ($current !== null && (string) $current['status'] === $to) {
                return $current; // Someone else already made the same move.
            }

            throw ApiException::conflict(
                'SESSION_STATE_CHANGED',
                'This session changed while you were working on it. Refresh to see where it is now.',
            );
        }

        return $this->findById((int) $session['id']) ?? $session;
    }

    /** @param array<string, mixed> $session */
    public function markActive(array $session): array
    {
        $extra = $session['started_at'] === null ? ['started_at' => Clock::now()] : [];

        return $this->transition($session, SessionStatus::ACTIVE, $extra);
    }

    /** @param array<string, mixed> $session */
    public function pause(array $session, RemoteIdentity $identity): array
    {
        $this->assertHost($session, $identity, 'Only the person who started this session can pause it.');
        $updated = $this->transition($session, SessionStatus::PAUSED);
        $this->audit->record($updated, EventType::SESSION_PAUSED, $identity->id);

        return $updated;
    }

    /** @param array<string, mixed> $session */
    public function resume(array $session, RemoteIdentity $identity): array
    {
        $this->assertHost($session, $identity, 'Only the person who started this session can resume it.');
        $updated = $this->transition($session, SessionStatus::ACTIVE);
        $this->audit->record($updated, EventType::SESSION_RESUMED, $identity->id);

        return $updated;
    }

    /**
     * End a session and release its join code.
     *
     * Any participant may end a session they are in — being unable to leave a
     * screen-sharing session you are in would be a worse failure than an
     * over-permissive one.
     *
     * @param array<string, mixed> $session
     */
    public function end(array $session, RemoteIdentity $identity, string $reason = 'ENDED_BY_USER'): array
    {
        if (SessionStatus::isTerminal((string) $session['status'])) {
            return $session; // Idempotent: ending an ended session is fine.
        }

        $isParticipant = $this->participants->findByUser((int) $session['id'], $identity->id) !== null;
        if (! $isParticipant && (int) $session['owner_user_id'] !== $identity->id) {
            throw ApiException::forbidden('SESSION_END_DENIED', 'You are not part of this Remote session.');
        }

        $updated = $this->transition($session, SessionStatus::ENDED, [
            'ended_at'         => Clock::now(),
            'ended_by_user_id' => $identity->id,
            'end_reason'       => $reason,
            // The code must stop working the instant the session does (§6E).
            'session_code'     => null,
        ]);

        $this->participants->closeAll((int) $session['id']);
        $this->db->table('remote_invitations')
            ->where('session_id', $session['id'])
            ->where('revoked_at', null)
            ->update(['revoked_at' => Clock::now(), 'updated_at' => Clock::now()]);

        $this->audit->record($updated, EventType::SESSION_ENDED, $identity->id, 'USER', null, null, [
            'reason'          => $reason,
            'durationSeconds' => $this->durationSeconds($updated),
        ]);

        return $updated;
    }

    /**
     * Record that sharing actually began, and enforce the surface the browser
     * reported against the policy that is in force (§16).
     *
     * The frontend stops the track locally the moment it sees a disallowed
     * surface; this is the server-side half, and it is the one that counts —
     * a client that skipped its own check still cannot get the session marked
     * as sharing.
     *
     * @param array<string, mixed> $session
     */
    public function recordShareStarted(
        array $session,
        RemoteIdentity $identity,
        string $displaySurface,
        EffectivePolicy $policy,
    ): array {
        $surface = in_array($displaySurface, ['browser', 'window', 'monitor'], true) ? $displaySurface : 'unknown';

        // 'unknown' means the browser did not report `displaySurface` at all.
        // Firefox and Safari are in that position today, so refusing outright
        // would make Remote unusable there. Instead the session falls back to
        // the mode the user asked for and which policy already authorised, and
        // the gap is recorded honestly rather than presented as verified.
        if ($surface !== 'unknown' && ! $policy->allowsDisplaySurface($surface)) {
            $this->audit->record($session, EventType::POLICY_REJECTED, $identity->id, 'USER', null, null, [
                'displaySurface' => $surface,
                'reason'         => 'SURFACE_NOT_PERMITTED',
            ]);

            throw ApiException::forbidden(
                'SURFACE_NOT_ALLOWED',
                $this->surfaceRefusalMessage($surface),
                ['displaySurface' => $surface, 'allowed' => $policy->allowedShareModes()],
            );
        }

        $updated = $this->transition($session, SessionStatus::ACTIVE, [
            'actual_display_surface' => $surface,
            'started_at'             => $session['started_at'] ?? Clock::now(),
        ]);

        $this->participants->setSharing((int) $session['id'], $identity->id, true);

        // The surface-specific event only exists when the browser actually told
        // us which surface it was. Emitting it for 'unknown' would add a second
        // SCREEN_SHARE_STARTED to the timeline saying nothing.
        if ($surface !== 'unknown') {
            $this->audit->record($updated, EventType::forSurface($surface), $identity->id, 'USER', null, null, [
                'displaySurface' => $surface,
            ]);
        }

        $this->audit->record($updated, EventType::SCREEN_SHARE_STARTED, $identity->id, 'USER', null, null, [
            'displaySurface' => $surface,
            'shareMode'      => $session['requested_share_mode'],
            // False when the browser does not implement `displaySurface`
            // (Firefox, Safari). The audit trail says so rather than implying
            // the surface was checked.
            'verified'       => $surface !== 'unknown',
        ]);

        return $updated;
    }

    /**
     * Sharing stopped — by the Stop Sharing button, or by the browser's own
     * sharing bar. The session stays open so chat can continue and the user can
     * share again (§86).
     *
     * @param array<string, mixed> $session
     */
    public function recordShareStopped(array $session, RemoteIdentity $identity, string $reason = 'USER_STOPPED'): array
    {
        $this->participants->setSharing((int) $session['id'], $identity->id, false);

        $update = ['actual_display_surface' => null];
        $updated = SessionStatus::isTerminal((string) $session['status'])
            ? $session
            : $this->transition($session, (string) $session['status'], $update);

        $this->audit->record($updated, EventType::SCREEN_SHARE_STOPPED, $identity->id, 'USER', null, null, [
            'reason' => $reason,
        ]);

        return $updated;
    }

    /**
     * A cooperating AICOUNTLY tab reported a company that is not this session's
     * (§12). Sharing is stopped, the session is paused, and the mismatch is
     * recorded — a viewer must never be shown another tenant's screen because
     * the user switched company in a different tab.
     *
     * @param array<string, mixed> $session
     */
    public function recordContextMismatch(
        array $session,
        RemoteIdentity $identity,
        ?int $observedCompanyId,
        ?string $observedProduct,
    ): array {
        $this->participants->setSharing((int) $session['id'], $identity->id, false);

        $updated = SessionStatus::isTerminal((string) $session['status'])
            ? $session
            : $this->transition($session, SessionStatus::PAUSED, ['actual_display_surface' => null]);

        $this->audit->record($updated, EventType::COMPANY_CONTEXT_MISMATCH, $identity->id, 'USER', null, null, [
            'sessionCompanyId'  => $session['company_id'],
            'observedCompanyId' => $observedCompanyId,
            'observedProduct'   => $observedProduct,
        ]);

        return $updated;
    }

    /**
     * Sessions the caller may see, newest first (§42).
     *
     * @param  array{scopeType?: string|null, companyId?: int|null, status?: string|null,
     *               sourceProduct?: string|null, from?: string|null, to?: string|null,
     *               sessionType?: string|null, limit?: int, offset?: int} $filters
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function history(RemoteIdentity $identity, array $filters): array
    {
        $limit  = min(max((int) ($filters['limit'] ?? 25), 1), 100);
        $offset = max((int) ($filters['offset'] ?? 0), 0);

        // Which companies may this person see organisation-wide history for?
        // Resolved per company, so holding the permission in one company grants
        // nothing in another (§77).
        $companyWide = [];
        foreach ($this->companiesFor($identity->id) as $companyId) {
            try {
                if ($this->policies->resolve($identity, 'COMPANY', $companyId)->can(PermissionCatalog::SESSION_HISTORY_COMPANY)) {
                    $companyWide[] = $companyId;
                }
            } catch (ApiException) {
                // Membership disappeared between the two reads; skip it.
            }
        }

        $builder = $this->db->table('remote_sessions s')
            ->select('s.*, d.name AS company_name, i.display_name AS owner_name')
            ->join('remote_company_directory d', 'd.company_id = s.company_id', 'left')
            ->join('remote_identities i', 'i.id = s.owner_user_id', 'left');

        $builder->groupStart()
            ->where('s.owner_user_id', $identity->id)
            ->orWhere('s.initiator_user_id', $identity->id)
            ->orWhere('s.id IN (SELECT session_id FROM remote_participants WHERE user_id = ' . (int) $identity->id . ')', null, false);

        if ($companyWide !== []) {
            $builder->orWhereIn('s.company_id', $companyWide);
        }
        $builder->groupEnd();

        if (! empty($filters['scopeType'])) {
            $builder->where('s.scope_type', $filters['scopeType']);
        }
        if (! empty($filters['companyId'])) {
            $builder->where('s.company_id', (int) $filters['companyId']);
        }
        if (! empty($filters['status'])) {
            $builder->where('s.status', $filters['status']);
        }
        if (! empty($filters['sessionType'])) {
            $builder->where('s.session_type', $filters['sessionType']);
        }
        if (! empty($filters['sourceProduct'])) {
            $builder->where('s.source_product', strtoupper((string) $filters['sourceProduct']));
        }
        if (! empty($filters['from'])) {
            $builder->where('s.created_at >=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $builder->where('s.created_at <=', $filters['to']);
        }

        $total = (clone $builder)->countAllResults(false);

        $items = $builder->orderBy('s.created_at', 'DESC')->limit($limit, $offset)->get()->getResultArray();

        return ['items' => $items, 'total' => $total];
    }

    /** @return list<array<string, mixed>> */
    public function events(int $sessionId, int $limit = 200): array
    {
        return $this->db->table('remote_session_events e')
            ->select('e.event_type, e.actor_type, e.metadata, e.occurred_at, i.display_name AS actor_name')
            ->join('remote_identities i', 'i.id = e.actor_user_id', 'left')
            ->where('e.session_id', $sessionId)
            ->orderBy('e.occurred_at', 'ASC')
            ->limit($limit)
            ->get()
            ->getResultArray();
    }

    /**
     * Counters for the dashboard (§68). One query per number, all of them
     * scoped to what this person may actually see.
     *
     * @param  list<int> $companyWideIds
     * @return array<string, int|float|null>
     */
    public function dashboardMetrics(RemoteIdentity $identity, array $companyWideIds): array
    {
        $visible = function () use ($identity, $companyWideIds) {
            $builder = $this->db->table('remote_sessions s');
            $builder->groupStart()
                ->where('s.owner_user_id', $identity->id)
                ->orWhere('s.id IN (SELECT session_id FROM remote_participants WHERE user_id = ' . (int) $identity->id . ')', null, false);
            if ($companyWideIds !== []) {
                $builder->orWhereIn('s.company_id', $companyWideIds);
            }

            return $builder->groupEnd();
        };

        $active = $visible()
            ->whereIn('s.status', [SessionStatus::WAITING, SessionStatus::JOIN_REQUESTED, SessionStatus::CONNECTING, SessionStatus::ACTIVE, SessionStatus::PAUSED, SessionStatus::RECONNECTING])
            ->countAllResults();

        $thisMonth = $visible()
            ->where('s.created_at >=', gmdate('Y-m-01 00:00:00') . '+00')
            ->countAllResults();

        $avgRow = $visible()
            ->select('AVG(EXTRACT(EPOCH FROM (s.ended_at - s.started_at))) AS avg_seconds', false)
            ->where('s.started_at IS NOT NULL', null, false)
            ->where('s.ended_at IS NOT NULL', null, false)
            ->where('s.created_at >=', gmdate('Y-m-01 00:00:00') . '+00')
            ->get()
            ->getRowArray();

        return [
            'activeSessions'        => $active,
            'sessionsThisMonth'     => $thisMonth,
            'averageDurationSeconds' => $avgRow !== null && $avgRow['avg_seconds'] !== null ? (int) round((float) $avgRow['avg_seconds']) : null,
        ];
    }

    /** @return list<int> */
    public function companiesFor(int $userId): array
    {
        $rows = $this->db->table('remote_user_company_access')
            ->select('company_id')
            ->where('user_id', $userId)
            ->get()
            ->getResultArray();

        return array_map(static fn (array $r) => (int) $r['company_id'], $rows);
    }

    /** @param array<string, mixed> $session */
    public function durationSeconds(array $session): ?int
    {
        if ($session['started_at'] === null || $session['ended_at'] === null) {
            return null;
        }

        return max(0, strtotime((string) $session['ended_at']) - strtotime((string) $session['started_at']));
    }

    /** @param array<string, mixed> $session */
    private function assertHost(array $session, RemoteIdentity $identity, string $message): void
    {
        if ((int) $session['owner_user_id'] !== $identity->id) {
            throw ApiException::forbidden('SESSION_NOT_HOST', $message);
        }
    }

    /**
     * `AR-10282`. A sequence, not a random value, because it is a label people
     * read to each other — and it grants nothing on its own.
     */
    private function nextDisplayId(): string
    {
        $row = $this->db->query("SELECT nextval('remote_session_display_seq') AS n")->getRowArray();

        return 'AR-' . (string) ($row['n'] ?? random_int(10000, 99999));
    }

    /**
     * A join code nobody else is using.
     *
     * The retry loop exists because the code space is shared with every live
     * session; a collision is rare but must not become an error the user sees.
     * After the retries, the session is created without a code and can still be
     * joined by link.
     */
    private function allocateJoinCode(): ?string
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $code = Ids::joinCode($this->config->joinCodeLength);

            $taken = $this->db->table('remote_sessions')
                ->where('session_code', $code)
                ->countAllResults();

            if ($taken === 0) {
                return $code;
            }
        }

        log_message('error', 'Remote: could not allocate a unique join code after 8 attempts');

        return null;
    }

    /**
     * Refuse a new session once the plan's monthly allowance is spent (§79).
     * A null allowance means unlimited, which is the default.
     */
    private function enforceMonthlySessionQuota(?int $companyId): void
    {
        $entitlement = $this->policies->entitlement($companyId);
        $allowance   = $entitlement['max_monthly_sessions'];

        if ($allowance === null) {
            return;
        }

        $builder = $this->db->table('remote_sessions')->where('created_at >=', gmdate('Y-m-01 00:00:00') . '+00');
        $companyId === null ? $builder->where('company_id', null) : $builder->where('company_id', $companyId);

        if ($builder->countAllResults() >= (int) $allowance) {
            throw ApiException::forbidden(
                'SESSION_QUOTA_REACHED',
                'This organisation has used all its Remote sessions for the month.',
                ['plan' => $entitlement['plan_code'], 'allowance' => (int) $allowance],
            );
        }
    }

    private function surfaceRefusalMessage(string $surface): string
    {
        return match ($surface) {
            'monitor' => 'Entire-screen sharing is not permitted for your organisation.',
            'window'  => 'Application-window sharing is not permitted for your organisation.',
            default   => 'That way of sharing is not permitted for your organisation.',
        };
    }

    private function timestampIn(int $minutes): string
    {
        return Clock::inMinutes($minutes);
    }
}
