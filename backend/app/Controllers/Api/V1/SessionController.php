<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Domain\Audit\EventType;
use App\Domain\Policy\PermissionCatalog;
use App\Domain\Support\ApiException;
use App\Domain\Support\Presenter;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * Sessions: create, read, share, pause, end, history (§28).
 *
 * Every method resolves the policy for the session's *own* scope before acting.
 * That is what stops a user who may share their entire monitor at one company
 * from doing it in a session belonging to another (§77).
 */
class SessionController extends BaseApiController
{
    /** `POST /sessions` */
    public function create(): ResponseInterface
    {
        $body     = $this->body();
        $identity = $this->identity();
        $context  = $this->context()->sourceContext();

        $scopeType = $this->enum($body, 'scopeType', ['PERSONAL', 'COMPANY', 'AICOUNTLY_SUPPORT'], 'PERSONAL');

        // A verified launch context outranks whatever the body asked for: the
        // company comes from the signature, never from the client (§6C, §12).
        $companyId = $context?->companyId ?? $this->optionalInt($body, 'companyId');
        if ($scopeType === 'PERSONAL') {
            $companyId = null;
        }

        $policy = $this->policyFor($scopeType, $companyId);

        $session = Services::sessionService()->create($identity, [
            'scopeType'          => $scopeType,
            'companyId'          => $companyId,
            'branchId'           => $context?->branchId ?? $this->optionalInt($body, 'branchId'),
            'financialYearId'    => $context?->financialYearId ?? $this->optionalInt($body, 'financialYearId'),
            'sessionType'        => $this->enum($body, 'sessionType', ['ASSISTANCE', 'SUPPORT', 'INTERNAL', 'GUEST_VIEW'], 'ASSISTANCE'),
            'requestedShareMode' => $this->enum($body, 'requestedShareMode', ['SAFE_SHARE', 'BROWSER_TAB', 'APPLICATION_WINDOW', 'ENTIRE_MONITOR'], 'SAFE_SHARE'),
            'allowAudio'         => $this->boolean($body, 'allowAudio'),
            'allowSystemAudio'   => $this->boolean($body, 'allowSystemAudio'),
            'issueSummary'       => $this->optionalString($body, 'issueSummary', 2000),
            'supportTicketId'    => $this->optionalString($body, 'supportTicketId', 64),
            'ip'                 => $this->clientIp(),
            'userAgent'          => $this->userAgent(),
        ], $policy, $context);

        return $this->created($this->detail($session));
    }

    /** `GET /sessions/{uuid}` */
    public function show(string $uuid): ResponseInterface
    {
        $session = $this->resolveSession($uuid);

        return $this->ok($this->detail($session));
    }

    /**
     * `POST /sessions/{uuid}/share-intent`
     *
     * Called *before* the browser picker opens. The server authorises the
     * intent; only then does the frontend call `getDisplayMedia`. Doing it in
     * this order means a user is never shown an operating-system dialog for
     * something their organisation was going to refuse anyway (§16, §30).
     */
    public function shareIntent(string $uuid): ResponseInterface
    {
        $session  = $this->resolveSession($uuid);
        $identity = $this->identity();
        $policy   = $this->policyForSession($session);

        $shareMode = $this->enum(
            $this->body(),
            'shareMode',
            ['SAFE_SHARE', 'BROWSER_TAB', 'APPLICATION_WINDOW', 'ENTIRE_MONITOR'],
            (string) $session['requested_share_mode'],
        );

        if (! $policy->can(PermissionCatalog::SCREEN_SHARE) || ! $policy->allowsShareMode($shareMode)) {
            Services::auditService()->record($session, EventType::POLICY_REJECTED, $identity->id, 'USER', null, null, [
                'shareMode' => $shareMode,
                'reason'    => 'SHARE_MODE_NOT_ALLOWED',
            ]);

            throw ApiException::forbidden(
                'SHARE_MODE_NOT_ALLOWED',
                'That way of sharing is not available to you in this organisation.',
                ['shareMode' => $shareMode, 'allowed' => $policy->allowedShareModes()],
            );
        }

        Services::sessionService()->transition($session, (string) $session['status'], [
            'requested_share_mode' => $shareMode,
        ]);

        Services::auditService()->record($session, EventType::SCREEN_SHARE_REQUESTED, $identity->id, 'USER', null, null, [
            'shareMode' => $shareMode,
        ]);

        return $this->ok([
            'approved'  => true,
            'shareMode' => $shareMode,
            'allowedShareModes' => $policy->allowedShareModes(),
            'allowSystemAudio'  => $policy->allowSystemAudio && Presenter::bool($session['allow_system_audio']),
        ]);
    }

