<?php

declare(strict_types=1);

namespace App\Domain\Directory;

use App\Domain\Auth\RemoteIdentity;
use App\Domain\Auth\SourceContext;
use App\Domain\Support\Clock;
use CodeIgniter\Database\BaseConnection;
use Config\Remote as RemoteConfig;

/**
 * Which AICOUNTLY companies a person may act in, and what they are called.
 *
 * AICOUNTLY owns this data (manage.aicountly.com). Remote keeps a projection so
 * that a session list is readable and a permission check does not require a
 * network call, and refreshes it from three sources, in order of authority:
 *
 *   1. **A verified source-context token.** The strongest signal there is: an
 *      AICOUNTLY product has just asserted, over a signature, that this user is
 *      working in this company. Recorded immediately.
 *   2. **The platform directory API**, when `remote.directoryBase` is set.
 *   3. **An administrator**, through the permissions screen.
 *
 * With none of the three configured, Remote still works: personal sessions need
 * no company at all, and a company session becomes available the first time
 * that company launches Remote with signed context.
 */
class PlatformDirectory
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly RemoteConfig $config,
    ) {
    }

    /**
     * The companies this user can pick from when starting a session (§6B).
     *
     * @return list<array{companyId: int, name: string, isCompanyAdmin: bool, roleKey: string,
     *                    branchId: int|null, financialYearId: int|null}>
     */
    public function companiesFor(RemoteIdentity $identity): array
    {
        $this->refreshFromDirectory($identity);

        $rows = $this->db->table('remote_user_company_access a')
            ->select('a.company_id, a.branch_id, a.financial_year_id, a.role_key, a.is_company_admin, d.name')
            ->join('remote_company_directory d', 'd.company_id = a.company_id', 'left')
            ->where('a.user_id', $identity->id)
            ->orderBy('d.name', 'ASC')
            ->get()
            ->getResultArray();

        return array_map(fn (array $row) => [
            'companyId'       => (int) $row['company_id'],
            'name'            => (string) ($row['name'] ?? ('Company ' . $row['company_id'])),
            'isCompanyAdmin'  => $this->truthy($row['is_company_admin']),
            'roleKey'         => (string) $row['role_key'],
            'branchId'        => $row['branch_id'] !== null ? (int) $row['branch_id'] : null,
            'financialYearId' => $row['financial_year_id'] !== null ? (int) $row['financial_year_id'] : null,
        ], $rows);
    }

    /**
     * Record what a verified context token just told us (§6C).
     *
     * This is what makes a company usable in Remote without a directory API:
     * launching once from AICOUNTLY Books with signed context is enough for
     * that company to appear, with its branch and financial year attached.
     */
    public function rememberFromContext(RemoteIdentity $identity, SourceContext $context): void
    {
        if ($context->companyId === null) {
            return;
        }

        $this->db->query(
            <<<'SQL'
                INSERT INTO remote_user_company_access
                    (user_id, company_id, branch_id, financial_year_id, role_key, source, synced_at, created_at, updated_at)
                VALUES (?, ?, ?, ?, 'MEMBER', 'CONTEXT_TOKEN', NOW(), NOW(), NOW())
                ON CONFLICT (user_id, company_id) DO UPDATE
                    SET branch_id         = COALESCE(EXCLUDED.branch_id, remote_user_company_access.branch_id),
                        financial_year_id = COALESCE(EXCLUDED.financial_year_id, remote_user_company_access.financial_year_id),
                        synced_at         = NOW(),
                        updated_at        = NOW()
                SQL,
            [$identity->id, $context->companyId, $context->branchId, $context->financialYearId],
        );

        $this->ensureCompanyRow($context->companyId, null);
    }

    /** Make sure a company has a directory row so its name can be rendered. */
    public function ensureCompanyRow(int $companyId, ?string $name): void
    {
        $this->db->query(
            <<<'SQL'
                INSERT INTO remote_company_directory (company_id, name, synced_at, created_at, updated_at)
                VALUES (?, ?, NOW(), NOW(), NOW())
                ON CONFLICT (company_id) DO UPDATE
                    SET name = CASE WHEN EXCLUDED.name <> '' THEN EXCLUDED.name ELSE remote_company_directory.name END,
                        synced_at = NOW(),
                        updated_at = NOW()
                SQL,
            [$companyId, $name ?? ('Company ' . $companyId)],
        );
    }

    /** @return array<string, mixed>|null */
    public function company(int $companyId): ?array
    {
        return $this->db->table('remote_company_directory')->where('company_id', $companyId)->get()->getRowArray();
    }

    /**
     * Pull membership from the platform when a directory API is configured and
     * the projection has gone stale.
     *
     * A failure here is not an error the user should see: the projection simply
     * stays as it was. Remote must not stop working because a directory service
     * is slow, and it must not *grant* anything it could not confirm either —
     * so nothing is removed on a failed refresh, only added on a successful one.
     */
    private function refreshFromDirectory(RemoteIdentity $identity): void
    {
        if ($this->config->directoryBase === '') {
            return;
        }

        $freshest = $this->db->table('remote_user_company_access')
            ->selectMax('synced_at')
            ->where('user_id', $identity->id)
            ->get()
            ->getRowArray();

        if ($freshest !== null && $freshest['synced_at'] !== null
            && strtotime((string) $freshest['synced_at']) > time() - $this->config->directoryCacheSeconds) {
            return;
        }

        $payload = $this->fetchDirectory('/companies?user_uuid=' . rawurlencode($identity->uuid));
        if ($payload === null) {
            return;
        }

        $companies = $payload['companies'] ?? $payload['data'] ?? [];
        if (! is_array($companies)) {
            return;
        }

        foreach ($companies as $company) {
            if (! is_array($company)) {
                continue;
            }

            $companyId = (int) ($company['company_id'] ?? $company['id'] ?? 0);
            if ($companyId <= 0) {
                continue;
            }

            $this->ensureCompanyRow($companyId, isset($company['name']) ? (string) $company['name'] : null);

            $this->db->query(
                <<<'SQL'
                    INSERT INTO remote_user_company_access
                        (user_id, company_id, branch_id, financial_year_id, role_key, is_company_admin, source, synced_at, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, 'PORTAL', NOW(), NOW(), NOW())
                    ON CONFLICT (user_id, company_id) DO UPDATE
                        SET role_key         = EXCLUDED.role_key,
                            is_company_admin = EXCLUDED.is_company_admin,
                            branch_id        = COALESCE(EXCLUDED.branch_id, remote_user_company_access.branch_id),
                            financial_year_id = COALESCE(EXCLUDED.financial_year_id, remote_user_company_access.financial_year_id),
                            source           = 'PORTAL',
                            synced_at        = NOW(),
                            updated_at       = NOW()
                    SQL,
                [
                    $identity->id,
                    $companyId,
                    isset($company['branch_id']) ? (int) $company['branch_id'] : null,
                    isset($company['financial_year_id']) ? (int) $company['financial_year_id'] : null,
                    (string) ($company['role_key'] ?? $company['role'] ?? 'MEMBER'),
                    (bool) ($company['is_admin'] ?? $company['is_company_admin'] ?? false),
                ],
            );
        }
    }

    /** @return array<string, mixed>|null */
    private function fetchDirectory(string $path): ?array
    {
        $ch = curl_init($this->config->directoryBase . $path);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => array_filter([
                'Accept: application/json',
                $this->config->directoryToken !== '' ? 'Authorization: Bearer ' . $this->config->directoryToken : null,
            ]),
        ]);

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false || $status !== 200) {
            log_message('warning', 'Remote: platform directory returned {status}', ['status' => $status]);

            return null;
        }

        $decoded = json_decode((string) $body, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }
}
