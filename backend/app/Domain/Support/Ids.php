<?php

declare(strict_types=1);

namespace App\Domain\Support;

/**
 * Identifier generation.
 *
 * Three kinds of identifier exist in Remote and they are not interchangeable:
 *
 *   * the **UUID** is the session's public name — unguessable, used in URLs
 *     and APIs, never a credential on its own;
 *   * the **display id** (`AR-10282`) is a label for humans to read out on a
 *     phone call, and is explicitly not secret (§70);
 *   * the **join code** and **invitation secret** *are* credentials, so both
 *     come from `random_bytes` and neither is derived from a database id.
 *
 * A serial primary key is never any of these and never leaves the server.
 */
final class Ids
{
    public static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80); // variant RFC 4122

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    public static function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }

    /**
     * A 9-digit join code, generated from a CSPRNG (§6E).
     *
     * `random_int` rather than `rand`/`mt_rand`: a code that can be predicted
     * from a previous one is not a code. Leading zeros are kept, so all 10^9
     * values are reachable.
     */
    public static function joinCode(int $length = 9): string
    {
        $digits = '';
        for ($i = 0; $i < $length; $i++) {
            $digits .= (string) random_int(0, 9);
        }

        return $digits;
    }

    /** `583 194 726` — grouped for reading aloud, stored without the spaces. */
    public static function formatJoinCode(string $code): string
    {
        return trim(chunk_split($code, 3, ' '));
    }

    public static function normaliseJoinCode(string $input): string
    {
        return preg_replace('/\D+/', '', $input) ?? '';
    }

    /**
     * The secret half of an invitation link. 32 bytes of entropy, URL-safe.
     * Only its SHA-256 is stored; this value exists once, in one response.
     */
    public static function invitationSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hashSecret(string $secret): string
    {
        return hash('sha256', $secret);
    }
}
