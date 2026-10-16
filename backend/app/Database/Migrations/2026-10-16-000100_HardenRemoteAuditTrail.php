<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Brings `remote_audit_logs` up to the fleet audit-trail standard.
 *
 * The table already carried who/when/where (actor, company, IP, user agent,
 * request id). This adds what a reader needs without opening the metadata:
 * which record the row is about, a one-line summary, the severity, the actor's
 * uuid and name as they were at the time, plus indexes for the filters the
 * Audit trail screen offers.
 *
 * It also makes the table append-only. A row trigger refuses every UPDATE and
 * DELETE; nothing in the product does either. TRUNCATE is a statement rather
 * than a row change, so it is not refused — the test suite empties the table
 * with it between tests, and nothing else in the product truncates it.
 */
final class HardenRemoteAuditTrail extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
            ALTER TABLE remote_audit_logs
                ADD COLUMN IF NOT EXISTS actor_uuid   VARCHAR(64)  NULL,
                ADD COLUMN IF NOT EXISTS actor_label  VARCHAR(200) NULL,
                ADD COLUMN IF NOT EXISTS entity_type  VARCHAR(40)  NULL,
                ADD COLUMN IF NOT EXISTS entity_id    VARCHAR(64)  NULL,
                ADD COLUMN IF NOT EXISTS entity_label VARCHAR(200) NULL,
                ADD COLUMN IF NOT EXISTS summary      TEXT         NULL,
                ADD COLUMN IF NOT EXISTS severity     VARCHAR(12)  NOT NULL DEFAULT 'info'
            SQL);
        $this->db->query(<<<'SQL'
            ALTER TABLE remote_audit_logs
                ADD CONSTRAINT remote_audit_logs_severity_chk CHECK (severity IN ('info', 'material', 'security'))
            SQL);

        // Rows written before this migration: give them the same shape as new
        // ones. Runs before the trigger below exists, which is the only time
        // an UPDATE of this table is ever legitimate.
        $this->db->query(<<<'SQL'
            UPDATE remote_audit_logs a
               SET actor_uuid  = i.platform_uuid,
                   actor_label = NULLIF(i.display_name, '')
              FROM remote_identities i
             WHERE i.id = a.actor_user_id
            SQL);
        $this->db->query(<<<'SQL'
            UPDATE remote_audit_logs
               SET entity_type = CASE WHEN session_uuid IS NOT NULL THEN 'session'
                                      WHEN metadata->>'deviceUuid' IS NOT NULL THEN 'device' END,
                   entity_id   = CASE WHEN session_uuid IS NOT NULL THEN session_uuid::text
                                      WHEN metadata->>'deviceUuid' IS NOT NULL THEN metadata->>'deviceUuid' END,
                   summary     = INITCAP(REPLACE(LOWER(event), '_', ' ')),
                   severity    = CASE
                       WHEN event IN ('POLICY_UPDATED', 'PERMISSION_UPDATED', 'DEVICE_ENROLLED', 'DEVICE_REVOKED',
                                      'DEVICE_AUTH_FAILED', 'UNATTENDED_ACCESS_ENABLED', 'UNATTENDED_ACCESS_DISABLED',
                                      'COMPANY_CONTEXT_MISMATCH', 'POLICY_REJECTED', 'CONTROL_GRANTED')
                            THEN 'security'
                       WHEN event IN ('SESSION_CREATED', 'SESSION_ENDED', 'INVITATION_CREATED', 'INVITATION_REVOKED',
                                      'PARTICIPANT_APPROVED', 'PARTICIPANT_DENIED', 'DEVICE_UPDATED')
                            THEN 'material'
                       ELSE 'info' END
            SQL);

        $this->db->query('CREATE INDEX IF NOT EXISTS remote_audit_logs_entity_idx ON remote_audit_logs (company_id, entity_type, entity_id, created_at DESC)');
        $this->db->query('CREATE INDEX IF NOT EXISTS remote_audit_logs_company_event_idx ON remote_audit_logs (company_id, event, created_at DESC)');
        $this->db->query('CREATE INDEX IF NOT EXISTS remote_audit_logs_company_actor_idx ON remote_audit_logs (company_id, actor_user_id, created_at DESC)');

        $this->db->query(<<<'SQL'
            CREATE OR REPLACE FUNCTION remote_audit_logs_refuse_change() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'remote_audit_logs is append-only: % is not permitted', TG_OP
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$ LANGUAGE plpgsql
            SQL);
        $this->db->query(<<<'SQL'
            CREATE TRIGGER remote_audit_logs_append_only
                BEFORE UPDATE OR DELETE ON remote_audit_logs
                FOR EACH ROW EXECUTE FUNCTION remote_audit_logs_refuse_change()
            SQL);
    }

    public function down(): void
    {
        $this->db->query('DROP TRIGGER IF EXISTS remote_audit_logs_append_only ON remote_audit_logs');
        $this->db->query('DROP FUNCTION IF EXISTS remote_audit_logs_refuse_change()');
        $this->db->query('DROP INDEX IF EXISTS remote_audit_logs_entity_idx');
        $this->db->query('DROP INDEX IF EXISTS remote_audit_logs_company_event_idx');
        $this->db->query('DROP INDEX IF EXISTS remote_audit_logs_company_actor_idx');
        $this->db->query('ALTER TABLE remote_audit_logs DROP CONSTRAINT IF EXISTS remote_audit_logs_severity_chk');
        $this->db->query(<<<'SQL'
            ALTER TABLE remote_audit_logs
                DROP COLUMN IF EXISTS actor_uuid,
                DROP COLUMN IF EXISTS actor_label,
                DROP COLUMN IF EXISTS entity_type,
                DROP COLUMN IF EXISTS entity_id,
                DROP COLUMN IF EXISTS entity_label,
                DROP COLUMN IF EXISTS summary,
                DROP COLUMN IF EXISTS severity
            SQL);
    }
}
