<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A device belongs to a person, and may belong to an organisation.
 *
 * `remote_devices.company_id` has always been nullable; a later migration
 * added `remote_devices_company_required_chk` to forbid the null, on the
 * reasoning that "a device with no company could not be governed by any
 * company's policy". That reasoning held only while policy was the sole
 * authority over a device. It is not: a device is enrolled by a person who
 * holds `remote.device.enrol`, it is reached through sessions that carry
 * their own scope, and what it may do is the intersection of its declaration,
 * the machine's permissions and an entitlement — none of which needs a tenant
 * to exist.
 *
 * What the constraint did produce was a product where a person could install
 * the agent, sign in, and then be told they had no permission to register the
 * computer in front of them, because every organisation they belonged to
 * lacked `remote_entitlements.desktop_devices`. That is the opposite of what
 * a remote-support tool is for.
 *
 * So the constraint goes, and a personal device becomes a device like any
 * other: `company_id` null, `user_id` its owner. Nothing else in the schema
 * changes — the column was already nullable, `CompanyLifecycle` already
 * reasons about `company_id IS NULL` devices when purging a person's data,
 * and every index over `company_id` is a plain b-tree that stores nulls
 * perfectly well.
 *
 * Reversible: `down()` puts the constraint back, which succeeds only while no
 * personal device exists. That is the honest behaviour — a rollback that
 * silently deleted somebody's registered computer would be worse than one
 * that refuses.
 */
class LetADeviceBelongToAPerson extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE remote_devices DROP CONSTRAINT IF EXISTS remote_devices_company_required_chk');

        // The owner is how a personal device is found, and until now the
        // indexed lookup was the company one. A person may register more than
        // one computer, so this is not unique.
        $this->db->query('CREATE INDEX IF NOT EXISTS remote_devices_user_status_idx ON remote_devices (user_id, status)');

        // Basic, for everybody. `desktop_devices` is the switch that decides
        // whether a person may register the computer in front of them, and the
        // platform-wide default row has carried it off since it was seeded —
        // so every organisation refused, and so did personal scope once it
        // could hold a device at all. Only the default row is touched: a
        // company that was given its own entitlement keeps exactly what it was
        // given.
        $this->db->query('UPDATE remote_entitlements SET desktop_devices = TRUE, updated_at = NOW() WHERE company_id IS NULL AND desktop_devices = FALSE');
    }

    public function down(): void
    {
        // The entitlement is deliberately not put back: `down()` cannot tell a
        // row this migration changed from one somebody has since decided on,
        // and turning a licence off underneath a working installation is worse
        // than leaving it on.
        $this->db->query('DROP INDEX IF EXISTS remote_devices_user_status_idx');

        $this->db->query(<<<'SQL'
            ALTER TABLE remote_devices
                ADD CONSTRAINT remote_devices_company_required_chk
                    CHECK (company_id IS NOT NULL)
            SQL);
    }
}
