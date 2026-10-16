<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Manage's company lifecycle (lib/company-lifecycle, docs/COMPANY_LIFECYCLE.md): the receiver kit's four
 * tables — aic_lifecycle_events, _state, _jobs, _acks.
 *
 * The SQL is the kit's schema.sql, copied unchanged (kit 1.0.0) to
 * app/Database/Sql/2026-10-14-000100_aic_company_lifecycle.sql and run as one script: idempotent
 * (IF NOT EXISTS) and additive — it touches no remote_* table.
 */
final class CreateAicCompanyLifecycleTables extends Migration
{
    public function up(): void
    {
        $sql = (string) file_get_contents(APPPATH . 'Database/Sql/2026-10-14-000100_aic_company_lifecycle.sql');
        // pg_query runs a multi-statement script; CodeIgniter's Postgre driver hands simpleQuery() to it.
        $this->db->simpleQuery($sql);
        if (! $this->db->tableExists('aic_lifecycle_acks', false)) {
            throw new \RuntimeException('aic_lifecycle_* tables were not created: ' . json_encode($this->db->error()));
        }
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS aic_lifecycle_acks, aic_lifecycle_jobs, aic_lifecycle_state, aic_lifecycle_events');
    }
}
