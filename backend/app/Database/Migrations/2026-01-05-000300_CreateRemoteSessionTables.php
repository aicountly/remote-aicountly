<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Sessions, participants and invitations — the core of the product.
 *
 * Two rules are enforced by the database rather than only by code, because
 * they are the ones a bug would leak a tenant through:
 *
 *   * a COMPANY or AICOUNTLY_SUPPORT session must carry its company, and a
 *     PERSONAL session must carry none (§5);
 *   * an invitation's secret is only ever stored as a SHA-256 hash, so a
 *     database read cannot be replayed into a live session (§6F).
 */
final class CreateRemoteSessionTables extends Migration
{
    public function up(): void
    {
        // Human-friendly display ids (AR-10282, §70). A sequence keeps them
        // dense and unguessable-adjacent without ever being a credential — the
        // UUID is the identifier, this is the label.
        $this->db->query('CREATE SEQUENCE remote_session_display_seq START WITH 10001 INCREMENT BY 1');

        $this->db->query(<<<'SQL'
            CREATE TABLE remote_sessions (
                id                       BIGSERIAL    PRIMARY KEY,
                uuid                     UUID         NOT NULL UNIQUE,
                display_id               VARCHAR(16)  NOT NULL UNIQUE,

                session_code             VARCHAR(9)   NULL,
                session_code_expires_at  TIMESTAMPTZ  NULL,

                scope_type               VARCHAR(20)  NOT NULL,
                company_id               BIGINT       NULL,
                branch_id                BIGINT       NULL,
                financial_year_id        BIGINT       NULL,

                initiator_user_id        BIGINT       NOT NULL REFERENCES remote_identities (id),
                owner_user_id            BIGINT       NOT NULL REFERENCES remote_identities (id),

                source_product           VARCHAR(40)  NULL,
                source_route             VARCHAR(255) NULL,
                source_reference         VARCHAR(120) NULL,
                source_agent             VARCHAR(40)  NULL,
                source_conversation_id   VARCHAR(120) NULL,
                issue_summary            TEXT         NULL,
                support_ticket_id        VARCHAR(64)  NULL,

                session_type             VARCHAR(24)  NOT NULL DEFAULT 'ASSISTANCE',
                status                   VARCHAR(20)  NOT NULL DEFAULT 'CREATED',

                requested_share_mode     VARCHAR(24)  NOT NULL DEFAULT 'SAFE_SHARE',
                actual_display_surface   VARCHAR(16)  NULL,

                allow_audio              BOOLEAN      NOT NULL DEFAULT FALSE,
                allow_system_audio       BOOLEAN      NOT NULL DEFAULT FALSE,
                allow_chat               BOOLEAN      NOT NULL DEFAULT TRUE,
                allow_annotation         BOOLEAN      NOT NULL DEFAULT TRUE,
                allow_file_transfer      BOOLEAN      NOT NULL DEFAULT FALSE,
                allow_recording          BOOLEAN      NOT NULL DEFAULT FALSE,
                allow_external_guest     BOOLEAN      NOT NULL DEFAULT FALSE,
                max_duration_minutes     INTEGER      NOT NULL DEFAULT 60,

                started_at               TIMESTAMPTZ  NULL,
                ended_at                 TIMESTAMPTZ  NULL,
                expires_at               TIMESTAMPTZ  NOT NULL,
                last_activity_at         TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

                ended_by_user_id         BIGINT       NULL,
                end_reason               VARCHAR(40)  NULL,

                created_ip               INET         NULL,
                created_user_agent       TEXT         NULL,

                created_at               TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at               TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

                CONSTRAINT remote_sessions_scope_chk
                    CHECK (scope_type IN ('PERSONAL', 'COMPANY', 'AICOUNTLY_SUPPORT')),
                CONSTRAINT remote_sessions_status_chk
                    CHECK (status IN ('CREATED', 'WAITING', 'JOIN_REQUESTED', 'CONNECTING', 'ACTIVE',
                                      'PAUSED', 'RECONNECTING', 'ENDED', 'DECLINED', 'EXPIRED', 'FAILED')),
                CONSTRAINT remote_sessions_type_chk
                    CHECK (session_type IN ('ASSISTANCE', 'SUPPORT', 'INTERNAL', 'GUEST_VIEW')),
                CONSTRAINT remote_sessions_share_mode_chk
                    CHECK (requested_share_mode IN ('SAFE_SHARE', 'BROWSER_TAB', 'APPLICATION_WINDOW', 'ENTIRE_MONITOR')),
                CONSTRAINT remote_sessions_surface_chk
                    CHECK (actual_display_surface IS NULL
                           OR actual_display_surface IN ('browser', 'window', 'monitor', 'unknown')),

                -- §5: a company session must know its company…
                CONSTRAINT remote_sessions_company_required_chk
                    CHECK (scope_type <> 'COMPANY' OR company_id IS NOT NULL),
                -- …and a personal session must carry no company context at all,
                -- so it can never become a back door into one (§13).
                CONSTRAINT remote_sessions_personal_null_chk
                    CHECK (scope_type <> 'PERSONAL'
                           OR (company_id IS NULL AND branch_id IS NULL AND financial_year_id IS NULL)),
                CONSTRAINT remote_sessions_branch_needs_company_chk
                    CHECK (branch_id IS NULL OR company_id IS NOT NULL),
                CONSTRAINT remote_sessions_code_chk
                    CHECK (session_code IS NULL OR session_code ~ '^[0-9]{9}$')
            )
            SQL);

        // A join code is only meaningful while the session can still be joined,
        // and it is cleared when the session ends — so uniqueness only has to
        // hold over live codes, and a retired code returns to the pool.
        $this->db->query('CREATE UNIQUE INDEX remote_sessions_code_uniq ON remote_sessions (session_code) WHERE session_code IS NOT NULL');
        $this->db->query('CREATE INDEX remote_sessions_company_idx ON remote_sessions (company_id, created_at DESC)');
        $this->db->query('CREATE INDEX remote_sessions_owner_idx ON remote_sessions (owner_user_id, created_at DESC)');
        $this->db->query('CREATE INDEX remote_sessions_status_idx ON remote_sessions (status) WHERE status NOT IN (\'ENDED\', \'EXPIRED\', \'FAILED\', \'DECLINED\')');
        $this->db->query('CREATE INDEX remote_sessions_support_ticket_idx ON remote_sessions (support_ticket_id) WHERE support_ticket_id IS NOT NULL');

        // --- Participants (§22) ---------------------------------------------
        $this->db->query(<<<'SQL'
            CREATE TABLE remote_participants (
                id                   BIGSERIAL    PRIMARY KEY,
                uuid                 UUID         NOT NULL UNIQUE,
                session_id           BIGINT       NOT NULL REFERENCES remote_sessions (id) ON DELETE CASCADE,
                user_id              BIGINT       NULL REFERENCES remote_identities (id),
                invitation_id        BIGINT       NULL,

                participant_role     VARCHAR(24)  NOT NULL,
                client_type          VARCHAR(20)  NOT NULL DEFAULT 'BROWSER',
                capabilities         JSONB        NOT NULL DEFAULT '{}'::jsonb,

                display_name         VARCHAR(160) NOT NULL,
                email                VARCHAR(190) NULL,

                status               VARCHAR(20)  NOT NULL DEFAULT 'REQUESTED',
                is_host              BOOLEAN      NOT NULL DEFAULT FALSE,
                approved_by_user_id  BIGINT       NULL,

                connection_state     VARCHAR(20)  NOT NULL DEFAULT 'IDLE',
                microphone_enabled   BOOLEAN      NOT NULL DEFAULT FALSE,
                is_sharing           BOOLEAN      NOT NULL DEFAULT FALSE,

                requested_at         TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                joined_at            TIMESTAMPTZ  NULL,
                left_at              TIMESTAMPTZ  NULL,
                last_seen_at         TIMESTAMPTZ  NULL,

                ip                   INET         NULL,
                user_agent           TEXT         NULL,
                browser_name         VARCHAR(60)  NULL,
                os_name              VARCHAR(60)  NULL,

                created_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

                CONSTRAINT remote_participants_role_chk
                    CHECK (participant_role IN ('SHARER', 'VIEWER', 'SUPPORT_TECHNICIAN', 'OBSERVER', 'GUEST')),
                CONSTRAINT remote_participants_client_chk
                    CHECK (client_type IN ('BROWSER', 'DESKTOP_AGENT', 'MOBILE')),
                CONSTRAINT remote_participants_status_chk
                    CHECK (status IN ('REQUESTED', 'APPROVED', 'DENIED', 'JOINED', 'LEFT', 'REMOVED')),
                CONSTRAINT remote_participants_connection_chk
                    CHECK (connection_state IN ('IDLE', 'CONNECTING', 'CONNECTED', 'INTERRUPTED', 'CLOSED')),
                -- A guest has no AICOUNTLY identity; everyone else must have one.
                CONSTRAINT remote_participants_identity_chk
                    CHECK ((participant_role = 'GUEST') = (user_id IS NULL))
            )
            SQL);
        $this->db->query('CREATE UNIQUE INDEX remote_participants_session_user_uniq ON remote_participants (session_id, user_id) WHERE user_id IS NOT NULL');
        $this->db->query('CREATE INDEX remote_participants_session_idx ON remote_participants (session_id)');
        $this->db->query('CREATE INDEX remote_participants_user_idx ON remote_participants (user_id, created_at DESC)');

        // --- Invitations (§6F) ----------------------------------------------
        $this->db->query(<<<'SQL'
            CREATE TABLE remote_invitations (
                id                     BIGSERIAL    PRIMARY KEY,
                uuid                   UUID         NOT NULL UNIQUE,
                session_id             BIGINT       NOT NULL REFERENCES remote_sessions (id) ON DELETE CASCADE,

                -- SHA-256 of the secret handed to the invitee. The secret
                -- itself is shown once, at creation, and never stored: a dump
                -- of this table cannot be turned back into a working link.
                token_hash             CHAR(64)     NOT NULL UNIQUE,

                invitation_type        VARCHAR(20)  NOT NULL DEFAULT 'INTERNAL',
                invitee_email          VARCHAR(190) NULL,
                created_by_user_id     BIGINT       NOT NULL REFERENCES remote_identities (id),

                max_uses               INTEGER      NOT NULL DEFAULT 1,
                used_count             INTEGER      NOT NULL DEFAULT 0,

                redeemed_at            TIMESTAMPTZ  NULL,
                redeemed_participant_id BIGINT      NULL REFERENCES remote_participants (id) ON DELETE SET NULL,
                revoked_at             TIMESTAMPTZ  NULL,
                revoked_by_user_id     BIGINT       NULL,

                expires_at             TIMESTAMPTZ  NOT NULL,
                created_at             TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at             TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

                CONSTRAINT remote_invitations_type_chk
                    CHECK (invitation_type IN ('INTERNAL', 'EXTERNAL_GUEST', 'SUPPORT')),
                CONSTRAINT remote_invitations_uses_chk
                    CHECK (max_uses >= 1 AND used_count >= 0 AND used_count <= max_uses)
            )
            SQL);
        $this->db->query('CREATE INDEX remote_invitations_session_idx ON remote_invitations (session_id)');
        $this->db->query('CREATE INDEX remote_invitations_expiry_idx ON remote_invitations (expires_at) WHERE revoked_at IS NULL');

        $this->db->query('ALTER TABLE remote_participants ADD CONSTRAINT remote_participants_invitation_fk FOREIGN KEY (invitation_id) REFERENCES remote_invitations (id) ON DELETE SET NULL');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE IF EXISTS remote_participants DROP CONSTRAINT IF EXISTS remote_participants_invitation_fk');
        $this->db->query('DROP TABLE IF EXISTS remote_invitations');
        $this->db->query('DROP TABLE IF EXISTS remote_participants');
        $this->db->query('DROP TABLE IF EXISTS remote_sessions');
        $this->db->query('DROP SEQUENCE IF EXISTS remote_session_display_seq');
    }
}
