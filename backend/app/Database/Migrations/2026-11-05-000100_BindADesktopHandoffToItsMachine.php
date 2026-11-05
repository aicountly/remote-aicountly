<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Bind a portal sign-in hand-off to the machine it was issued for.
 *
 * `POST /desktop-signin/handoff` answered any caller that presented the 64-hex
 * code with the confirming person's AICOUNTLY `auth_token` — the estate-wide
 * credential that mints a `ses_key` for every product. The code was the only
 * proof asked for, so the credential was bound to a bearer string rather than
 * to the computer that had just proved possession of its enrolled private key.
 * `docs/desktop/DEVICE_ENROLMENT.md` asserted the opposite ("No portal
 * credential ever reaches this window"), which is corrected in the same change.
 *
 * `handoff_device_fp` is the SHA-256 fingerprint of the enrolling device's
 * public key, known from the row's own `device_payload` before the device is
 * enrolled. It is used twice: as part of the key the hand-off is sealed under,
 * and as the fact the redeeming caller's device credential must match. NULL on
 * a row sealed before this column existed, which keeps a hand-off issued in the
 * minutes before a deploy redeemable — the TTL is ten minutes, so those rows
 * are gone shortly after.
 */
final class BindADesktopHandoffToItsMachine extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
            ALTER TABLE remote_desktop_signin_codes
                ADD COLUMN IF NOT EXISTS handoff_device_fp CHAR(64) NULL
            SQL);
    }

    public function down(): void
    {
        $this->db->query(<<<'SQL'
            ALTER TABLE remote_desktop_signin_codes
                DROP COLUMN IF EXISTS handoff_device_fp
            SQL);
    }
}
