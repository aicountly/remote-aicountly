<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Basic includes reaching your own computer when nobody is at it.
 *
 * Remote is used with personal accounts, and a personal account's licence is
 * the platform-wide entitlement row (`company_id IS NULL`) — or, where none was
 * ever seeded, the built-in Basic in `EffectivePolicyResolver::entitlement()`.
 * Both carried `unattended_access = false`, so a person could register their
 * own computer and be helped on it, but never reach it from anywhere else:
 * the one thing people most often install a remote-access tool for.
 *
 * The built-in Basic and `RemotePlatformDefaultsSeeder` now say true. This
 * brings a row seeded before them into line, because production may hold one
 * — and an entitlement left false there would quietly win over the code, since
 * the stored row is read before the built-in.
 *
 * An entitlement is what a licence allows, not anything switched on. With it
 * true, unattended access still needs the scope's policy to permit it (every
 * organisation preset leaves `allow_unattended_access` off, so a company that
 * falls back to this row still has nothing switched on), the person's own
 * `remote.unattended.access`, and — on each computer separately — somebody at
 * that machine turning it on and confirming what it means.
 *
 * Only the platform-wide row is touched. A company that was given its own
 * entitlement keeps exactly what it was given.
 */
final class EntitleUnattendedAccessByDefault extends Migration
{
    public function up(): void
    {
        $this->db->query(
            'UPDATE remote_entitlements SET unattended_access = TRUE, updated_at = NOW() '
            . 'WHERE company_id IS NULL AND unattended_access = FALSE',
        );
    }

    public function down(): void
    {
        // Deliberately not put back, for the reason `LetADeviceBelongToAPerson`
        // leaves `desktop_devices` alone: `down()` cannot tell the row this
        // changed from one somebody has since decided on, and taking a licence
        // away underneath people who rely on it is worse than leaving it. Every
        // computer's own switch is unaffected either way, so nothing becomes
        // reachable or unreachable by running this backwards.
    }
}
