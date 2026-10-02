<?php

declare(strict_types=1);

namespace App\Domain\Directory;

use App\Domain\Auth\RemoteIdentity;
use App\Domain\Support\ApiException;
use App\Domain\Support\RequestContext;
use CodeIgniter\Database\BaseConnection;
use Config\Remote as RemoteConfig;

/**
 * Keeps Remote's company membership honest against Aicountly Manage (G28#4).
 *
 * A row in `remote_user_company_access` says "this person belongs to this
 * company", and Manage is the one that knows. A row is *relied on* only while a
 * confirmation from Manage is recent:
 *
 *   * **Fresh** (confirmed within `membershipVerifySeconds`): used as is.
 *   * **Stale, and the person's own session is in hand** (they are the caller):
 *     Manage is asked. Yes refreshes the confirmation; no *removes the row* and
 *     the person's seat in that company's live sessions; no answer falls back to
 *     the grace period below.
 *   * **Stale, and nobody can be asked** (a device acting for its owner, an
 *     admin screen listing someone else): the row is honoured for
 *     `membershipGraceSeconds` after its last confirmation and not a moment
 *     after.
 *
 * An outage therefore neither locks everyone out at once nor lets a removed
 * person stay for ever; and a launch token, which only ever *hints* a company,
 * grants nothing until Manage has said yes.
 */