    /**
     * `POST /sessions/{uuid}/share-started`
     *
     * The browser reports which surface the user actually picked. This is the
     * server-side half of surface enforcement, and the half that counts: a
     * client that skipped its own check still cannot get the session marked as
     * sharing a monitor its organisation forbids (§16).
     */
    public function shareStarted(string $uuid): ResponseInterface
    {
        $session = $this->resolveSession($uuid);
        $body    = $this->body();

        $surface = strtolower((string) ($body['displaySurface'] ?? 'unknown'));

        $updated = Services::sessionService()->recordShareStarted(
            $session,
            $this->identity(),
            $surface,
            $this->policyForSession($session),
        );

        return $this->ok($this->detail($updated));
    }

    /** `POST /sessions/{uuid}/share-stopped` */
    public function shareStopped(string $uuid): ResponseInterface
    {
        $session = $this->resolveSession($uuid);

        $reason = $this->optionalString($this->body(), 'reason', 40) ?? 'USER_STOPPED';

        $updated = Services::sessionService()->recordShareStopped($session, $this->identity(), $reason);

        return $this->ok($this->detail($updated));
    }

    /**
     * `POST /sessions/{uuid}/context-mismatch`
     *
     * A cooperating AICOUNTLY tab reported a company that is not this session's
     * (§12). Sharing stops, the session pauses, and the mismatch is recorded.
     */
    public function contextMismatch(string $uuid): ResponseInterface
    {
        $session = $this->resolveSession($uuid);
        $body    = $this->body();

        $updated = Services::sessionService()->recordContextMismatch(
            $session,
            $this->identity(),
            $this->optionalInt($body, 'observedCompanyId'),
            $this->optionalString($body, 'observedProduct', 40),
        );

        return $this->ok($this->detail($updated));
    }

    /** `POST /sessions/{uuid}/pause` */
    public function pause(string $uuid): ResponseInterface
    {
        $session = $this->resolveSession($uuid);

        return $this->ok($this->detail(Services::sessionService()->pause($session, $this->identity())));
    }

    /** `POST /sessions/{uuid}/resume` */
    public function resume(string $uuid): ResponseInterface
    {
        $session = $this->resolveSession($uuid);

        return $this->ok($this->detail(Services::sessionService()->resume($session, $this->identity())));
    }

    /** `POST /sessions/{uuid}/end` */
    public function end(string $uuid): ResponseInterface
    {
        $session = $this->resolveSession($uuid);

        $updated = Services::sessionService()->end(
            $session,
            $this->identity(),
            $this->optionalString($this->body(), 'reason', 40) ?? 'ENDED_BY_USER',
        );

        return $this->ok($this->detail($updated));
    }

    /** `GET /sessions/history` */
    public function history(): ResponseInterface
    {
        $result = Services::sessionService()->history($this->identity(), [
            'scopeType'     => $this->request->getGet('scopeType'),
            'companyId'     => $this->request->getGet('companyId'),
            'status'        => $this->request->getGet('status'),
            'sessionType'   => $this->request->getGet('sessionType'),
            'sourceProduct' => $this->request->getGet('sourceProduct'),
            'from'          => $this->request->getGet('from'),
            'to'            => $this->request->getGet('to'),
            'limit'         => (int) ($this->request->getGet('limit') ?? 25),
            'offset'        => (int) ($this->request->getGet('offset') ?? 0),
        ]);

        return $this->ok(
            array_map(static fn (array $row) => Presenter::session($row), $result['items']),
            ['total' => $result['total']],
        );
    }

