<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Put back remote_invitations.redeemed_participant_id → remote_participants (docs/COMPANY_LIFECYCLE.md).
 *
 * Migration 2026-10-14-000200 dropped this constraint only because the company-lifecycle kit 1.0 refused to
 * purge a company whose tables form a foreign-key cycle (participants.invitation_id → invitations, and this
 * one back). Kit 1.1 handles cycles, so the integrity it gave comes back, with the same ON DELETE SET NULL
 * the application relies on, and DEFERRABLE (INITIALLY IMMEDIATE, so the application sees no difference):
 * the purge lists the column in break_cycle (it is NULLed on the purged invitations after they are archived,
 * and a restore puts it back), and a restore of rows an account hook archived runs with the constraint
 * deferred, so the two tables can be put back in either order.
 *
 * A back-reference written while the constraint was absent may name a participant that has since gone: it
 * is cleared first, exactly as ON DELETE SET NULL would have done. The index from 2026-10-14-000200 stays
 * (the SET NULL lookup uses it).
 */
final class RestoreInvitationRedeemedParticipantForeignKey extends Migration
{
    public function up(): void
    {
        $this->db->query('UPDATE remote_invitations i SET redeemed_participant_id = NULL
            WHERE i.redeemed_participant_id IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM remote_participants p WHERE p.id = i.redeemed_participant_id)');
        $this->db->query('ALTER TABLE remote_invitations DROP CONSTRAINT IF EXISTS remote_invitations_redeemed_participant_id_fkey');
        $this->db->query('ALTER TABLE remote_invitations ADD CONSTRAINT remote_invitations_redeemed_participant_id_fkey
            FOREIGN KEY (redeemed_participant_id) REFERENCES remote_participants (id) ON DELETE SET NULL
            DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE remote_invitations DROP CONSTRAINT IF EXISTS remote_invitations_redeemed_participant_id_fkey');
    }
}
