<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Device-code sign-in for the desktop agent (docs/desktop/DEVICE_ENROLMENT.md).
 *
 * The desktop cannot receive a portal redirect the way a browser tab can, and
 * the loopback pattern RFC 8252 describes for that turned out to depend on the
 * portal honouring a `returnUrl` on a loopback address — which it does not, for
 * its silent-SSO fast path. This table is what replaces it: a short code the
 * agent displays, and a browser tab — already capable of the portal's ordinary,
 * working sign-in — confirms it.
 *
 * Two codes, two audiences, and the split is the security property:
 *
 *   * **`user_code`** is short and shown on the machine's own screen. A person
 *     reads it, opens the confirmation page, and matches it. It identifies
 *     *which* sign-in to confirm and nothing else — anyone who saw it over
 *     someone's shoulder can find the confirmation page but not act as the
 *     agent.
 *   * **`device_code`** is a 32-byte secret the agent holds and never displays.
 *     It is what the agent polls with, and it is what makes the poll endpoint
 *     safe to call unauthenticated — knowing it is proof of being the process
 *     that started this sign-in, exactly as a device auth nonce proves
 *     possession of a key (`remote_device_challenges`, which this mirrors).
 *
 * `device_payload` holds the enrolment request the agent already has at the
 * moment it starts (its public key, its name, its declared capabilities) —
 * captured once, up front, so confirming on the web needs nothing from the
 * agent and claiming needs nothing further from the person. Confirming attaches
 * `identity_id` and `company_id`; claiming spends the row exactly once and is
 * where the two halves meet.
 */
final class CreateRemoteDesktopSignInCodes extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE TABLE remote_desktop_signin_codes (
                id             BIGSERIAL    PRIMARY KEY,
                -- hex of 32 random bytes, exactly like remote_device_challenges.nonce.
                device_code    CHAR(64)     NOT NULL,
                -- "XXXX-XXXX" from an unambiguous 32-symbol alphabet (no 0/O/1/I/L).
                user_code      CHAR(9)      NOT NULL,
                device_label   VARCHAR(160) NULL,
                device_payload JSONB        NOT NULL DEFAULT '{}',
                status         VARCHAR(16)  NOT NULL DEFAULT 'PENDING',
                identity_id    BIGINT       NULL REFERENCES remote_identities (id) ON DELETE CASCADE,
                company_id     BIGINT       NULL,
                company_name   VARCHAR(160) NULL,
                requested_ip   INET         NULL,
                confirmed_ip   INET         NULL,
                confirmed_at   TIMESTAMPTZ  NULL,
                claimed_at     TIMESTAMPTZ  NULL,
                expires_at     TIMESTAMPTZ  NOT NULL,
                created_at     TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            )
            SQL);

        $this->db->query(<<<'SQL'
            ALTER TABLE remote_desktop_signin_codes
                ADD CONSTRAINT remote_desktop_signin_codes_status_chk
                    CHECK (status IN ('PENDING', 'CONFIRMED', 'DENIED', 'CLAIMED')),
                -- Confirming is what attaches a person and an organisation to a
                -- code; nothing before that point may claim to know either.
                ADD CONSTRAINT remote_desktop_signin_codes_confirmed_chk
                    CHECK (status NOT IN ('CONFIRMED', 'CLAIMED') OR (identity_id IS NOT NULL AND company_id IS NOT NULL))
            SQL);

        // The lookup a poll does, every couple of seconds for up to the TTL —
        // this is the index that matters for load.
        $this->db->query('CREATE UNIQUE INDEX remote_desktop_signin_codes_device_code_uniq ON remote_desktop_signin_codes (device_code)');
        // Unique only while a code is still live: once it is denied or claimed
        // its short code is free to (extremely improbably) recur without two
        // rows ever being ambiguous to look up by user_code at the same time.
        $this->db->query("CREATE UNIQUE INDEX remote_desktop_signin_codes_user_code_uniq ON remote_desktop_signin_codes (user_code) WHERE status IN ('PENDING', 'CONFIRMED')");
        // Drives the opportunistic sweep, same shape as remote_device_challenges.
        $this->db->query('CREATE INDEX remote_desktop_signin_codes_expiry_idx ON remote_desktop_signin_codes (expires_at)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS remote_desktop_signin_codes');
    }
}
