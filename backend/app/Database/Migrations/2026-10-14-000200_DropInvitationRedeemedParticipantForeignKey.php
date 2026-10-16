<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Break the one foreign-key cycle in Remote's schema, so a company purge can run (docs/COMPANY_LIFECYCLE.md).
 *
 * remote_participants.invitation_id → remote_invitations and remote_invitations.redeemed_participant_id →
 * remote_participants (both ON DELETE SET NULL) form a cycle, and the company-lifecycle kit deletes in a
 * fixed children-before-parents order it checks against every foreign key: with a cycle no order passes,
 * so it refuses to purge a company at all. The second constraint is the redundant one —
 * redeemed_participant_id is a back-reference written once, when an invitation is redeemed
 * (InvitationService::redeem), and both rows always live and die with their session (ON DELETE CASCADE
 * from remote_sessions). The column and its meaning stay; only the constraint goes, and an index keeps
 * the lookup fast.
 */
final class DropInvitationRedeemedParticipantForeignKey extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE remote_invitations DROP CONSTRAINT IF EXISTS remote_invitations_redeemed_participant_id_fkey');
        $this->db->query('CREATE INDEX IF NOT EXISTS remote_invitations_redeemed_participant_idx ON remote_invitations (redeemed_participant_id) WHERE redeemed_participant_id IS NOT NULL');
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX IF EXISTS remote_invitations_redeemed_participant_idx');
        $this->db->query('ALTER TABLE remote_invitations ADD CONSTRAINT remote_invitations_redeemed_participant_id_fkey
            FOREIGN KEY (redeemed_participant_id) REFERENCES remote_participants (id) ON DELETE SET NULL');
    }
}