class MembershipVerifier
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly RemoteConfig $config,
        private readonly ManageClient $manage,
        private readonly RequestContext $context,
    ) {
    }

    public function enforcing(): bool
    {
        return $this->config->membershipEnforcement;
    }

    /**
     * The row if it may be relied on, null if the person no longer belongs.
     *
     * @param  array<string, mixed> $row a `remote_user_company_access` row, with `verified_at`
     * @return array<string, mixed>|null
     * @throws ApiException 503 when Manage cannot be asked and the last confirmation is too old to rely on
     */
    public function confirm(array $row): ?array
    {
        if (! $this->enforcing()) {
            return $row;
        }

        $age = $this->ageOf($row);
        if ($age !== null && $age <= $this->config->membershipVerifySeconds) {
            return $row;
        }

        $userId    = (int) $row['user_id'];
        $companyId = (int) $row['company_id'];
        $sesKey    = $this->sesKeyFor($userId);

        if ($sesKey !== null) {
            $answer = $this->manage->companyAccess($sesKey, $companyId);

            if ($answer['state'] === ManageClient::GRANTED) {
                $this->markVerified($userId, $companyId, $answer['name']);
                $row['verified_at'] = gmdate('Y-m-d H:i:sO');

                return $row;
            }
            if ($answer['state'] === ManageClient::DENIED) {
                $this->forget($userId, $companyId);

                return null;
            }
        }

        if ($age !== null && $age <= $this->config->membershipGraceSeconds) {
            return $row;
        }

        if ($sesKey !== null) {
            // We asked, Manage did not answer, and the last yes is too old.
            throw new ApiException(
                'MEMBERSHIP_UNAVAILABLE',
                'AICOUNTLY could not confirm your access to this organisation just now. Please retry in a moment.',
                503,
                ['retryable' => true],
            );
        }

        return null;
    }

    /**
     * Check every company this person holds against what Manage lets them open.
     * Present means confirmed (and the name refreshed); absent means removed.
     * An unanswered question changes nothing.
     */
    public function reconcileUser(RemoteIdentity $identity): void
    {
        if (! $this->enforcing()) {
            return;
        }

        $sesKey = $this->sesKeyFor($identity->id);
        if ($sesKey === null) {
            return;
        }

        $stale = $this->db->table('remote_user_company_access')
            ->select('company_id, verified_at')
            ->where('user_id', $identity->id)
            ->groupStart()
                ->where('verified_at IS NULL', null, false)
                ->orWhere('verified_at <', gmdate('Y-m-d H:i:sO', time() - $this->config->membershipVerifySeconds))
            ->groupEnd()
            ->get()
            ->getResultArray();

        if ($stale === []) {
            return;
        }

        $accessible = $this->manage->accessibleCompanies($sesKey);
        if ($accessible === null) {
            return;
        }

        foreach ($stale as $row) {
            $companyId = (int) $row['company_id'];
            if (array_key_exists($companyId, $accessible)) {
                $this->markVerified($identity->id, $companyId, $accessible[$companyId] !== '' ? $accessible[$companyId] : null);
            } else {
                $this->forget($identity->id, $companyId);
            }
        }
    }

    /**
     * Take a company's Remote people back to what Manage's member directory
     * says, using an administrator's own session.
     *
     * Whoever Remote holds for the company but Manage does not list is removed;
     * whoever is listed is confirmed, and their name and e-mail learned if
     * Remote did not have them (G28#1).
     *
     * @return array{state: string, removed: int}
     */
    public function reconcileCompany(int $companyId, string $sesKey): array
    {
        if (! $this->enforcing()) {
            return ['state' => 'skipped', 'removed' => 0];
        }

        $directory = $this->manage->companyMembers($sesKey, $companyId);
        if ($directory['state'] !== ManageClient::GRANTED) {
            return ['state' => $directory['state'], 'removed' => 0];
        }

        $listed = [];
        foreach ($directory['members'] as $member) {
            $listed[$member['uuid']] = $member;
        }

        $held = $this->db->table('remote_user_company_access a')
            ->select('a.user_id, i.platform_uuid, i.display_name, i.email')
            ->join('remote_identities i', 'i.id = a.user_id')
            ->where('a.company_id', $companyId)
            ->get()
            ->getResultArray();

        $removed = 0;
        foreach ($held as $person) {
            $member = $listed[(string) $person['platform_uuid']] ?? null;
            if ($member === null) {
                $this->forget((int) $person['user_id'], $companyId);
                $removed++;
                continue;
            }

            $this->markVerified((int) $person['user_id'], $companyId, null);
            $this->learn((int) $person['user_id'], $person, $member);
        }

        return ['state' => ManageClient::GRANTED, 'removed' => $removed];
    }

    /**
     * Remove a person's access to a company and their seat in its live sessions.
     *
     * Their sessions stay as history; what stops is being let into, or kept in,
     * a company's room by a membership that has ended.
     */
    public function forget(int $userId, int $companyId): void
    {
        $this->db->transStart();

        $this->db->query(
            'DELETE FROM remote_user_company_access WHERE user_id = ? AND company_id = ?',
            [$userId, $companyId],
        );

        $this->db->query(
            <<<'SQL'
                UPDATE remote_participants
                   SET status = 'REMOVED', left_at = COALESCE(left_at, NOW()), updated_at = NOW()
                 WHERE user_id = ?
                   AND is_host = FALSE
                   AND status IN ('REQUESTED', 'APPROVED', 'JOINED')
                   AND session_id IN (
                       SELECT id FROM remote_sessions
                        WHERE company_id = ?
                          AND status NOT IN ('ENDED', 'DECLINED', 'EXPIRED', 'FAILED')
                   )
                SQL,
            [$userId, $companyId],
        );

        $this->db->transComplete();

        log_message('notice', 'Remote: company access of user {user} to company {company} removed (no longer a member in Manage)', [
            'user'    => $userId,
            'company' => $companyId,
        ]);
    }

    public function markVerified(int $userId, int $companyId, ?string $companyName): void
    {
        $this->db->query(
            'UPDATE remote_user_company_access SET verified_at = NOW(), synced_at = NOW(), updated_at = NOW() WHERE user_id = ? AND company_id = ?',
            [$userId, $companyId],
        );

        if ($companyName !== null && $companyName !== '') {
            $this->db->query(
                <<<'SQL'
                    INSERT INTO remote_company_directory (company_id, name, synced_at, created_at, updated_at)
                    VALUES (?, ?, NOW(), NOW(), NOW())
                    ON CONFLICT (company_id) DO UPDATE SET name = EXCLUDED.name, synced_at = NOW(), updated_at = NOW()
                    SQL,
                [$companyId, $companyName],
            );
        }
    }

    /**
     * Fill in a name or e-mail Remote does not have yet; never overwrite one it does.
     *
     * @param array<string, mixed>                              $person
     * @param array{uuid: string, name: string, email: ?string} $member
     */
    private function learn(int $userId, array $person, array $member): void
    {
        $nameKnown  = trim((string) $person['display_name']) !== '' && $person['display_name'] !== 'AICOUNTLY user';
        $emailKnown = trim((string) ($person['email'] ?? '')) !== '';

        $set = [];
        if (! $nameKnown && $member['name'] !== '') {
            $set['display_name'] = $member['name'];
        }
        if (! $emailKnown && $member['email'] !== null) {
            $set['email'] = $member['email'];
        }
        if ($set !== []) {
            $this->db->table('remote_identities')->where('id', $userId)->update($set);
        }
    }

    /** Seconds since Manage last confirmed the row; null when it never did. */
    private function ageOf(array $row): ?int
    {
        $verified = $row['verified_at'] ?? null;
        if ($verified === null || $verified === '') {
            return null;
        }
        $at = strtotime((string) $verified);

        return $at === false ? null : max(0, time() - $at);
    }

    /** The caller's own session, but only when the caller is that person. */
    private function sesKeyFor(int $userId): ?string
    {
        $identity = $this->context->identityOrNull();
        if ($identity === null || $identity->id !== $userId) {
            return null;
        }

        $key = $this->context->sesKey();

        return $key !== null && $key !== '' ? $key : null;
    }
}
