<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Platform defaults. Safe to run in production, and safe to run twice.
 *
 * Only one row: the entitlement that applies to every company without one of
 * its own (§79). Role and user permission tables start empty on purpose —
 * {@see \App\Domain\Policy\PermissionCatalog} supplies the baseline in code, so
 * an empty table means "the defaults", not "nobody can do anything".
 *
 *     php spark db:seed RemotePlatformDefaultsSeeder
 */
class RemotePlatformDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        $this->db->query(
            <<<'SQL'
                INSERT INTO remote_entitlements
                    (company_id, plan_code, max_monthly_sessions, max_session_duration_minutes,
                     external_guests, recording, file_transfer, advanced_audit, desktop_devices, unattended_access,
                     created_at, updated_at)
                SELECT NULL, 'REMOTE_PROFESSIONAL', NULL, 120, TRUE, FALSE, TRUE, TRUE, FALSE, FALSE, NOW(), NOW()
                WHERE NOT EXISTS (SELECT 1 FROM remote_entitlements WHERE company_id IS NULL)
                SQL,
        );
    }
}
