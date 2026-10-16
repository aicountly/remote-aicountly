<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The company-lifecycle purge worker may delete a purged company's audit rows (docs/COMPANY_LIFECYCLE.md).
 *
 * 2026-10-16-000100_HardenRemoteAuditTrail made remote_audit_logs append-only: its row trigger refuses every
 * UPDATE and DELETE. The reviewed purge manifest (config/lifecycle-manifest.php) lists remote_audit_logs in
 * company_tables — no statute keeps Remote's own audit log — so a company purge archives the company's rows
 * and then deletes them. This lets exactly that through: a DELETE in a session that says it is the purge
 * worker (`aicountly.lifecycle_purge = on`, set only on the kit's own connection when it runs from the
 * command line: App\Libraries\CompanyLifecycle::build()). UPDATE is still always refused, and so is DELETE
 * from any other session, the web API's included.
 */
final class LetTheLifecyclePurgeDeleteRemoteAuditLogs extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE OR REPLACE FUNCTION remote_audit_logs_refuse_change() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' AND current_setting('aicountly.lifecycle_purge', true) = 'on' THEN
                    RETURN OLD;
                END IF;
                RAISE EXCEPTION 'remote_audit_logs is append-only: % is not permitted', TG_OP
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$ LANGUAGE plpgsql
            SQL);
    }

    public function down(): void
    {
        // Back to 2026-10-16-000100's function: every UPDATE and DELETE refused.
        $this->db->query(<<<'SQL'
            CREATE OR REPLACE FUNCTION remote_audit_logs_refuse_change() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'remote_audit_logs is append-only: % is not permitted', TG_OP
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$ LANGUAGE plpgsql
            SQL);
    }
}
