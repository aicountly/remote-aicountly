<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * When Aicountly Manage last confirmed a person still belongs to a company.
 *
 * `remote_user_company_access` was written by a launch token, a directory sync
 * or a seed, and never taken back: `synced_at` meant "last written", not "last
 * confirmed", so a person removed from a company in Manage kept their access
 * here for ever (G28#4). `verified_at` is the confirmation: set when Manage
 * answered for that person's own session, read to decide whether a row may be
 * relied on. NULL means "recorded, never confirmed" — a launch-token hint, or a
 * row from before this column existed — and is honoured only once confirmed.
 */
final class AddRemoteMembershipVerification extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE remote_user_company_access ADD COLUMN IF NOT EXISTS verified_at TIMESTAMPTZ NULL');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE remote_user_company_access DROP COLUMN IF EXISTS verified_at');
    }
}