    /** `GET /sessions/{uuid}/events` */
    public function events(string $uuid): ResponseInterface
    {
        $session = $this->resolveSession($uuid);

        $events = Services::sessionService()->events((int) $session['id']);

        return $this->ok(array_map(static fn (array $row) => Presenter::event($row), $events));
    }

    /**
     * `POST /sessions/{uuid}/feedback` — "was your issue resolved?" (§72)
     */
    public function feedback(string $uuid): ResponseInterface
    {
        $session  = $this->resolveSession($uuid);
        $identity = $this->identity();
        $body     = $this->body();

        $resolution = $this->enum($body, 'resolution', ['YES', 'PARTIALLY', 'NO'], 'YES');
        $rating     = $this->optionalInt($body, 'rating');
        $comments   = $this->optionalString($body, 'comments', 2000);

        db_connect()->query(
            <<<'SQL'
                INSERT INTO remote_session_feedback (session_id, user_id, resolution, rating, comments, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
                ON CONFLICT (session_id, user_id) DO UPDATE
                    SET resolution = EXCLUDED.resolution,
                        rating     = EXCLUDED.rating,
                        comments   = EXCLUDED.comments
                SQL,
            [
                $session['id'],
                $identity->id,
                $resolution,
                $rating !== null ? max(1, min(5, $rating)) : null,
                $comments,
            ],
        );

        return $this->ok(['recorded' => true]);
    }

    /**
     * The session as the app renders it: the row, who is in it, what is
     * pending, and the invitations the host issued.
     *
     * @param  array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function detail(array $session): array
    {
        $identity     = $this->context()->identityOrNull();
        $participants = Services::participantService();

        $includeAudit = false;
        if ($identity !== null && $session['company_id'] !== null) {
            try {
                $includeAudit = Services::policyResolver()
                    ->resolve($identity, 'COMPANY', (int) $session['company_id'])
                    ->can(PermissionCatalog::AUDIT_VIEW);
            } catch (ApiException) {
                $includeAudit = false;
            }
        }

        $isHost = $identity !== null && (int) $session['owner_user_id'] === $identity->id;

        $resource = Presenter::session($session, $includeAudit);
        $resource['companyName'] ??= $session['company_id'] !== null
            ? Services::policyResolver()->companyName((int) $session['company_id'])
            : null;

        $resource['participants'] = array_map(
            static fn (array $row) => Presenter::participant($row, $includeAudit),
            $participants->forSession((int) $session['id']),
        );

        $resource['isHost'] = $isHost;

        $me = $identity !== null ? $participants->findByUser((int) $session['id'], $identity->id) : null;
        if ($me === null && ($guest = $this->context()->guest()) !== null) {
            $me = $participants->findByUuid($guest->participantUuid);
        }
        $resource['me'] = $me !== null ? Presenter::participant($me) : null;

        // Only the host needs the pending queue and the invitation list — they
        // are the only person who can act on either (§71).
        if ($isHost) {
            $resource['waiting'] = array_map(
                static fn (array $row) => Presenter::participant($row),
                $participants->waitingFor((int) $session['id']),
            );
            $resource['invitations'] = array_map(
                static fn (array $row) => Presenter::invitation($row),
                Services::invitationService()->forSession((int) $session['id']),
            );
        }

        return $resource;
    }

    /**
     * Load a session this caller may act in — signed-in user or guest.
     *
     * @return array<string, mixed>
     */
    private function resolveSession(string $uuid): array
    {
        $guest = $this->context()->guest();

        if ($guest !== null) {
            // A guest token names exactly one session, and this is where that
            // is enforced: presenting it against any other uuid is a 404.
            $guest->assertSession($uuid);

            return Services::sessionService()->findByUuidOrFail($uuid);
        }

        return Services::sessionService()->findForUser($uuid, $this->identity());
    }

    /**
     * The policy for the session's own scope — not for whatever the user has
     * selected in the header.
     *
     * @param array<string, mixed> $session
     */
    private function policyForSession(array $session)
    {
        return Services::policyResolver()->resolve(
            $this->identity(),
            (string) $session['scope_type'],
            $session['company_id'] !== null ? (int) $session['company_id'] : null,
        );
    }
}
