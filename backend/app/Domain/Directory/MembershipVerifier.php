<?php

declare(strict_types=1);

namespace App\Domain\Directory;

use App\Domain\Auth\RemoteIdentity;
use App\Domain\Policy\PlanEntitlements;
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
                // The company's plan came with the same answer (CONTRACTS §3.5): kept for PlanEntitlements.
                // Workspace-wide access (`app_access_source: workspace`) needs nothing more here: the row
                // Remote holds stays a member's (role MEMBER, never company admin) — Remote derives no role
                // from Manage's `app_role_key`.
                $this->rememberPlan($companyId, (bool) ($answer['planKnown'] ?? false), $answer['plan'] ?? null);
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
     * The person's companies, from Manage's live directory (finding F-2).
     *
     * Manage's company directory (`GET /api/v1/company-directory?app=remote&access=granted`, asked with the
     * person's own session; an older Manage's company list otherwise — ManageClient::openableCompanies)
     * is the source: every company it says the person may open in Remote — a share, an approved
     * request, a creator's access, a workspace owner's "every company" access — is added here as a
     * confirmed member's row (role MEMBER, never company administrator; a row Remote already holds keeps
     * its administrator flag and role, which are Remote's own), and every row Remote holds that is due a
     * confirmation is checked against it: present means confirmed (and the name refreshed); absent means
     * removed. The list is read when a row is due, or when the last read is older than
     * `membershipVerifySeconds` (remote_identities.directory_synced_at), so a new grant shows up in the
     * picker within that window. An unanswered question changes nothing.
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

        if ($stale === [] && ! $this->directoryDue($identity->id)) {
            return;
        }

        $accessible = $this->manage->openableCompanies($sesKey);
        if ($accessible === null) {
            return;
        }

        foreach ($stale as $row) {
            $companyId = (int) $row['company_id'];
            if (! array_key_exists($companyId, $accessible)) {
                $this->forget($identity->id, $companyId);
            }
        }
        foreach ($accessible as $companyId => $name) {
            $this->adopt($identity->id, (int) $companyId, $name !== '' ? $name : null);
        }
        try {
            $this->db->query('UPDATE remote_identities SET directory_synced_at = NOW() WHERE id = ?', [$identity->id]);
        } catch (\Throwable $e) {
            log_message('warning', 'Remote: directory read time not kept (migration 2026-10-24-000200 not applied?): {reason}', ['reason' => $e->getMessage()]);
        }
    }

    /** Whether Manage's list was last read for this person longer ago than the verify window (or never). */
    private function directoryDue(int $userId): bool
    {
        try {
            $last = $this->db->table('remote_identities')->select('directory_synced_at')->where('id', $userId)->get()->getRowArray();
        } catch (\Throwable $e) {
            // A database without the column yet (migration 2026-10-24-000200): behave as before it.
            return false;
        }
        $at = $last['directory_synced_at'] ?? null;

        return $at === null || strtotime((string) $at) < time() - $this->config->membershipVerifySeconds;
    }

    /**
     * Manage lists $companyId for this person in Remote: hold it as a confirmed member's row. An existing
     * row is confirmed and keeps its role and administrator flag (Remote's own); a new one is MEMBER.
     */
    private function adopt(int $userId, int $companyId, ?string $name): void
    {
        $this->db->query(
            'INSERT INTO remote_company_directory (company_id, name, synced_at, created_at, updated_at)
             VALUES (?, ?, NOW(), NOW(), NOW())
             ON CONFLICT (company_id) DO ' . ($name !== null ? 'UPDATE SET name = EXCLUDED.name, synced_at = NOW(), updated_at = NOW()' : 'NOTHING'),
            [$companyId, $name ?? ('Company ' . $companyId)],
        );
        $this->db->query(
            <<<'SQL'
                INSERT INTO remote_user_company_access
                    (user_id, company_id, role_key, is_company_admin, source, synced_at, verified_at, created_at, updated_at)
                VALUES (?, ?, 'MEMBER', FALSE, 'PORTAL', NOW(), NOW(), NOW(), NOW())
                ON CONFLICT (user_id, company_id) DO UPDATE
                    SET verified_at = NOW(), synced_at = NOW(), updated_at = NOW()
                SQL,
            [$userId, $companyId],
        );
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

    /** Keep the plan companyinfo carried (or that it carried none) on the company's directory row. */
    private function rememberPlan(int $companyId, bool $planKnown, mixed $plan): void
    {
        try {
            $this->db->query(
                <<<'SQL'
                    INSERT INTO remote_company_directory (company_id, name, synced_at, created_at, updated_at)
                    VALUES (?, ?, NOW(), NOW(), NOW())
                    ON CONFLICT (company_id) DO NOTHING
                    SQL,
                [$companyId, 'Company ' . $companyId],
            );
            PlanEntitlements::remember($this->db, $companyId, $planKnown ? $plan : null);
        } catch (\Throwable $e) {
            // A database without the plan columns yet: no limits, membership unaffected.
            log_message('warning', 'Remote: plan of company {company} not kept: {reason}', ['company' => $companyId, 'reason' => $e->getMessage()]);
        }
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
