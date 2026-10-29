<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Hand the confirming person's AICOUNTLY sign-in to the desktop application's
 * embedded portal (docs/desktop/PORTAL.md, "Signing the portal in").
 *
 * Confirming a desktop sign-in registers the computer; the desktop window also
 * shows the web portal, which needs an AICOUNTLY sign-in of its own. Without a
 * hand-off, somebody who confirmed in their browser was asked to sign in again
 * inside the application — a second login for the same person, seconds later.
 *
 * Three columns, holding the sign-in for as short a time and in as weak a form
 * as the flow allows:
 *
 *   * `portal_token` — the confirming page's `auth_token`, between confirm and
 *     the agent's claiming poll (seconds; the agent polls every two). Cleared
 *     in the same statement that claims the row, by a deny, and by the sweep
 *     once the code has expired.
 *   * `handoff_ciphertext` — the same token, sealed with a key derived from a
 *     one-time hand-off code that is handed to the agent and **not stored**:
 *     only its hash is, in `handoff_hash`. What is left in this table after the
 *     claim cannot be read without the code the agent holds.
 *   * `handoff_expires_at` — the code is good for minutes, and once.
 */
final class AddDesktopSignInPortalHandoff extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
            ALTER TABLE remote_desktop_signin_codes
                ADD COLUMN IF NOT EXISTS portal_token       TEXT        NULL,
                ADD COLUMN IF NOT EXISTS handoff_hash       CHAR(64)    NULL,
                ADD COLUMN IF NOT EXISTS handoff_ciphertext TEXT        NULL,
                ADD COLUMN IF NOT EXISTS handoff_expires_at TIMESTAMPTZ NULL
            SQL);

        $this->db->query(
            'CREATE UNIQUE INDEX IF NOT EXISTS remote_desktop_signin_codes_handoff_uniq '
            . 'ON remote_desktop_signin_codes (handoff_hash) WHERE handoff_hash IS NOT NULL',
        );
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX IF EXISTS remote_desktop_signin_codes_handoff_uniq');
        $this->db->query(<<<'SQL'
            ALTER TABLE remote_desktop_signin_codes
                DROP COLUMN IF EXISTS portal_token,
                DROP COLUMN IF EXISTS handoff_hash,
                DROP COLUMN IF EXISTS handoff_ciphertext,
                DROP COLUMN IF EXISTS handoff_expires_at
            SQL);
    }
}
