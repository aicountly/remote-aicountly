<?php

declare(strict_types=1);

namespace Aicountly\Ice\Licence;

/**
 * AIC-S2S v1 request signing — the scheme Finance, Engage and Manage already speak (commerce contract §0.1), ported
 * byte for byte from manage-aicountly's AicS2sSigner so the central licensing backend can reuse its receiver.
 * tests/LicenceTest.php pins Finance's published worked example.
 *
 *   canonical = METHOD "\n" PATH "\n" CANONICAL_QUERY "\n" TIMESTAMP "\n" NONCE "\n" hex(sha256(body))
 *   X-AIC-Signature: v1=<hex HMAC-SHA256(secret, canonical)>
 */
final class AicS2s
{
    public static function canonicalQuery(string $rawQuery): string
    {
        $pairs = [];
        foreach (explode('&', ltrim($rawQuery, '?')) as $part) {
            if ($part === '') {
                continue;
            }
            $eq      = strpos($part, '=');
            $k       = rawurldecode(str_replace('+', ' ', $eq === false ? $part : substr($part, 0, $eq)));
            $v       = $eq === false ? '' : rawurldecode(str_replace('+', ' ', substr($part, $eq + 1)));
            $pairs[] = [rawurlencode($k), rawurlencode($v)];
        }
        usort($pairs, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return implode('&', array_map(static fn (array $p): string => $p[0] . '=' . $p[1], $pairs));
    }

    public static function canonical(string $method, string $path, string $rawQuery, string $timestamp, string $nonce, string $body): string
    {
        return strtoupper($method) . "\n" . $path . "\n" . self::canonicalQuery($rawQuery) . "\n" . $timestamp . "\n" . $nonce . "\n" . hash('sha256', $body);
    }

    public static function signature(string $secret, string $canonical): string
    {
        return 'v1=' . hash_hmac('sha256', $canonical, $secret);
    }

    /**
     * @param array<string, scalar|null> $params
     */
    public static function buildQuery(array $params): string
    {
        $parts = [];
        foreach ($params as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $parts[] = rawurlencode((string) $k) . '=' . rawurlencode(is_bool($v) ? ($v ? '1' : '0') : (string) $v);
        }

        return implode('&', $parts);
    }

    /** @return array<string, string> the headers of one signed request */
    public static function headers(string $client, string $secret, string $keyId, string $environment, string $method, string $path, string $rawQuery, ?int $timestamp = null, ?string $nonce = null): array
    {
        $ts    = (string) ($timestamp ?? time());
        $nonce ??= self::uuid4();

        return [
            'X-AIC-Client'      => $client,
            'X-AIC-Key-Id'      => $keyId,
            'X-AIC-Timestamp'   => $ts,
            'X-AIC-Nonce'       => $nonce,
            'X-AIC-Environment' => $environment,
            'X-AIC-Signature'   => self::signature($secret, self::canonical($method, $path, $rawQuery, $ts, $nonce, '')),
            'X-AIC-Actor'       => 'service',
            'X-AIC-Request-Id'  => self::uuid4(),
            'X-Saas-Origin'     => $client,
            'Accept'            => 'application/json',
        ];
    }

    private static function uuid4(): string
    {
        $d    = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
