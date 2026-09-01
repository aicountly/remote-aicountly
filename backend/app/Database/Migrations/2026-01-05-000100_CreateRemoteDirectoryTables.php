<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Local projections of AICOUNTLY platform data.
 *
 * None of these tables is a master. AICOUNTLY owns identity (my.aicountly.com)
 * and the company/branch/financial-year masters (manage.aicountly.com); Remote
 * only caches the few fields it needs to render a session, resolve a permission
 * and keep an audit trail readable years later.
 *
 * `remote_identities` in particular is NOT an authentication store: it holds no
 * password, no credential and no session. It exists because the portal
 * identifies a user by UUID while Remote needs a stable integer to key a dozen
 * foreign keys on. Where the platform supplies its own numeric user id, that id
 * is used verbatim so the numbers line up across AICOUNTLY products.
 */
final class CreateRemoteDirectoryTables extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE TABLE remote_identities (
                id                BIGSERIAL    PRIMARY KEY,
                platform_uuid     VARCHAR(64)  NOT NULL UNIQUE,
                platform_user_id  BIGINT       NULL UNIQUE,
                display_name      VARCHAR(160) NOT NULL DEFAULT '',
                email             VARCHAR(190) NULL,
                is_support_agent  BOOLEAN      NOT NULL DEFAULT FALSE,
                is_platform_admin BOOLEAN      NOT NULL DEFAULT FALSE,
                last_seen_at      TIMESTAMPTZ  NULL,
                created_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            )
            SQL);
        $this->db->query('CREATE INDEX remote_identities_email_idx ON remote_identities (LOWER(email))');

        $this->db->query(<<<'SQL'
            CREATE TABLE remote_company_directory (
                company_id  BIGINT       PRIMARY KEY,
                name        VARCHAR(190) NOT NULL,
                short_name  VARCHAR(60)  NULL,
                timezone    VARCHAR(64)  NOT NULL DEFAULT 'UTC',
                locale      VARCHAR(16)  NOT NULL DEFAULT 'en-IN',
                synced_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            )
            SQL);

        // Which companies a user may act in, and with what platform role.
        // Populated from the platform directory API, from a verified source
        // context token, or by an administrator. It is a cache with a
        // `synced_at`, never the authority.
        $this->db->query(<<<'SQL'
            CREATE TABLE remote_user_company_access (
                id                 BIGSERIAL   PRIMARY KEY,
                user_id            BIGINT      NOT NULL REFERENCES remote_identities (id) ON DELETE CASCADE,
                company_id         BIGINT      NOT NULL,
                branch_id          BIGINT      NULL,
                financial_year_id  BIGINT      NULL,
                role_key           VARCHAR(64) NOT NULL DEFAULT 'MEMBER',
                is_company_admin   BOOLEAN     NOT NULL DEFAULT FALSE,
                source             VARCHAR(20) NOT NULL DEFAULT 'PORTAL',
                synced_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                created_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT remote_user_company_access_unique UNIQUE (user_id, company_id),
                CONSTRAINT remote_user_company_access_source_chk
                    CHECK (source IN ('PORTAL', 'CONTEXT_TOKEN', 'ADMIN', 'SEED'))
            )
            SQL);
        $this->db->query('CREATE INDEX remote_user_company_access_company_idx ON remote_user_company_access (company_id)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS remote_user_company_access');
        $this->db->query('DROP TABLE IF EXISTS remote_company_directory');
        $this->db->query('DROP TABLE IF EXISTS remote_identities');
    }
}
