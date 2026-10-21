<?php

declare(strict_types=1);

namespace Aicountly\Ice;

/**
 * Traffic per licence tier, from the RELAY's own log — independent of the lease books.
 *
 * coturn logs one line per closed allocation:
 *
 *   session 002000000000000003: usage: realm=<…>, username=<1792587666:p.3f2a…>, rp=3, rb=260, sp=3, sb=308
 *
 * `rp`/`rb` are packets/bytes received from the client, `sp`/`sb` sent to it. The username is the HMAC-signed
 * one this library minted, so its subject prefix (`p.` / `f.`, see Tier::letter) cannot be forged by a
 * client: the bytes below are attributable to a tier by the node that carried them. That is the evidence
 * that a free user did not consume the paid reserve — books can say what was admitted, only the relay
 * can say what was carried. (`peer usage:` lines describe the far leg and are not counted.)
 *
 * The line format was read from coturn 4.6.1; confirm it against the real nodes' version (runbook F).
 */
final class UsageLog
{
    /**
     * @param iterable<string> $lines
     * @return array{
     *     tiers: array<string, array{allocations: int, bytesIn: int, bytesOut: int, bytes: int}>,
     *     lines: int, ignored: int
     * }
     */
    public static function summarize(iterable $lines): array
    {
        $tiers = [];
        foreach (['paid', 'free', 'untiered'] as $bucket) {
            $tiers[$bucket] = ['allocations' => 0, 'bytesIn' => 0, 'bytesOut' => 0, 'bytes' => 0];
        }

        $seen = 0;
        $ignored = 0;

        foreach ($lines as $line) {
            $row = self::parse((string) $line);
            if ($row === null) {
                continue;
            }
            $seen++;

            // An allocation with no credential (a probe's unauthenticated challenge) has no username.
            if ($row['username'] === '') {
                $ignored++;
                continue;
            }

            $bucket = Tier::fromUsername($row['username'])?->value ?? 'untiered';
            $tiers[$bucket]['allocations']++;
            $tiers[$bucket]['bytesIn']  += $row['rb'];
            $tiers[$bucket]['bytesOut'] += $row['sb'];
            $tiers[$bucket]['bytes']    += $row['rb'] + $row['sb'];
        }

        return ['tiers' => $tiers, 'lines' => $seen, 'ignored' => $ignored];
    }

    /** @return array{session: string, username: string, rp: int, rb: int, sp: int, sb: int}|null */
    public static function parse(string $line): ?array
    {
        if (stripos($line, 'peer usage:') !== false) {
            return null;
        }

        // Anchored at both ends: the line must BEGIN with coturn's own prefix (a pid and an optional time stamp,
        // then `session N:`) and END with the byte counts, so text a client got into some other log line cannot
        // pose as a usage record.
        $pattern = '/^[^\n]{0,40}?\d+: (?:[^:\n]{0,20}: )?session (\d{1,20}): usage: realm=<[^<>\n]{0,255}>, username=<([^<>\n]{0,160})>, '
            . 'rp=(\d{1,15}), rb=(\d{1,15}), sp=(\d{1,15}), sb=(\d{1,15})\s*$/D';
        if (preg_match($pattern, $line, $m) !== 1) {
            return null;
        }

        // A tier is read only from a username shaped exactly as this library mints them; anything else is
        // reported as untiered rather than trusted.
        $username = preg_match('/^(\d{9,12}:[pf]\.[A-Za-z0-9_.-]{1,64}|\d{9,12}:[A-Za-z0-9_.-]{1,64})?$/', $m[2]) === 1 ? $m[2] : '?';

        return ['session' => $m[1], 'username' => $username, 'rp' => (int) $m[3], 'rb' => (int) $m[4], 'sp' => (int) $m[5], 'sb' => (int) $m[6]];
    }
}
