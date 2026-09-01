<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * AICOUNTLY Support requests and end-of-session feedback (§24, §72).
 *
 * A support request is deliberately not coupled to any one ticketing system:
 * `support_ticket_id`, `source_product`, `source_route` and `source_reference`
 * are optional strings, so Pulse, Advisor or a future helpdesk can all use the
 * same queue without Remote depending on any of them.
 */
final class CreateRemoteSupportTables extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE TABLE remote_support_requests (
                id                     BIGSERIAL    PRIMARY KEY,
                uuid                   UUID         NOT NULL UNIQUE,
                session_id             BIGINT       NULL REFERENCES remote_sessions (id) ON DELETE SET NULL,

                scope_type             VARCHAR(20)  NOT NULL DEFAULT 'AICOUNTLY_SUPPORT',
                company_id             BIGINT       NULL,
                branch_id              BIGINT       NULL,
                financial_year_id      BIGINT       NULL,

                requester_user_id      BIGINT       NOT NULL REFERENCES remote_identities (id),
                requester_name         VARCHAR(160) NOT NULL,

                source_product         VARCHAR(40)  NULL,
                source_route           VARCHAR(255) NULL,
                source_reference       VARCHAR(120) NULL,
                source_agent           VARCHAR(40)  NULL,
                source_conversation_id VARCHAR(120) NULL,
                support_ticket_id      VARCHAR(64)  NULL,
                issue_summary          TEXT         NULL,

                requested_share_mode   VARCHAR(24)  NOT NULL DEFAULT 'SAFE_SHARE',
                priority               VARCHAR(10)  NOT NULL DEFAULT 'NORMAL',
                status                 VARCHAR(16)  NOT NULL DEFAULT 'PENDING',

                accepted_by_user_id    BIGINT       NULL REFERENCES remote_identities (id),
                accepted_at            TIMESTAMPTZ  NULL,
                closed_at              TIMESTAMPTZ  NULL,
                expires_at             TIMESTAMPTZ  NOT NULL,

                created_at             TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at             TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

                CONSTRAINT remote_support_requests_scope_chk
                    CHECK (scope_type IN ('PERSONAL', 'COMPANY', 'AICOUNTLY_SUPPORT')),
                CONSTRAINT remote_support_requests_status_chk
                    CHECK (status IN ('PENDING', 'ACCEPTED', 'DECLINED', 'CANCELLED', 'EXPIRED', 'COMPLETED')),
                CONSTRAINT remote_support_requests_priority_chk
                    CHECK (priority IN ('LOW', 'NORMAL', 'HIGH', 'URGENT')),
                CONSTRAINT remote_support_requests_share_mode_chk
                    CHECK (requested_share_mode IN ('SAFE_SHARE', 'BROWSER_TAB', 'APPLICATION_WINDOW', 'ENTIRE_MONITOR')),
                -- An accepted request has an acceptor and vice versa: the two
                -- can never drift apart, whichever technician won the race.
                CONSTRAINT remote_support_requests_accept_chk
                    CHECK ((status = 'ACCEPTED') = (accepted_by_user_id IS NOT NULL AND accepted_at IS NOT NULL)
                           OR status IN ('COMPLETED', 'CANCELLED'))
            )
            SQL);
        $this->db->query('CREATE INDEX remote_support_requests_queue_idx ON remote_support_requests (status, created_at) WHERE status = \'PENDING\'');
        $this->db->query('CREATE INDEX remote_support_requests_company_idx ON remote_support_requests (company_id, created_at DESC)');
        $this->db->query('CREATE INDEX remote_support_requests_requester_idx ON remote_support_requests (requester_user_id, created_at DESC)');

        $this->db->query(<<<'SQL'
            CREATE TABLE remote_session_feedback (
                id          BIGSERIAL   PRIMARY KEY,
                session_id  BIGINT      NOT NULL REFERENCES remote_sessions (id) ON DELETE CASCADE,
                user_id     BIGINT      NOT NULL REFERENCES remote_identities (id) ON DELETE CASCADE,
                resolution  VARCHAR(16) NOT NULL,
                rating      SMALLINT    NULL,
                comments    TEXT        NULL,
                created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                CONSTRAINT remote_session_feedback_unique UNIQUE (session_id, user_id),
                CONSTRAINT remote_session_feedback_resolution_chk
                    CHECK (resolution IN ('YES', 'PARTIALLY', 'NO')),
                CONSTRAINT remote_session_feedback_rating_chk
                    CHECK (rating IS NULL OR rating BETWEEN 1 AND 5),
                CONSTRAINT remote_session_feedback_comment_chk
                    CHECK (comments IS NULL OR char_length(comments) <= 2000)
            )
            SQL);
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS remote_session_feedback');
        $this->db->query('DROP TABLE IF EXISTS remote_support_requests');
    }
}
