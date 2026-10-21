<?php

declare(strict_types=1);

namespace Aicountly\Ice;

/**
 * A client's account of how one connection went, reduced to what is safe to log.
 *
 * The JS kit's IceDiagnostics produces it; a product endpoint passes the request body through
 * sanitize() and writes the result as one structured log line. Everything is an enumeration, a node
 * id or a bounded count: an unknown key is dropped, an unknown value becomes null, and nothing that
 * could carry an SDP, a candidate, an IP address or a credential survives — whatever a client sends.
 */
final class ConnectivityReport
{
    public const OUTCOMES        = ['connected', 'recovered', 'failed', 'abandoned'];
    public const PATHS           = ['direct', 'relay', 'unknown'];
    public const CANDIDATE_TYPES = ['host', 'srflx', 'prflx', 'relay'];
    public const RELAY_PROTOCOLS = ['udp', 'tcp', 'tls'];
    public const ERROR_CATEGORIES = [
        'turn_unreachable', 'turn_auth_failed', 'turn_forbidden', 'turn_capacity', 'turn_error',
        'stun_unreachable', 'ice_failed', 'no_relay_candidates', 'credentials_expired', 'signalling_lost',
    ];

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public static function sanitize(array $raw): array
    {
        $gathered = [];
        foreach (self::CANDIDATE_TYPES as $type) {
            $gathered[$type] = self::count($raw['gathered'][$type] ?? 0, 999);
        }

        $errors = [];
        foreach (array_slice(is_array($raw['iceErrors'] ?? null) ? $raw['iceErrors'] : [], 0, 10) as $error) {
            if (! is_array($error) || ($category = self::oneOf($error['category'] ?? null, self::ERROR_CATEGORIES)) === null) {
                continue;
            }

            $errors[] = [
                'category' => $category,
                'node'     => self::nodeId($error['node'] ?? null),
                'code'     => self::count($error['code'] ?? 0, 999),
            ];
        }

        return [
            'outcome'             => self::oneOf($raw['outcome'] ?? null, self::OUTCOMES),
            'path'                => self::oneOf($raw['path'] ?? null, self::PATHS) ?? 'unknown',
            'localCandidateType'  => self::oneOf($raw['localCandidateType'] ?? null, self::CANDIDATE_TYPES),
            'remoteCandidateType' => self::oneOf($raw['remoteCandidateType'] ?? null, self::CANDIDATE_TYPES),
            'relayProtocol'       => self::oneOf($raw['relayProtocol'] ?? null, self::RELAY_PROTOCOLS),
            'relayNode'           => self::nodeId($raw['relayNode'] ?? null),
            'gathered'            => $gathered,
            'iceErrors'           => $errors,
            'restarts'            => self::count($raw['restarts'] ?? 0, 99),
            'credentialRefreshes' => self::count($raw['credentialRefreshes'] ?? 0, 99),
            'timeToConnectMs'     => isset($raw['timeToConnectMs']) ? self::count($raw['timeToConnectMs'], 600_000) : null,
            'durationMs'          => isset($raw['durationMs']) ? self::count($raw['durationMs'], 86_400_000) : null,
        ];
    }

    /** @param list<string> $allowed */
    private static function oneOf(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    private static function nodeId(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $value) === 1 ? $value : null;
    }

    private static function count(mixed $value, int $max): int
    {
        return is_numeric($value) ? max(0, min($max, (int) $value)) : 0;
    }
}
