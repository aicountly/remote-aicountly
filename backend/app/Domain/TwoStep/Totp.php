<?php

declare(strict_types=1);

namespace App\Domain\TwoStep;

/**
 * Time-based one-time passwords — RFC 6238 over RFC 4226 — and the base32 an
 * authenticator app reads its secret in.
 *
 * Written here rather than pulled in as a package because it is small, it is
 * the security property itself, and every line of it can be checked against
 * the RFCs' own test vectors (tests/TwoStep/TotpTest.php). The parameters are
 * the ones every authenticator app supports without being told: HMAC-SHA1,
 * 30-second steps, six digits, a 160-bit secret.
 *
 * Nothing in this class knows about people, storage or replay. {@see match()}
 * says *which* time step a code belongs to; refusing a step that was already
 * used is the caller's job, because only the caller knows what was used.
 */
final class Totp
{
    public const DIGITS = 6;
    public const PERIOD = 30;

    /** 160 bits: what RFC 4226 recommends and what an authenticator app expects. */
    public const SECRET_BYTES = 20;

    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A fresh secret, raw bytes. */
    public static function generateSecret(): string
    {
        return random_bytes(self::SECRET_BYTES);
    }

    /** The RFC 6238 time step a moment falls in. */
    public static function step(int $timestamp, int $period = self::PERIOD): int
    {
        return intdiv($timestamp, $period);
    }

    /**
     * RFC 4226 HOTP: HMAC-SHA1 over the 8-byte big-endian counter, dynamically
     * truncated to 31 bits, reduced to `$digits` decimal digits, left-padded.
     */
    public static function hotp(string $key, int $counter, int $digits = self::DIGITS): string
    {
        // `J` is unsigned 64-bit big-endian: the counter as the RFC lays it out.
        $hash   = hash_hmac('sha1', pack('J', $counter), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($binary % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /** The code an authenticator shows at a moment. */
    public static function at(string $key, int $timestamp, int $digits = self::DIGITS, int $period = self::PERIOD): string
    {
        return self::hotp($key, self::step($timestamp, $period), $digits);
    }

    /**
     * Which time step a submitted code belongs to, within `$window` steps either
     * side of now, or null.
     *
     * ±1 step is the usual allowance for a phone clock a little out and for the
     * seconds it takes to type: a code is good for between 60 and 90 seconds.
     * Every candidate is computed and compared with `hash_equals` — no early
     * return on the first match — so how long the answer takes does not say how
     * close a guess came. The newest matching step wins, so a replay check made
     * on the result is made on the step that matters.
     */
    public static function match(string $key, string $code, int $timestamp, int $window = 1): ?int
    {
        if (preg_match('/^\d{' . self::DIGITS . '}$/', $code) !== 1) {
            return null;
        }

        $now     = self::step($timestamp);
        $matched = null;

        for ($offset = -$window; $offset <= $window; $offset++) {
            $step = $now + $offset;
            if ($step < 0) {
                continue;
            }
            if (hash_equals(self::hotp($key, $step), $code)) {
                $matched = $step;
            }
        }

        return $matched;
    }

    /**
     * The `otpauth://` URI an authenticator app scans (Google's Key URI format,
     * which every app reads). The issuer is given twice, as the label prefix
     * and as a parameter, because older apps read only one of the two.
     */
    public static function uri(string $issuer, string $account, string $base32Secret): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?%s',
            rawurlencode($issuer),
            rawurlencode($account),
            http_build_query([
                'secret'    => $base32Secret,
                'issuer'    => $issuer,
                'algorithm' => 'SHA1',
                'digits'    => self::DIGITS,
                'period'    => self::PERIOD,
            ], '', '&', PHP_QUERY_RFC3986),
        );
    }

    /** RFC 4648 base32, upper case, without padding — how authenticator apps take a secret. */
    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            if ($byte === '') {
                continue;
            }
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            if ($chunk === '') {
                continue;
            }
            $out .= self::BASE32_ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $out;
    }

    /**
     * The reverse, tolerant of what a person types — lower case, spaces, the
     * `=` padding — and strict about everything else: null for any character
     * that is not base32.
     */
    public static function base32Decode(string $text): ?string
    {
        $text = strtoupper(preg_replace('/[\s=]+/', '', $text) ?? '');
        if ($text === '' || strspn($text, self::BASE32_ALPHABET) !== strlen($text)) {
            return $text === '' ? '' : null;
        }

        $bits = '';
        foreach (str_split($text) as $char) {
            $bits .= str_pad(decbin((int) strpos(self::BASE32_ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            // Trailing bits short of a byte are padding, not data.
            if (strlen($chunk) === 8) {
                $out .= chr((int) bindec($chunk));
            }
        }

        return $out;
    }
}
