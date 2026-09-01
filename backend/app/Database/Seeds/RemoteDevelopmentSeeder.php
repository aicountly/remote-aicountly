<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Sample data for local development and for the two-browser walkthrough in
 * docs/DEVELOPMENT.md.
 *
 * Refuses to run outside development. The rows it creates — two companies with
 * deliberately different policies, and three people — are exactly the shape the
 * company-isolation tests describe (§77), so the behaviour can be seen in the
 * UI as well as asserted in a test.
 *
 *     php spark db:seed RemoteDevelopmentSeeder
 */
class RemoteDevelopmentSeeder extends Seeder
{
    public function run(): void
    {
        if (ENVIRONMENT === 'production') {
            throw new \RuntimeException('RemoteDevelopmentSeeder must never run in production.');
        }

        // --- Companies -------------------------------------------------------
        // ABC permits entire-screen sharing; XYZ does not. A user who belongs
        // to both must get different answers in each, which is the whole point.
        $this->company(481, 'ABC Private Limited', [
            'policy_preset'        => 'CUSTOM',
            'allow_entire_monitor' => 'TRUE',
            'allow_file_transfer'  => 'TRUE',
            'allow_external_guest' => 'TRUE',
        ]);

        $this->company(902, 'XYZ Enterprises', [
            'policy_preset'        => 'STANDARD',
            'allow_entire_monitor' => 'FALSE',
        ]);

        // --- People ----------------------------------------------------------
        $rahul = $this->identity('dev-uuid-rahul', 1928, 'Rahul Gupta', 'rahul@abc.example');
        $priya = $this->identity('dev-uuid-priya', 1929, 'Priya Nair', 'priya@abc.example');
        $aman  = $this->identity('dev-uuid-aman', 2044, 'Aman Verma', 'aman@aicountly.com');

        // Aman answers AICOUNTLY Support requests.
        $this->db->table('remote_identities')->where('id', $aman)->update(['is_support_agent' => true]);

        // Rahul is in both companies — administrator at ABC, ordinary member at
        // XYZ. His ABC rights must have no effect inside an XYZ session.
        $this->access($rahul, 481, 'COMPANY_ADMIN', true);
        $this->access($rahul, 902, 'MEMBER', false);
        $this->access($priya, 481, 'MEMBER', false);
    }

    /** @param array<string, string> $overrides */
    private function company(int $companyId, string $name, array $overrides): void
    {
        $this->db->query(
            'INSERT INTO remote_company_directory (company_id, name, timezone, locale, synced_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, NOW(), NOW(), NOW())
             ON CONFLICT (company_id) DO UPDATE SET name = EXCLUDED.name, updated_at = NOW()',
            [$companyId, $name, 'Asia/Kolkata', 'en-IN'],
        );

        $this->db->query(
            'INSERT INTO remote_company_policies (company_id) VALUES (?) ON CONFLICT (company_id) DO NOTHING',
            [$companyId],
        );

        $assignments = [];
        foreach ($overrides as $column => $value) {
            // Column names come from this file only, never from input.
            $assignments[] = sprintf('%s = %s', $column, $column === 'policy_preset' ? "'" . $value . "'" : $value);
        }

        if ($assignments !== []) {
            $this->db->query(
                'UPDATE remote_company_policies SET ' . implode(', ', $assignments) . ', updated_at = NOW() WHERE company_id = ?',
                [$companyId],
            );
        }
    }

    private function identity(string $uuid, int $platformUserId, string $name, string $email): int
    {
        $row = $this->db->query(
            'INSERT INTO remote_identities (platform_uuid, platform_user_id, display_name, email, created_at, updated_at)
             VALUES (?, ?, ?, ?, NOW(), NOW())
             ON CONFLICT (platform_uuid) DO UPDATE SET display_name = EXCLUDED.display_name, updated_at = NOW()
             RETURNING id',
            [$uuid, $platformUserId, $name, $email],
        )->getRowArray();

        return (int) $row['id'];
    }

    private function access(int $userId, int $companyId, string $roleKey, bool $isAdmin): void
    {
        $this->db->query(
            'INSERT INTO remote_user_company_access (user_id, company_id, role_key, is_company_admin, source, synced_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, \'SEED\', NOW(), NOW(), NOW())
             ON CONFLICT (user_id, company_id) DO UPDATE
                 SET role_key = EXCLUDED.role_key, is_company_admin = EXCLUDED.is_company_admin, updated_at = NOW()',
            [$userId, $companyId, $roleKey, $isAdmin],
        );
    }
}
