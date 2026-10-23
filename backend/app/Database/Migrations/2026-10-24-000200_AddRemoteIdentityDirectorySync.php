<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * When Remote last read a person's companies from AICOUNTLY Manage's live directory (finding F-2).
 *
 * `remote_user_company_access` used to learn a company only from a launch token, a directory API that
 * production never configured, or an administrator — so a company Manage gave the person (a share, an
 * approved request, a workspace owner's "every company" access) did not appear in Remote until the person
 * happened to launch Remote from another product there. MembershipVerifier::reconcileUser now reads
 * Manage's list (`GET /api/companies?filter=all&app=remote`, the person's own session) at most once per
 * `membershipVerifySeconds` and adds what it lists; this column is that "at most once".
 *
 *   directory_synced_at   the last successful read of Manage's list for this person; NULL = never
 *
 * Additive and nullable: NULL simply means "read it on the next company picker".
 */
final class AddRemoteIdentityDirectorySync extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE remote_identities ADD COLUMN IF NOT EXISTS directory_synced_at TIMESTAMPTZ NULL');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE remote_identities DROP COLUMN IF EXISTS directory_synced_at');
    }
}
