<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The policy layer: entitlement → company policy → role → user.
 *
 * Evaluation order and the "most restrictive rule wins" rule live in
 * App\Domain\Policy\EffectivePolicyResolver. These tables only store the
 * inputs; nothing here is consulted by the frontend directly.
 */
final class CreateRemotePolicyTables extends Migration
{
    public function up(): void
    {
        // --- Company policy (§7) -------------------------------------------
        // Defaults on every column are the conservative preset from §8:
        // Safe Share / tab / window on, entire monitor off, guests off,
        // recording off.
        $this->db->query(<<<'SQL'
            CREATE TABLE remote_company_policies (
                id                           BIGSERIAL   PRIMARY KEY,
                company_id                   BIGINT      NOT NULL UNIQUE,
                remote_enabled               BOOLEAN     NOT NULL DEFAULT TRUE,
                policy_preset                VARCHAR(16) NOT NULL DEFAULT 'STANDARD',

                allow_safe_share             BOOLEAN     NOT NULL DEFAULT TRUE,
                allow_browser_tab            BOOLEAN     NOT NULL DEFAULT TRUE,
                allow_application_window     BOOLEAN     NOT NULL DEFAULT TRUE,
                allow_entire_monitor         BOOLEAN     NOT NULL DEFAULT FALSE,

                allow_microphone             BOOLEAN     NOT NULL DEFAULT TRUE,
                allow_system_audio           BOOLEAN     NOT NULL DEFAULT FALSE,
                allow_text_chat              BOOLEAN     NOT NULL DEFAULT TRUE,
                allow_annotation             BOOLEAN     NOT NULL DEFAULT TRUE,
                allow_file_transfer          BOOLEAN     NOT NULL DEFAULT FALSE,

                allow_external_guest         BOOLEAN     NOT NULL DEFAULT FALSE,
                allow_internal_sessions      BOOLEAN     NOT NULL DEFAULT TRUE,
                allow_aicountly_support      BOOLEAN     NOT NULL DEFAULT TRUE,

                allow_recording              BOOLEAN     NOT NULL DEFAULT FALSE,
                recording_requires_consent   BOOLEAN     NOT NULL DEFAULT TRUE,

                max_session_duration_minutes INTEGER     NOT NULL DEFAULT 60,
                guest_link_expiry_minutes    INTEGER     NOT NULL DEFAULT 10,

                created_by                   BIGINT      NULL,
                updated_by                   BIGINT      NULL,
                created_at                   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at                   TIMESTAMPTZ NOT NULL DEFAULT NOW(),

                CONSTRAINT remote_company_policies_preset_chk
                    CHECK (policy_preset IN ('RESTRICTED', 'SAFE', 'STANDARD', 'OPEN', 'CUSTOM')),
                CONSTRAINT remote_company_policies_duration_chk
                    CHECK (max_session_duration_minutes BETWEEN 5 AND 1440),
                CONSTRAINT remote_company_policies_invite_expiry_chk
                    CHECK (guest_link_expiry_minutes BETWEEN 1 AND 1440),
                -- A disabled product cannot simultaneously permit sharing:
                -- turning Remote off is the one switch that means it.
                CONSTRAINT remote_company_policies_disabled_chk
                    CHECK (
                        remote_enabled
                        OR NOT (allow_safe_share OR allow_browser_tab
                                OR allow_application_window OR allow_entire_monitor)
                    )
            )
            SQL);

        // --- Entitlements (§79) --------------------------------------------
        // One row per company; the row with company_id IS NULL is the platform
        // default that applies to every company without its own.
        $this->db->query(<<<'SQL'
            CREATE TABLE remote_entitlements (
                id                           BIGSERIAL   PRIMARY KEY,
                company_id                   BIGINT      NULL,
                plan_code                    VARCHAR(32) NOT NULL DEFAULT 'REMOTE_FREE',
                max_monthly_sessions         INTEGER     NULL,
                max_session_duration_minutes INTEGER     NULL,
                external_guests              BOOLEAN     NOT NULL DEFAULT FALSE,
                recording                    BOOLEAN     NOT NULL DEFAULT FALSE,
                file_transfer                BOOLEAN     NOT NULL DEFAULT TRUE,
                advanced_audit               BOOLEAN     NOT NULL DEFAULT FALSE,
                desktop_devices              BOOLEAN     NOT NULL DEFAULT FALSE,
                unattended_access            BOOLEAN     NOT NULL DEFAULT FALSE,
                valid_from                   TIMESTAMPTZ NULL,
                valid_until                  TIMESTAMPTZ NULL,
                created_at                   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at                   TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL);
        // Postgres treats NULLs as distinct in a UNIQUE constraint, so the
        // platform-default row needs a partial index of its own to stay single.
        $this->db->query('CREATE UNIQUE INDEX remote_entitlements_company_uniq ON remote_entitlements (company_id) WHERE company_id IS NOT NULL');
        $this->db->query('CREATE UNIQUE INDEX remote_entitlements_default_uniq ON remote_entitlements ((TRUE)) WHERE company_id IS NULL');

        // --- Role permissions (§10) ----------------------------------------
        // company_id NULL = the platform-wide default grant for that role key.
        $this->db->query(<<<'SQL'
            CREATE TABLE remote_role_permissions (
                id         BIGSERIAL   PRIMARY KEY,
                company_id BIGINT      NULL,
                role_key   VARCHAR(64) NOT NULL,
                permission VARCHAR(64) NOT NULL,
                effect     VARCHAR(8)  NOT NULL DEFAULT 'ALLOW',
                created_by BIGINT      NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT remote_role_permissions_effect_chk CHECK (effect IN ('ALLOW', 'DENY'))
            )
            SQL);
        $this->db->query('CREATE UNIQUE INDEX remote_role_permissions_uniq ON remote_role_permissions (COALESCE(company_id, 0), role_key, permission)');

        // --- User permissions (§11) ----------------------------------------
        // A grant here can only narrow what the company policy already allows;
        // the resolver never lets an ALLOW here outvote a company prohibition.
        $this->db->query(<<<'SQL'
            CREATE TABLE remote_user_permissions (
                id         BIGSERIAL   PRIMARY KEY,
                company_id BIGINT      NULL,
                user_id    BIGINT      NOT NULL REFERENCES remote_identities (id) ON DELETE CASCADE,
                permission VARCHAR(64) NOT NULL,
                effect     VARCHAR(8)  NOT NULL DEFAULT 'ALLOW',
                created_by BIGINT      NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT remote_user_permissions_effect_chk CHECK (effect IN ('ALLOW', 'DENY'))
            )
            SQL);
        $this->db->query('CREATE UNIQUE INDEX remote_user_permissions_uniq ON remote_user_permissions (COALESCE(company_id, 0), user_id, permission)');
        $this->db->query('CREATE INDEX remote_user_permissions_user_idx ON remote_user_permissions (user_id)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS remote_user_permissions');
        $this->db->query('DROP TABLE IF EXISTS remote_role_permissions');
        $this->db->query('DROP TABLE IF EXISTS remote_entitlements');
        $this->db->query('DROP TABLE IF EXISTS remote_company_policies');
    }
}
