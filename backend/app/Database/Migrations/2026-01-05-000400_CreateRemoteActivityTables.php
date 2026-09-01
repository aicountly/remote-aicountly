<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * What happened during a session: events, audit, chat, transfers, recordings.
 *
 * `remote_session_events` and `remote_audit_logs` are deliberately separate
 * (§60). Events are the session's own timeline, shown to anyone who can see
 * the session. Audit rows are the security record: they outlive the session,
 * carry actor/IP context, and are readable only with `remote.audit.view`.
 *
 * Neither ever stores screen content. Chat is stored under its own retention
 * rules in `remote_messages`, and is not copied into the audit trail.
 */
final class CreateRemoteActivityTables extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE TABLE remote_session_events (
                id             BIGSERIAL   PRIMARY KEY,
                session_id     BIGINT      NOT NULL REFERENCES remote_sessions (id) ON DELETE CASCADE,
                participant_id BIGINT      NULL REFERENCES remote_participants (id) ON DELETE SET NULL,
                event_type     VARCHAR(48) NOT NULL,
                actor_user_id  BIGINT      NULL,
                actor_type     VARCHAR(20) NOT NULL DEFAULT 'USER',
                metadata       JSONB       NOT NULL DEFAULT '{}'::jsonb,
                occurred_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT remote_session_events_actor_chk
                    CHECK (actor_type IN ('USER', 'GUEST', 'SUPPORT', 'SYSTEM'))
            )
            SQL);
        $this->db->query('CREATE INDEX remote_session_events_session_idx ON remote_session_events (session_id, occurred_at)');
        $this->db->query('CREATE INDEX remote_session_events_type_idx ON remote_session_events (event_type, occurred_at DESC)');

        $this->db->query(<<<'SQL'
            CREATE TABLE remote_audit_logs (
                id               BIGSERIAL   PRIMARY KEY,
                uuid             UUID        NOT NULL UNIQUE,
                event            VARCHAR(64) NOT NULL,
                actor_user_id    BIGINT      NULL,
                actor_type       VARCHAR(20) NOT NULL DEFAULT 'USER',
                company_id       BIGINT      NULL,
                session_uuid     UUID        NULL,
                participant_uuid UUID        NULL,
                source_product   VARCHAR(40) NULL,
                ip               INET        NULL,
                user_agent       TEXT        NULL,
                request_id       VARCHAR(64) NULL,
                metadata         JSONB       NOT NULL DEFAULT '{}'::jsonb,
                created_at       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT remote_audit_logs_actor_chk
                    CHECK (actor_type IN ('USER', 'GUEST', 'SUPPORT', 'SYSTEM'))
            )
            SQL);
        $this->db->query('CREATE INDEX remote_audit_logs_company_idx ON remote_audit_logs (company_id, created_at DESC)');
        $this->db->query('CREATE INDEX remote_audit_logs_session_idx ON remote_audit_logs (session_uuid)');
        $this->db->query('CREATE INDEX remote_audit_logs_actor_idx ON remote_audit_logs (actor_user_id, created_at DESC)');
        $this->db->query('CREATE INDEX remote_audit_logs_event_idx ON remote_audit_logs (event, created_at DESC)');

        // --- Chat (§35) ------------------------------------------------------
        // Messages travel over the data channel; this table is the durable copy
        // kept for the session record, and the relay path when the data channel
        // is not up yet.
        $this->db->query(<<<'SQL'
            CREATE TABLE remote_messages (
                id             BIGSERIAL    PRIMARY KEY,
                uuid           UUID         NOT NULL UNIQUE,
                session_id     BIGINT       NOT NULL REFERENCES remote_sessions (id) ON DELETE CASCADE,
                participant_id BIGINT       NULL REFERENCES remote_participants (id) ON DELETE SET NULL,
                author_user_id BIGINT       NULL,
                author_name    VARCHAR(160) NOT NULL,
                message_type   VARCHAR(16)  NOT NULL DEFAULT 'USER',
                body           TEXT         NOT NULL,
                delivered_via  VARCHAR(16)  NOT NULL DEFAULT 'DATA_CHANNEL',
                created_at     TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                CONSTRAINT remote_messages_type_chk CHECK (message_type IN ('USER', 'SYSTEM')),
                CONSTRAINT remote_messages_via_chk CHECK (delivered_via IN ('DATA_CHANNEL', 'RELAY')),
                CONSTRAINT remote_messages_body_chk CHECK (char_length(body) BETWEEN 1 AND 4000)
            )
            SQL);
        $this->db->query('CREATE INDEX remote_messages_session_idx ON remote_messages (session_id, created_at)');

        // --- File transfer (§36) ---------------------------------------------
        // The bytes never touch this server; they move peer-to-peer over the
        // data channel. This is the ledger of what was offered and accepted.
        $this->db->query(<<<'SQL'
            CREATE TABLE remote_file_transfers (
                id                  BIGSERIAL    PRIMARY KEY,
                uuid                UUID         NOT NULL UNIQUE,
                session_id          BIGINT       NOT NULL REFERENCES remote_sessions (id) ON DELETE CASCADE,
                from_participant_id BIGINT       NULL REFERENCES remote_participants (id) ON DELETE SET NULL,
                to_participant_id   BIGINT       NULL REFERENCES remote_participants (id) ON DELETE SET NULL,
                file_name           VARCHAR(255) NOT NULL,
                file_size           BIGINT       NOT NULL,
                mime_type           VARCHAR(160) NULL,
                status              VARCHAR(16)  NOT NULL DEFAULT 'OFFERED',
                bytes_transferred   BIGINT       NOT NULL DEFAULT 0,
                error_code          VARCHAR(40)  NULL,
                started_at          TIMESTAMPTZ  NULL,
                completed_at        TIMESTAMPTZ  NULL,
                created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                CONSTRAINT remote_file_transfers_status_chk
                    CHECK (status IN ('OFFERED', 'ACCEPTED', 'DECLINED', 'IN_PROGRESS', 'COMPLETED', 'FAILED', 'CANCELLED')),
                CONSTRAINT remote_file_transfers_size_chk CHECK (file_size >= 0)
            )
            SQL);
        $this->db->query('CREATE INDEX remote_file_transfers_session_idx ON remote_file_transfers (session_id, created_at)');

        // --- Recording (§38) -------------------------------------------------
        // Schema and consent model exist so the capability can be switched on
        // without a migration. No recording is produced in V1.
        $this->db->query(<<<'SQL'
            CREATE TABLE remote_recordings (
                id                  BIGSERIAL   PRIMARY KEY,
                uuid                UUID        NOT NULL UNIQUE,
                session_id          BIGINT      NOT NULL REFERENCES remote_sessions (id) ON DELETE CASCADE,
                started_by_user_id  BIGINT      NULL,
                consent_state       VARCHAR(16) NOT NULL DEFAULT 'PENDING',
                consent_recorded_at TIMESTAMPTZ NULL,
                status              VARCHAR(16) NOT NULL DEFAULT 'REQUESTED',
                storage_key         VARCHAR(255) NULL,
                duration_seconds    INTEGER     NULL,
                size_bytes          BIGINT      NULL,
                started_at          TIMESTAMPTZ NULL,
                stopped_at          TIMESTAMPTZ NULL,
                retention_until     TIMESTAMPTZ NULL,
                created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT remote_recordings_consent_chk
                    CHECK (consent_state IN ('PENDING', 'GRANTED', 'DENIED', 'NOT_REQUIRED')),
                CONSTRAINT remote_recordings_status_chk
                    CHECK (status IN ('REQUESTED', 'RECORDING', 'COMPLETED', 'FAILED', 'DELETED')),
                -- Capture may only begin once consent is settled (§38).
                CONSTRAINT remote_recordings_consent_before_capture_chk
                    CHECK (status IN ('REQUESTED', 'FAILED', 'DELETED')
                           OR consent_state IN ('GRANTED', 'NOT_REQUIRED'))
            )
            SQL);
        $this->db->query('CREATE INDEX remote_recordings_session_idx ON remote_recordings (session_id)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS remote_recordings');
        $this->db->query('DROP TABLE IF EXISTS remote_file_transfers');
        $this->db->query('DROP TABLE IF EXISTS remote_messages');
        $this->db->query('DROP TABLE IF EXISTS remote_audit_logs');
        $this->db->query('DROP TABLE IF EXISTS remote_session_events');
    }
}
