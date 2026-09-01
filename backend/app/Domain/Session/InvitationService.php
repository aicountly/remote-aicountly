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
use App\Domain\Support\Ids;
use CodeIgniter\Database\BaseConnection;
use Config\Remote as RemoteConfig;

/**
 * One-time invitation links (§6F, §23).
 *
 * The secret is generated from `random_bytes`, returned exactly once in the
 * creation response, and stored only as a SHA-256 hash. Nothing — not an admin
 * screen, not a database dump, not this service — can reproduce a link after
 * the fact. Losing it means issuing a new one, which is the correct trade.
 *
 * Redemption is a single guarded UPDATE, so a link opened twice at the same
 * instant is consumed once (§58).
 */
class InvitationService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly AuditService $audit,
        private readonly RemoteConfig $config,
    ) {
    }

    /**
     * @param  array<string, mixed> $session
     * @return array{invitation: array<string, mixed>, url: string, secret: string}
     */
    public function create(
        array $session,
        RemoteIdentity $identity,
        EffectivePolicy $policy,
        string $type,
        ?string $inviteeEmail,
        ?int $expiryMinutes,
    ): array {
        if (! SessionStatus::isLive((string) $session['status'])) {
            throw ApiException::conflict('SESSION_ALREADY_ENDED', 'This Remote session has already finished.');
        }

        if ((int) $session['owner_user_id'] !== $identity->id) {
            throw ApiException::forbidden('NOT_SESSION_HOST', 'Only the person who started this session can invite someone to it.');
        }

        if ($type === 'EXTERNAL_GUEST') {
            // Two independent gates: the organisation must permit guests at all,
            // and this user must hold the permission to issue one (§23).
            if (! $policy->allowExternalGuest || ! (bool) $session['allow_external_guest']) {
                throw ApiException::forbidden(
                    'EXTERNAL_GUEST_NOT_ALLOWED',
                    'This organisation does not allow people outside AICOUNTLY to join Remote sessions.',
                );
            }
            if (! $policy->can(PermissionCatalog::EXTERNAL_INVITE)) {
                throw ApiException::forbidden(
                    'EXTERNAL_INVITE_DENIED',
                    'You do not have permission to invite someone outside your organisation.',
                );
            }
        }

        if (! $policy->can(PermissionCatalog::SESSION_CREATE)) {
            throw ApiException::forbidden('INVITE_DENIED', 'You do not have permission to invite people to a Remote session.');
        }

        // Never longer than the organisation's own guest-link ceiling, and
        // never past the session's own expiry — an invitation that outlives the
        // session it belongs to is a loose end.
        $requested = $expiryMinutes ?? min($this->config->inviteDefaultMinutes, $policy->guestLinkExpiryMinutes);
        $minutes   = max(1, min($requested, $policy->guestLinkExpiryMinutes));

        $expiresAt = min(
            time() + $minutes * 60,
            strtotime((string) $session['expires_at']) ?: time() + $minutes * 60,
        );

        $secret = Ids::invitationSecret();
        $uuid   = Ids::uuid4();

        $this->db->table('remote_invitations')->insert([
            'uuid'               => $uuid,
            'session_id'         => $session['id'],
            'token_hash'         => Ids::hashSecret($secret),
            'invitation_type'    => $type,
            'invitee_email'      => $inviteeEmail,
            'created_by_user_id' => $identity->id,
            'max_uses'           => 1,
            'expires_at'         => gmdate('Y-m-d H:i:s', $expiresAt) . '+00',
        ]);

        $invitation = $this->findByUuidOrFail($uuid);

        $this->audit->record($session, EventType::INVITATION_CREATED, $identity->id, 'USER', null, null, [
            'invitationUuid' => $uuid,
            'type'           => $type,
            'expiresAt'      => Clock::iso($invitation['expires_at']),
            // The invitee's address is business context, not a secret; the
            // link itself is never written anywhere.
            'inviteeEmail'   => $inviteeEmail,
        ]);

        return [
            'invitation' => $invitation,
            'url'        => $this->config->appUrl . '/join/' . $secret,
            'secret'     => $secret,
        ];
    }

    /**
     * Look up a live invitation by the secret from a link.
     *
     * The lookup is by hash, so the secret is never compared in the database
     * and never appears in a query log.
     *
     * @return array<string, mixed>|null
     */
    public function findLiveBySecret(string $secret): ?array
    {
        if ($secret === '' || strlen($secret) > 128) {
            return null;
        }

        $row = $this->db->table('remote_invitations')
            ->where('token_hash', Ids::hashSecret($secret))
            ->get()
            ->getRowArray();

        if ($row === null) {
            return null;
        }

        if ($row['revoked_at'] !== null) {
            throw ApiException::conflict('INVITATION_REVOKED', 'This invitation has been withdrawn.');
        }

        if (Clock::hasPassed($row['expires_at'])) {
            throw ApiException::conflict('INVITATION_EXPIRED', 'This invitation link has expired. Ask for a new one.');
        }

        if ((int) $row['used_count'] >= (int) $row['max_uses']) {
            throw ApiException::conflict('INVITATION_ALREADY_USED', 'This invitation link has already been used.');
        }

        return $row;
    }

    /**
     * Consume one use, atomically.
     *
     * The `used_count < max_uses` predicate inside the UPDATE is what makes
     * this safe: two browsers opening the same link at the same moment both run
     * this statement, and Postgres lets exactly one of them change a row.
     *
     * @param array<string, mixed> $invitation
     */
    public function redeem(array $invitation, int $participantId): void
    {
        $this->db->table('remote_invitations')
            ->where('id', $invitation['id'])
            ->where('used_count <', 'max_uses', false)
            ->where('revoked_at', null)
            // set(..., escape: false) so the increment is evaluated by Postgres
            // rather than written as the literal string 'used_count + 1'.
            ->set('used_count', 'used_count + 1', false)
            ->set('redeemed_at', Clock::now())
            ->set('redeemed_participant_id', $participantId)
            ->set('updated_at', Clock::now())
            ->update();

        if ($this->db->affectedRows() === 0) {
            throw ApiException::conflict('INVITATION_ALREADY_USED', 'This invitation link has already been used.');
        }
    }

    /** @param array<string, mixed> $session */
    public function revoke(array $session, string $invitationUuid, RemoteIdentity $identity): void
    {
        if ((int) $session['owner_user_id'] !== $identity->id) {
            throw ApiException::forbidden('NOT_SESSION_HOST', 'Only the person who started this session can withdraw its invitations.');
        }

        if (! Ids::isUuid($invitationUuid)) {
            throw ApiException::notFound('That invitation could not be found.');
        }

        $this->db->table('remote_invitations')
            ->where('session_id', $session['id'])
            ->where('uuid', $invitationUuid)
            ->where('revoked_at', null)
            ->update([
                'revoked_at'         => Clock::now(),
                'revoked_by_user_id' => $identity->id,
                'updated_at'         => Clock::now(),
            ]);

        if ($this->db->affectedRows() === 0) {
            throw ApiException::notFound('That invitation could not be found.');
        }

        $this->audit->record($session, EventType::INVITATION_REVOKED, $identity->id, 'USER', null, null, [
            'invitationUuid' => $invitationUuid,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function forSession(int $sessionId): array
    {
        return $this->db->table('remote_invitations')
            ->where('session_id', $sessionId)
            ->orderBy('created_at', 'DESC')
            ->get()
            ->getResultArray();
    }

    /** @return array<string, mixed> */
    public function findByUuidOrFail(string $uuid): array
    {
        $row = $this->db->table('remote_invitations')->where('uuid', $uuid)->get()->getRowArray();
        if ($row === null) {
            throw ApiException::notFound('That invitation could not be found.');
        }

        return $row;
    }
}
