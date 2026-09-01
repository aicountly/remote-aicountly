<?php

declare(strict_types=1);

namespace App\Domain\Session;

use App\Domain\Audit\AuditService;
use App\Domain\Audit\EventType;
use App\Domain\Auth\GuestPrincipal;
use App\Domain\Auth\RemoteIdentity;
use App\Domain\Policy\EffectivePolicyResolver;
use App\Domain\Policy\PermissionCatalog;
use App\Domain\Support\ApiException;
use App\Domain\Support\Ids;
use CodeIgniter\Database\BaseConnection;
use Config\Remote as RemoteConfig;

/**
 * The two ways into someone else's session: a join code, and an invitation link.
 *
 * They are deliberately not equivalent.
 *
 * * **A join code requires an AICOUNTLY sign-in.** Nine digits is a small space
 *   to defend by rate limiting alone, so the code is a convenience for people
 *   who already have an account — never a credential that admits a stranger.
 * * **An invitation link is the only path for an external guest**, because it
 *   carries 256 bits of entropy, expires in minutes and is single-use.
 *
 * Both end at the same place: a participant in REQUESTED, waiting for the host
 * to say yes (§71).
 */
class JoinService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly SessionService $sessions,
        private readonly ParticipantService $participants,
        private readonly InvitationService $invitations,
        private readonly EffectivePolicyResolver $policies,
        private readonly AuditService $audit,
        private readonly RemoteConfig $config,
    ) {
    }

    /**
     * Join by 9-digit code (§6E). Authenticated AICOUNTLY users only.
     *
     * @return array{session: array<string, mixed>, participant: array<string, mixed>}
     */
    public function joinByCode(string $rawCode, RemoteIdentity $identity, ?string $userAgent, ?string $ip): array
    {
        $code = Ids::normaliseJoinCode($rawCode);

        if (strlen($code) !== $this->config->joinCodeLength) {
            throw ApiException::badRequest('JOIN_CODE_INVALID', 'That session code does not look right. Check the digits and try again.');
        }

        $row = $this->db->table('remote_sessions')->where('session_code', $code)->get()->getRowArray();

        // One message for "no such code" and for "code belongs to a finished
        // session", so the code space cannot be probed for which codes exist.
        if ($row === null) {
            throw ApiException::notFound('That session code is not valid. It may have expired.');
        }

        $session = $this->sessions->findByUuidOrFail((string) $row['uuid']);

        if (! SessionStatus::isLive((string) $session['status'])) {
            throw ApiException::notFound('That session code is not valid. It may have expired.');
        }

        return $this->joinAuthenticated($session, $identity, $userAgent, $ip);
    }

    /**
     * Ask to join a session you already have the uuid for — the path taken when
     * a support technician accepts a request, or when someone follows a link
     * back into a session they were already part of.
     *
     * Identical guarantees to the code path: membership is checked, and the
     * participant still lands in REQUESTED awaiting the host.
     *
     * @param  array<string, mixed> $session
     * @return array{session: array<string, mixed>, participant: array<string, mixed>}
     */
    public function joinAuthenticated(
        array $session,
        RemoteIdentity $identity,
        ?string $userAgent = null,
        ?string $ip = null,
    ): array {
        if (! SessionStatus::isLive((string) $session['status'])) {
            throw ApiException::conflict('SESSION_ALREADY_ENDED', 'This Remote session has already finished.');
        }

        $this->assertMayJoin($session, $identity);

        $participant = $this->participants->requestJoin(
            $session,
            $identity,
            $identity->displayName,
            $this->roleFor($session, $identity),
            null,
            $identity->email,
            $userAgent,
            $ip,
        );

        $session = $this->promoteToJoinRequested($session);

        return ['session' => $session, 'participant' => $participant];
    }

    /**
     * Redeem an invitation link.
     *
     * @param  RemoteIdentity|null $identity null when nobody is signed in
     * @return array{session: array<string, mixed>, participant: array<string, mixed>, guestToken: string|null}
     */
    public function redeemInvitation(
        string $secret,
        ?RemoteIdentity $identity,
        ?string $guestName,
        ?string $guestEmail,
        ?string $userAgent,
        ?string $ip,
    ): array {
        $invitation = $this->invitations->findLiveBySecret($secret);
        if ($invitation === null) {
            throw ApiException::notFound('This invitation link is not valid. Ask for a new one.');
        }

        $session = $this->sessions->findById((int) $invitation['session_id']);
        if ($session === null || ! SessionStatus::isLive((string) $session['status'])) {
            throw ApiException::conflict('SESSION_ALREADY_ENDED', 'This Remote session has already finished.');
        }

        $type = (string) $invitation['invitation_type'];

        if ($type === 'EXTERNAL_GUEST') {
            return $this->redeemAsGuest($session, $invitation, $guestName, $guestEmail, $userAgent, $ip);
        }

        // INTERNAL and SUPPORT invitations name an AICOUNTLY person, so being
        // signed in is the whole point of them.
        if ($identity === null) {
            throw ApiException::unauthenticated('Sign in to AICOUNTLY to join this Remote session.');
        }

        $this->assertMayJoin($session, $identity);

        $role = $type === 'SUPPORT' ? ParticipantService::ROLE_SUPPORT : $this->roleFor($session, $identity);

        $participant = $this->participants->requestJoin(
            $session,
            $identity,
            $identity->displayName,
            $role,
            (int) $invitation['id'],
            $identity->email,
            $userAgent,
            $ip,
        );

        $this->invitations->redeem($invitation, (int) $participant['id']);
        $this->audit->record($session, EventType::INVITATION_REDEEMED, $identity->id, 'USER', (int) $participant['id'], (string) $participant['uuid'], [
            'invitationUuid' => $invitation['uuid'],
            'type'           => $type,
        ]);

        $session = $this->promoteToJoinRequested($session);

        return ['session' => $session, 'participant' => $participant, 'guestToken' => null];
    }

    /**
     * @param  array<string, mixed> $session
     * @param  array<string, mixed> $invitation
     * @return array{session: array<string, mixed>, participant: array<string, mixed>, guestToken: string}
     */
    private function redeemAsGuest(
        array $session,
        array $invitation,
        ?string $guestName,
        ?string $guestEmail,
        ?string $userAgent,
        ?string $ip,
    ): array {
        // Re-checked at redemption, not only at creation: the organisation may
        // have turned guests off since the link was sent, and the link must
        // stop working the moment it does.
        if (! $this->truthy($session['allow_external_guest'])) {
            throw ApiException::forbidden(
                'EXTERNAL_GUEST_NOT_ALLOWED',
                'This organisation no longer allows people outside AICOUNTLY to join Remote sessions.',
            );
        }

        $name = trim((string) ($guestName ?? ''));
        if ($name === '') {
            $name = $invitation['invitee_email'] !== null ? (string) $invitation['invitee_email'] : 'Guest';
        }

        $participant = $this->participants->requestJoin(
            $session,
            null,
            mb_substr($name, 0, 120),
            ParticipantService::ROLE_GUEST,
            (int) $invitation['id'],
            $guestEmail ?? ($invitation['invitee_email'] !== null ? (string) $invitation['invitee_email'] : null),
            $userAgent,
            $ip,
        );

        $this->invitations->redeem($invitation, (int) $participant['id']);

        $this->audit->record($session, EventType::INVITATION_REDEEMED, null, 'GUEST', (int) $participant['id'], (string) $participant['uuid'], [
            'invitationUuid' => $invitation['uuid'],
            'type'           => 'EXTERNAL_GUEST',
        ]);

        $session = $this->promoteToJoinRequested($session);

        // The guest's credential expires with the session, never after it.
        $expiresAt = strtotime((string) $session['expires_at']) ?: time() + 3600;

        return [
            'session'     => $session,
            'participant' => $participant,
            'guestToken'  => GuestPrincipal::issue(
                $this->config,
                (string) $participant['uuid'],
                (string) $session['uuid'],
                (string) $participant['display_name'],
                $expiresAt,
            ),
        ];
    }

    /**
     * Tenant isolation on the way in (§77).
     *
     * For a company session the joiner must belong to that company. Being a
     * member of some *other* company, or holding a permission somewhere else,
     * counts for nothing here.
     *
     * @param array<string, mixed> $session
     */
    private function assertMayJoin(array $session, RemoteIdentity $identity): void
    {
        $companyId = $session['company_id'] !== null ? (int) $session['company_id'] : null;
        $scope     = (string) $session['scope_type'];

        if ($companyId === null) {
            // A personal session: anyone signed in may ask, and the host decides.
            $policy = $this->policies->resolve($identity, 'PERSONAL', null);
        } else {
            $isSupportTechnician = $scope === 'AICOUNTLY_SUPPORT'
                && ($identity->isSupportAgent || in_array($identity->id, $this->config->supportTechnicianUserIds, true));

            if ($isSupportTechnician) {
                // A support technician is not a member of the customer's
                // company and must not be treated as one — their permission to
                // join comes from their own AICOUNTLY standing.
                $policy = $this->policies->resolve($identity, 'PERSONAL', null);

                if (! $policy->can(PermissionCatalog::SUPPORT_ACCEPT)) {
                    throw ApiException::forbidden('SUPPORT_JOIN_DENIED', 'You do not have permission to join AICOUNTLY Support sessions.');
                }

                return;
            }

            // resolve() throws COMPANY_ACCESS_DENIED for a non-member.
            $policy = $this->policies->resolve($identity, 'COMPANY', $companyId);
        }

        if (! $policy->can(PermissionCatalog::SESSION_JOIN)) {
            throw ApiException::forbidden('SESSION_JOIN_DENIED', 'You do not have permission to join Remote sessions.');
        }

        if (! $policy->can(PermissionCatalog::SCREEN_VIEW)) {
            throw ApiException::forbidden('SCREEN_VIEW_DENIED', 'You do not have permission to view a shared screen.');
        }
    }

    /** @param array<string, mixed> $session */
    private function roleFor(array $session, RemoteIdentity $identity): string
    {
        if ((string) $session['scope_type'] === 'AICOUNTLY_SUPPORT'
            && ($identity->isSupportAgent || in_array($identity->id, $this->config->supportTechnicianUserIds, true))) {
            return ParticipantService::ROLE_SUPPORT;
        }

        return ParticipantService::ROLE_VIEWER;
    }

    /**
     * WAITING → JOIN_REQUESTED, so the host's screen shows something is
     * pending. Any other live state is left alone: an already-ACTIVE session
     * gaining a second viewer must not fall back to a waiting state.
     *
     * @param  array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function promoteToJoinRequested(array $session): array
    {
        if (in_array((string) $session['status'], [SessionStatus::CREATED, SessionStatus::WAITING], true)) {
            return $this->sessions->transition($session, SessionStatus::JOIN_REQUESTED);
        }

        return $session;
    }

    private function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }
}
