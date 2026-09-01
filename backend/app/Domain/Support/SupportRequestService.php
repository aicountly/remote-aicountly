<?php

declare(strict_types=1);

namespace App\Domain\Support;

use App\Domain\Audit\AuditService;
use App\Domain\Audit\EventType;
use App\Domain\Auth\RemoteIdentity;
use App\Domain\Auth\SourceContext;
use App\Domain\Policy\EffectivePolicy;
use App\Domain\Policy\EffectivePolicyResolver;
use App\Domain\Policy\PermissionCatalog;
use App\Domain\Session\ParticipantService;
use App\Domain\Session\SessionService;
use App\Domain\Session\SessionStatus;
use CodeIgniter\Database\BaseConnection;
use Config\Remote as RemoteConfig;

/**
 * The AICOUNTLY Support queue (§24).
 *
 * A request carries the context the customer was already in — product, area,
 * company, ticket — so the technician who takes it does not have to ask. None
 * of those fields is required, and none of them ties Remote to a particular
 * helpdesk: `support_ticket_id` is an opaque string that Pulse, Advisor or a
 * future ticketing system can each fill in their own way.
 */
class SupportRequestService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly SessionService $sessions,
        private readonly ParticipantService $participants,
        private readonly EffectivePolicyResolver $policies,
        private readonly AuditService $audit,
        private readonly RemoteConfig $config,
    ) {
    }

    /**
     * A customer asks for help.
     *
     * The session is created straight away and the request points at it, so the
     * customer can be shown their session code and start choosing what to share
     * while the queue is still being watched.
     *
     * @param  array<string, mixed> $input
     * @return array{request: array<string, mixed>, session: array<string, mixed>}
     */
    public function create(
        RemoteIdentity $identity,
        array $input,
        EffectivePolicy $policy,
        ?SourceContext $context,
    ): array {
        if (! $policy->allowAicountlySupport) {
            throw ApiException::forbidden(
                'SUPPORT_SESSIONS_DISABLED',
                'This organisation has turned off AICOUNTLY Support sessions.',
            );
        }

        if (! $policy->can(PermissionCatalog::SUPPORT_REQUEST)) {
            throw ApiException::forbidden(
                'SUPPORT_REQUEST_DENIED',
                'You do not have permission to request AICOUNTLY Support.',
            );
        }

        $companyId = $policy->companyId;
        $scopeType = $companyId === null ? 'AICOUNTLY_SUPPORT' : 'AICOUNTLY_SUPPORT';

        $this->db->transException(true)->transStart();

        try {
            $session = $this->sessions->create(
                $identity,
                [
                    'scopeType'          => $scopeType,
                    'companyId'          => $companyId,
                    'branchId'           => $input['branchId'] ?? $context?->branchId,
                    'financialYearId'    => $input['financialYearId'] ?? $context?->financialYearId,
                    'sessionType'        => 'SUPPORT',
                    'requestedShareMode' => $input['requestedShareMode'] ?? 'SAFE_SHARE',
                    'allowAudio'         => (bool) ($input['allowAudio'] ?? false),
                    'supportTicketId'    => $input['supportTicketId'] ?? $context?->supportTicketId,
                    'issueSummary'       => $input['issueSummary'] ?? $context?->issueSummary,
                    'ip'                 => $input['ip'] ?? null,
                    'userAgent'          => $input['userAgent'] ?? null,
                ],
                $policy,
                $context,
            );

            $uuid = Ids::uuid4();

            $this->db->table('remote_support_requests')->insert([
                'uuid'                   => $uuid,
                'session_id'             => $session['id'],
                'scope_type'             => $scopeType,
                'company_id'             => $companyId,
                'branch_id'              => $session['branch_id'],
                'financial_year_id'      => $session['financial_year_id'],
                'requester_user_id'      => $identity->id,
                'requester_name'         => $identity->displayName,
                'source_product'         => $session['source_product'],
                'source_route'           => $session['source_route'],
                'source_reference'       => $session['source_reference'],
                'source_agent'           => $session['source_agent'],
                'source_conversation_id' => $session['source_conversation_id'],
                'support_ticket_id'      => $session['support_ticket_id'],
                'issue_summary'          => $session['issue_summary'],
                'requested_share_mode'   => $session['requested_share_mode'],
                'priority'               => $this->normalisePriority($input['priority'] ?? 'NORMAL'),
                'status'                 => 'PENDING',
                'expires_at'             => Clock::inMinutes($this->config->supportRequestExpiryMinutes),
            ]);

            $this->audit->record($session, EventType::SUPPORT_REQUESTED, $identity->id, 'USER', null, null, [
                'requestUuid'   => $uuid,
                'sourceProduct' => $session['source_product'],
                'sourceRoute'   => $session['source_route'],
                'ticketId'      => $session['support_ticket_id'],
            ]);

            $this->db->transComplete();

            return [
                'request' => $this->findByUuidOrFail($uuid),
                'session' => $this->sessions->findByUuidOrFail((string) $session['uuid']),
            ];
        } catch (\Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }
    }

    /**
     * A technician takes a request.
     *
     * Two technicians clicking Accept at the same moment is the normal case,
     * not the edge case (§59). The guarded UPDATE means exactly one of them
     * wins; the other is told, plainly, that someone else already took it —
     * rather than both being dropped into the same session believing they own
     * it.
     *
     * @return array{request: array<string, mixed>, session: array<string, mixed>}
     */
    public function accept(string $requestUuid, RemoteIdentity $identity): array
    {
        $this->assertMayHandleSupport($identity);

        $request = $this->findByUuidOrFail($requestUuid);

        if ((string) $request['status'] !== 'PENDING') {
            throw ApiException::conflict(
                'SUPPORT_REQUEST_TAKEN',
                'Another AICOUNTLY technician has already taken this request.',
            );
        }

        if (Clock::hasPassed($request['expires_at'])) {
            $this->expire((int) $request['id']);

            throw ApiException::conflict('SUPPORT_REQUEST_EXPIRED', 'This support request has expired.');
        }

        $this->db->table('remote_support_requests')
            ->where('id', $request['id'])
            ->where('status', 'PENDING')
            ->update([
                'status'              => 'ACCEPTED',
                'accepted_by_user_id' => $identity->id,
                'accepted_at'         => Clock::now(),
                'updated_at'          => Clock::now(),
            ]);

        if ($this->db->affectedRows() === 0) {
            throw ApiException::conflict(
                'SUPPORT_REQUEST_TAKEN',
                'Another AICOUNTLY technician has already taken this request.',
            );
        }

        $request = $this->findByUuidOrFail($requestUuid);
        $session = $this->sessions->findById((int) $request['session_id']);

        if ($session === null) {
            throw ApiException::conflict('SESSION_ALREADY_ENDED', 'The customer’s session has already finished.');
        }

        // The technician joins as a participant like anyone else — and still
        // waits for the customer to approve them (§71). Accepting a request
        // does not grant sight of a screen.
        $this->participants->requestJoin(
            $session,
            $identity,
            $identity->displayName,
            ParticipantService::ROLE_SUPPORT,
            null,
            $identity->email,
        );

        if (in_array((string) $session['status'], [SessionStatus::CREATED, SessionStatus::WAITING], true)) {
            $session = $this->sessions->transition($session, SessionStatus::JOIN_REQUESTED);
        }

        $this->audit->record($session, EventType::SUPPORT_ACCEPTED, $identity->id, 'SUPPORT', null, null, [
            'requestUuid' => $requestUuid,
        ]);

        return ['request' => $request, 'session' => $session];
    }

    public function decline(string $requestUuid, RemoteIdentity $identity, ?string $reason = null): array
    {
        $this->assertMayHandleSupport($identity);

        $request = $this->findByUuidOrFail($requestUuid);

        $this->db->table('remote_support_requests')
            ->where('id', $request['id'])
            ->where('status', 'PENDING')
            ->update([
                'status'     => 'DECLINED',
                'closed_at'  => Clock::now(),
                'updated_at' => Clock::now(),
            ]);

        if ($this->db->affectedRows() === 0) {
            throw ApiException::conflict('SUPPORT_REQUEST_TAKEN', 'This request is no longer waiting.');
        }

        $session = $this->sessions->findById((int) $request['session_id']);
        if ($session !== null) {
            $this->audit->record($session, EventType::SUPPORT_DECLINED, $identity->id, 'SUPPORT', null, null, [
                'requestUuid' => $requestUuid,
                'reason'      => $reason,
            ]);
        }

        return $this->findByUuidOrFail($requestUuid);
    }

    /** The customer changes their mind before anyone picks it up. */
    public function cancel(string $requestUuid, RemoteIdentity $identity): array
    {
        $request = $this->findByUuidOrFail($requestUuid);

        if ((int) $request['requester_user_id'] !== $identity->id) {
            throw ApiException::forbidden('SUPPORT_CANCEL_DENIED', 'Only the person who asked for help can cancel this request.');
        }

        $this->db->table('remote_support_requests')
            ->where('id', $request['id'])
            ->whereIn('status', ['PENDING', 'ACCEPTED'])
            ->update([
                'status'     => 'CANCELLED',
                'closed_at'  => Clock::now(),
                'updated_at' => Clock::now(),
            ]);

        $session = $this->sessions->findById((int) $request['session_id']);
        if ($session !== null && SessionStatus::isLive((string) $session['status'])) {
            $this->sessions->end($session, $identity, 'SUPPORT_CANCELLED');
        }

        return $this->findByUuidOrFail($requestUuid);
    }

    /**
     * The technician's queue, or the customer's own requests.
     *
     * A technician sees every pending request across AICOUNTLY, which is what
     * the role is for. Everyone else sees only their own — including a company
     * administrator, because a support conversation is between the person who
     * asked and AICOUNTLY.
     *
     * @param  array{status?: string|null, mine?: bool, limit?: int, offset?: int} $filters
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function queue(RemoteIdentity $identity, array $filters): array
    {
        $limit  = min(max((int) ($filters['limit'] ?? 25), 1), 100);
        $offset = max((int) ($filters['offset'] ?? 0), 0);

        $builder = $this->db->table('remote_support_requests r')
            ->select('r.*, s.uuid AS session_uuid, s.display_id AS session_display_id, s.status AS session_status, d.name AS company_name')
            ->join('remote_sessions s', 's.id = r.session_id', 'left')
            ->join('remote_company_directory d', 'd.company_id = r.company_id', 'left');

        if ($this->canHandleSupport($identity) && ! ($filters['mine'] ?? false)) {
            // Nothing further: the technician queue is intentionally global.
        } else {
            $builder->groupStart()
                ->where('r.requester_user_id', $identity->id)
                ->orWhere('r.accepted_by_user_id', $identity->id)
                ->groupEnd();
        }

        if (! empty($filters['status'])) {
            $builder->where('r.status', strtoupper((string) $filters['status']));
        }

        $total = (clone $builder)->countAllResults(false);
        $items = $builder->orderBy('r.created_at', 'DESC')->limit($limit, $offset)->get()->getResultArray();

        // Expiring on read keeps a stale request out of the queue without a
        // scheduler, the same way sessions expire (§21).
        foreach ($items as $index => $item) {
            if ((string) $item['status'] === 'PENDING' && Clock::hasPassed($item['expires_at'])) {
                $this->expire((int) $item['id']);
                $items[$index]['status'] = 'EXPIRED';
            }
        }

        return ['items' => $items, 'total' => $total];
    }

    public function pendingCount(RemoteIdentity $identity): int
    {
        $builder = $this->db->table('remote_support_requests')
            ->where('status', 'PENDING')
            ->where('expires_at >', Clock::now());

        if (! $this->canHandleSupport($identity)) {
            $builder->where('requester_user_id', $identity->id);
        }

        return $builder->countAllResults();
    }

    public function canHandleSupport(RemoteIdentity $identity): bool
    {
        if ($identity->isSupportAgent || in_array($identity->id, $this->config->supportTechnicianUserIds, true)) {
            return true;
        }

        return $this->policies->resolve($identity, 'PERSONAL', null)->can(PermissionCatalog::SUPPORT_ACCEPT);
    }

    private function assertMayHandleSupport(RemoteIdentity $identity): void
    {
        if (! $this->canHandleSupport($identity)) {
            throw ApiException::forbidden(
                'SUPPORT_ACCEPT_DENIED',
                'You do not have permission to take AICOUNTLY Support requests.',
            );
        }
    }

    private function expire(int $id): void
    {
        $this->db->table('remote_support_requests')
            ->where('id', $id)
            ->where('status', 'PENDING')
            ->update(['status' => 'EXPIRED', 'closed_at' => Clock::now(), 'updated_at' => Clock::now()]);
    }

    /** @return array<string, mixed> */
    public function findByUuidOrFail(string $uuid): array
    {
        if (! Ids::isUuid($uuid)) {
            throw ApiException::notFound('That support request could not be found.');
        }

        $row = $this->db->table('remote_support_requests r')
            ->select('r.*, s.uuid AS session_uuid, s.display_id AS session_display_id, s.status AS session_status, d.name AS company_name')
            ->join('remote_sessions s', 's.id = r.session_id', 'left')
            ->join('remote_company_directory d', 'd.company_id = r.company_id', 'left')
            ->where('r.uuid', $uuid)
            ->get()
            ->getRowArray();

        if ($row === null) {
            throw ApiException::notFound('That support request could not be found.');
        }

        return $row;
    }

    private function normalisePriority(string $priority): string
    {
        $priority = strtoupper($priority);

        return in_array($priority, ['LOW', 'NORMAL', 'HIGH', 'URGENT'], true) ? $priority : 'NORMAL';
    }
}
