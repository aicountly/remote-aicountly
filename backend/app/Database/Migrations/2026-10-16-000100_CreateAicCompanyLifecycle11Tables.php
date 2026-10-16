<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Manage's company lifecycle, kit 1.1 (lib/company-lifecycle, docs/COMPANY_LIFECYCLE.md): the kit's
 * schema-1.1.sql — aic_lifecycle_expiries, and jobs may be `held`.
 *
 * The SQL is copied unchanged (kit 1.1.0) to app/Database/Sql/2026-10-16-000100_aic_company_lifecycle_1_1.sql
 * and run as one script: idempotent and additive — it touches no remote_* table and no 1.0 row.
 */
final class CreateAicCompanyLifecycle11Tables extends Migration
{
    public function up(): void
    {
        $sql = (string) file_get_contents(APPPATH . 'Database/Sql/2026-10-16-000100_aic_company_lifecycle_1_1.sql');
        // pg_query runs a multi-statement script; CodeIgniter's Postgre driver hands simpleQuery() to it.
        $this->db->simpleQuery($sql);
        if (! $this->db->tableExists('aic_lifecycle_expiries', false)) {
            throw new \RuntimeException('aic_lifecycle_expiries was not created: ' . json_encode($this->db->error()));
        }
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS aic_lifecycle_expiries');
        $this->db->query("ALTER TABLE aic_lifecycle_jobs DROP CONSTRAINT IF EXISTS aic_lifecycle_jobs_status_chk");
        $this->db->query("ALTER TABLE aic_lifecycle_jobs ADD CONSTRAINT aic_lifecycle_jobs_status_chk
            CHECK (status IN ('pending', 'running', 'purged', 'nothing_to_purge', 'failed'))");
    }
}
