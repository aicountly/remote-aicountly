<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The company's plan for Remote, as AICOUNTLY Manage's companyinfo last carried it (CONTRACTS §3.5).
 *
 * Manage answers companyinfo (asked with app=remote, which Remote does whenever it confirms a person's
 * membership — MembershipVerifier) with `plan: {code, label, is_default, status, ends_at, entitlements}`.
 * Remote keeps that answer beside the company's name so its limits (remote.max_sessions_per_month,
 * remote.max_devices — App\Domain\Policy\PlanEntitlements) can be checked without asking again.
 *
 *   plan             the plan object as Manage sent it; NULL = Manage sent none (an older Manage): no limits
 *   plan_checked_at  when that answer came; NULL = never asked since this column existed
 *
 * A cache of Manage's answer, overwritten on the next one — nullable, so nothing changes until Manage
 * sends a plan.
 */
final class AddRemoteCompanyPlan extends Migration
{
    public function up(): void
    {
        $this->db->query('ALTER TABLE remote_company_directory ADD COLUMN IF NOT EXISTS plan JSONB NULL');
        $this->db->query('ALTER TABLE remote_company_directory ADD COLUMN IF NOT EXISTS plan_checked_at TIMESTAMPTZ NULL');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE remote_company_directory DROP COLUMN IF EXISTS plan_checked_at');
        $this->db->query('ALTER TABLE remote_company_directory DROP COLUMN IF EXISTS plan');
    }
}
