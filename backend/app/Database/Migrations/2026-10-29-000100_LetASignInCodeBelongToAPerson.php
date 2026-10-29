<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A desktop sign-in can be confirmed without an organisation.
 *
 * `LetADeviceBelongToAPerson` dropped the constraint that forbade a device
 * with no company, and the confirm page stopped asking for one — but
 * `remote_desktop_signin_codes_confirmed_chk` still required `company_id` on
 * every CONFIRMED or CLAIMED row. So the person confirming their own computer
 * passed every permission check and then broke the CHECK on the very
 * `UPDATE` that records the confirmation. With `DBDebug` off in production
 * that failure is not an exception, only zero affected rows, which the
 * service reported as "That code has already been used or denied." — on a
 * code nobody had used, for every account, fresh or not.
 *
 * The constraint keeps its first half: confirming is still what attaches a
 * person to a code, and nothing before that may claim to know one. The
 * organisation is optional, exactly as it is on the device it becomes.
 *
 * Reversible on the same terms as `LetADeviceBelongToAPerson`: `down()`
 * succeeds only while no personal sign-in row exists.
 */
class LetASignInCodeBelongToAPerson extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
            ALTER TABLE remote_desktop_signin_codes
                DROP CONSTRAINT IF EXISTS remote_desktop_signin_codes_confirmed_chk,
                ADD CONSTRAINT remote_desktop_signin_codes_confirmed_chk
                    CHECK (status NOT IN ('CONFIRMED', 'CLAIMED') OR identity_id IS NOT NULL)
            SQL);
    }

    public function down(): void
    {
        $this->db->query(<<<'SQL'
            ALTER TABLE remote_desktop_signin_codes
                DROP CONSTRAINT IF EXISTS remote_desktop_signin_codes_confirmed_chk,
                ADD CONSTRAINT remote_desktop_signin_codes_confirmed_chk
                    CHECK (status NOT IN ('CONFIRMED', 'CLAIMED') OR (identity_id IS NOT NULL AND company_id IS NOT NULL))
            SQL);
    }
}
