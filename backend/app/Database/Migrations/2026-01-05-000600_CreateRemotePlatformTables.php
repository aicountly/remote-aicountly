<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Cross-cutting platform tables.
 *
 * `remote_context_tokens` gives the source-context token (§6C) its replay
 * protection: a `jti` may be consumed exactly once, enforced by a unique index
 * rather than by application logic, so two simultaneous launches with the same
 * token cannot both succeed.
 *
 * `remote_devices` is future-facing (§52). No desktop-agent functionality
 * exists in V1 and nothing writes to this table yet — it is here so the agent
 * work does not begin with a schema migration against live session data.
 */
final class CreateRemotePlatformTables extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE TABLE remote_context_tokens (
                id              BIGSERIAL   PRIMARY KEY,
                jti             VARCHAR(64) NOT NULL UNIQUE,
                issuer          VARCHAR(190) NOT NULL,
                audience        VARCHAR(190) NOT NULL,
                subject_user_id BIGINT      NULL,
                company_id      BIGINT      NULL,
                source_product  VARCHAR(40) NULL,
                consumed_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                expires_at      TIMESTAMPTZ NOT NULL,
                created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL);
        // Consumed jtis are only interesting until the token they belong to
        // would have expired anyway; this index drives the cleanup sweep.
        $this->db->query('CREATE INDEX remote_context_tokens_expiry_idx ON remote_context_tokens (expires_at)');

        $this->db->query(<<<'SQL'
            CREATE TABLE remote_devices (
                id                        BIGSERIAL    PRIMARY KEY,
                uuid                      UUID         NOT NULL UNIQUE,
                company_id                BIGINT       NULL,
                user_id                   BIGINT       NULL REFERENCES remote_identities (id) ON DELETE SET NULL,
                device_name               VARCHAR(160) NOT NULL,
                device_type               VARCHAR(24)  NOT NULL DEFAULT 'DESKTOP',
                operating_system          VARCHAR(60)  NULL,
                agent_version             VARCHAR(32)  NULL,
                public_key                TEXT         NULL,
                status                    VARCHAR(16)  NOT NULL DEFAULT 'PENDING',
                capabilities              JSONB        NOT NULL DEFAULT '{}'::jsonb,
                last_seen_at              TIMESTAMPTZ  NULL,
                unattended_access_enabled BOOLEAN      NOT NULL DEFAULT FALSE,
                created_at                TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at                TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                CONSTRAINT remote_devices_type_chk
                    CHECK (device_type IN ('DESKTOP', 'LAPTOP', 'SERVER', 'MOBILE')),
                CONSTRAINT remote_devices_status_chk
                    CHECK (status IN ('PENDING', 'ACTIVE', 'SUSPENDED', 'REVOKED'))
            )
            SQL);
        $this->db->query('CREATE INDEX remote_devices_company_idx ON remote_devices (company_id)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS remote_devices');
        $this->db->query('DROP TABLE IF EXISTS remote_context_tokens');
    }
}
