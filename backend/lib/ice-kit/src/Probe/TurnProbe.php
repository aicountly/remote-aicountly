<?php

declare(strict_types=1);

namespace Aicountly\Ice\Probe;

use Aicountly\Ice\CredentialMinter;
use Aicountly\Ice\TurnNode;

/**
 * Asks a TURN node, over each of its transports, the two questions that decide whether calls relay:
 *
 *   * **binding** — does anything answer STUN at that address and port? (No credential involved.)
 *   * **allocation** — will it allocate a relay for a credential this API minted? This is the real
 *     test: it fails on a secret that does not match coturn's `static-auth-secret`, a wrong realm
 *     assumption, an exhausted quota, or a relay port range the server cannot bind — every one of
 *     which leaves "STUN answers" perfectly green.
 *
 * A successful allocation is released straight away (Refresh, lifetime 0). Results never contain the
 * credential; the relayed address is reported only as a family, since it is the server's own.
 */
final class TurnProbe
{
    private const SOFTWARE = 'aicountly-ice-kit-probe';

    /**
     * @param array{caFile?: string|null, verifyPeer?: bool} $tls
     */
    public function __construct(
        private readonly float $timeout = 5.0,
        private readonly array $tls = [],
    ) {
    }

    /**
     * @param list<string>|null $transports limit to these (udp, tcp, tls); null means all the node offers
     * @param bool              $negative   also prove the relay REFUSES a wrong-secret credential, an expired one, and no
     *                                      credential at all (needs `$allocate`); a relay that allocates for any of them
     *                                      fails the node outright
     * @return array{id: string, ok: bool, reachable: bool, allocated: bool|null, transports: array<string, array<string, mixed>>, error: string|null, negativeOk?: bool}
     */
    public function probeNode(TurnNode $node, ?array $transports = null, bool $allocate = false, int $ttlSeconds = 300, string $subject = 'probe', bool $negative = false): array
    {
        $results   = [];
        $reachable = false;
        $allocated = $allocate ? false : null;
        $error     = null;
        $negativeAccepted = false;

        foreach ($node->turnUrls() as $url) {
            $target = self::parseTurnUrl($url);
            if ($target === null || ($transports !== null && ! in_array($target['transport'], $transports, true))) {
                continue;
            }

            $key  = $target['transport'];
            $step = ['url' => $url, 'binding' => $this->binding($target['transport'], $target['host'], $target['port'])];

            $reachable = $reachable || $step['binding']['ok'];

            if ($allocate) {
                $credentials = $node->credentialsFor($subject, $ttlSeconds, time());
                $step['allocation'] = $credentials === null
                    ? ['ok' => false, 'error' => 'no_credentials']
                    : $this->allocation($target['transport'], $target['host'], $target['port'], $credentials['username'], $credentials['credential']);

                $allocated = $allocated || $step['allocation']['ok'];
                $error   ??= $step['allocation']['ok'] ? null : ($step['allocation']['error'] ?? null);
            }

            if ($negative && $allocate) {
                $step['negative'] = $this->negativeChecks($node, $target['transport'], $target['host'], $target['port'], $subject);
                $negativeAccepted = $negativeAccepted || in_array('accepted', array_column($step['negative'], 'verdict'), true);
            }

            $error ??= $step['binding']['ok'] ? null : ($step['binding']['error'] ?? null);
            $results[$key] = $step;
        }

        $ok = $allocate ? (bool) $allocated : $reachable;

        // A relay that hands out an allocation for a forged or expired credential is open to anyone,
        // however well its legitimate allocations work.
        if ($negativeAccepted) {
            $ok    = false;
            $error = 'accepts_invalid_credentials';
        }

        $result = [
            'id'         => $node->id,
            'ok'         => $ok && $results !== [],
            'reachable'  => $reachable,
            'allocated'  => $allocated,
            'transports' => $results,
            'error'      => $ok ? null : ($error ?? 'no_transport'),
        ];

        if ($negative && $allocate) {
            $result['negativeOk'] = ! $negativeAccepted;
        }

        return $result;
    }

    /** @return array{ok: bool, rttMs?: int, error?: string, detail?: string, certificate?: array<string, mixed>} */
    public function binding(string $transport, string $host, int $port): array
    {
        $started     = microtime(true);
        $certificate = null;

        try {
            $socket      = StunSocket::open($transport, $host, $port, $this->timeout, $this->tls);
            $certificate = $socket->certificate !== null
                ? self::summarizeCertificate($socket->certificate, time(), ($this->tls['verifyPeer'] ?? true) === true)
                : null;
            $txid  = StunMessage::newTransactionId();
            $reply = $socket->exchange(
                StunMessage::encode(StunMessage::BINDING_REQUEST, $txid, [[StunMessage::ATTR_SOFTWARE, self::SOFTWARE]]),
                $txid,
            );
            $socket->close();
        } catch (ProbeException $e) {
            $failure = ['ok' => false, 'error' => $e->reason, 'detail' => $e->getMessage()];

            // A refused handshake says nothing about WHY. Look at the certificate without trusting it, so
            // "expired", "wrong name" and "unknown issuer" are told apart from "the port is closed".
            if ($e->reason === 'tls_failed') {
                $failure['certificate'] = self::inspectCertificate($host, $port, $this->timeout);
            }

            return $failure;
        }

        if ($reply->type !== StunMessage::BINDING_SUCCESS) {
            return ['ok' => false, 'error' => 'binding_refused'] + ($certificate !== null ? ['certificate' => $certificate] : []);
        }

        return ['ok' => true, 'rttMs' => (int) round((microtime(true) - $started) * 1000)]
            + ($certificate !== null ? ['certificate' => $certificate] : []);
    }

    /**
     * What certificate a TLS port presents, without trusting it. Diagnostic only: `verified` is false.
     *
     * @return array<string, mixed>|null
     */
    public static function inspectCertificate(string $host, int $port, float $timeout = 5.0): ?array
    {
        $context = stream_context_create(['ssl' => [
            'peer_name' => $host, 'SNI_enabled' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'capture_peer_cert' => true,
        ]]);
        $stream = @stream_socket_client('tls://' . (str_contains($host, ':') ? "[{$host}]" : $host) . ":{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($stream === false) {
            return null;
        }

        $captured = stream_context_get_params($context)['options']['ssl']['peer_certificate'] ?? null;
        @fclose($stream);
        $parsed = $captured !== null ? openssl_x509_parse($captured) : false;

        return is_array($parsed) ? self::summarizeCertificate($parsed, time(), false) : null;
    }

    /**
     * A certificate reduced to what an operator checks: who it is for, who issued it, until when.
     *
     * @param array<string, mixed> $parsed openssl_x509_parse()
     * @return array<string, mixed>
     */
    public static function summarizeCertificate(array $parsed, int $now, bool $verified): array
    {
        $san = [];
        foreach (explode(',', (string) ($parsed['extensions']['subjectAltName'] ?? '')) as $entry) {
            $entry = trim($entry);
            if (str_starts_with($entry, 'DNS:')) {
                $san[] = substr($entry, 4);
            }
        }

        $validTo = (int) ($parsed['validTo_time_t'] ?? 0);

        return [
            'subject'       => (string) ($parsed['subject']['CN'] ?? ''),
            'san'           => $san,
            'issuer'        => trim(($parsed['issuer']['O'] ?? '') . ' ' . ($parsed['issuer']['CN'] ?? '')),
            'validFrom'     => gmdate('c', (int) ($parsed['validFrom_time_t'] ?? 0)),
            'validTo'       => gmdate('c', $validTo),
            'daysRemaining' => (int) floor(($validTo - $now) / 86400),
            // True only when this connection verified the chain and the host name against the trust store.
            'verified'      => $verified,
        ];
    }

    /**
     * @return array{ok: bool, rttMs?: int, error?: string, detail?: string, realm?: string, lifetime?: int, relayFamily?: string}
     */
    public function allocation(string $transport, string $host, int $port, string $username, #[\SensitiveParameter] string $credential): array
    {
        $started = microtime(true);
        $socket  = null;

        try {
            $socket = StunSocket::open($transport, $host, $port, $this->timeout, $this->tls);
            $requested = [StunMessage::ATTR_REQUESTED_TRANSPORT, pack('C', StunMessage::TRANSPORT_UDP) . "\0\0\0"];

            // 1. Unauthenticated, to learn the realm and nonce. A relay that allocates here is open
            //    to anyone on the internet, which is a finding, not a success.
            $txid      = StunMessage::newTransactionId();
            $challenge = $socket->exchange(StunMessage::encode(StunMessage::ALLOCATE_REQUEST, $txid, [$requested]), $txid);

            if ($challenge->type === StunMessage::ALLOCATE_SUCCESS) {
                return ['ok' => false, 'error' => 'open_relay', 'detail' => 'The relay allocated without a credential.'];
            }

            $realm = $challenge->first(StunMessage::ATTR_REALM);
            $nonce = $challenge->first(StunMessage::ATTR_NONCE);

            if (($challenge->errorCode()['code'] ?? 0) !== 401 || $realm === null || $nonce === null) {
                return ['ok' => false, 'error' => 'unexpected_challenge', 'detail' => self::describeError($challenge)];
            }

            $key = StunMessage::longTermKey($username, $realm, $credential);

            // 2. With the credential. One retry on a stale nonce (438), which coturn sends routinely.
            for ($try = 0; $try < 2; $try++) {
                $txid  = StunMessage::newTransactionId();
                $reply = $socket->exchange(StunMessage::encode(StunMessage::ALLOCATE_REQUEST, $txid, [
                    $requested,
                    [StunMessage::ATTR_USERNAME, $username],
                    [StunMessage::ATTR_REALM, $realm],
                    [StunMessage::ATTR_NONCE, $nonce],
                ], $key), $txid);

                if ($reply->type === StunMessage::ALLOCATE_SUCCESS) {
                    $relayed  = $reply->xorAddress(StunMessage::ATTR_XOR_RELAYED_ADDRESS);
                    $lifetime = $reply->first(StunMessage::ATTR_LIFETIME);
                    $rtt      = (int) round((microtime(true) - $started) * 1000);

                    $this->release($socket, $username, $realm, $nonce, $key);

                    return array_filter([
                        'ok'          => true,
                        'rttMs'       => $rtt,
                        'realm'       => $realm,
                        'lifetime'    => $lifetime !== null ? unpack('N', $lifetime)[1] : null,
                        'relayFamily' => $relayed['family'] ?? null,
                    ], static fn ($v) => $v !== null);
                }

                $code = $reply->errorCode()['code'] ?? 0;
                if ($code === 438 && ($fresh = $reply->first(StunMessage::ATTR_NONCE)) !== null) {
                    $nonce = $fresh;

                    continue;
                }

                return ['ok' => false, 'error' => self::reasonFor($code), 'detail' => self::describeError($reply)];
            }

            return ['ok' => false, 'error' => 'stale_nonce'];
        } catch (ProbeException $e) {
            return ['ok' => false, 'error' => $e->reason, 'detail' => $e->getMessage()];
        } finally {
            $socket?->close();
        }
    }

    /**
     * `turn:host:port?transport=udp|tcp` and `turns:host:port?transport=tcp` → where and how to connect.
     *
     * @return array{transport: string, host: string, port: int}|null
     */
    public static function parseTurnUrl(string $url): ?array
    {
        if (preg_match('/^(turns?):(\[[^\]]+\]|[^:?\/]+)(?::(\d+))?(?:\?transport=(udp|tcp))?$/i', trim($url), $m) !== 1) {
            return null;
        }

        $secure    = strtolower($m[1]) === 'turns';
        $transport = $secure ? 'tls' : (strtolower($m[4] ?? '') ?: 'udp');

        return [
            'transport' => $transport,
            'host'      => trim($m[2], '[]'),
            'port'      => ($m[3] ?? '') !== '' ? (int) $m[3] : ($secure ? 5349 : 3478),
        ];
    }

    /** RFC 5389/5766/8656 error codes, as words an operator can act on. */
    public static function reasonFor(int $code): string
    {
        return match ($code) {
            401     => 'auth_rejected',
            403     => 'forbidden',
            437     => 'allocation_mismatch',
            438     => 'stale_nonce',
            440     => 'address_family_not_supported',
            441     => 'wrong_credentials',
            442     => 'unsupported_transport',
            486     => 'allocation_quota_reached',
            508     => 'insufficient_capacity',
            default => 'error_' . $code,
        };
    }

    /**
     * What this relay does to a LIVE allocation when the credential that made it expires, and to a NEW
     * allocation attempted with that expired credential. Takes about 80 seconds.
     *
     * It matters because a client's TURN credential is fixed when it is issued. If the relay keeps
     * serving established allocations (coturn did, in every build this was run against), an expiry
     * threatens only what needs a NEW allocation afterwards — an ICE restart that re-gathers, a
     * reconnect after a network change. If it kills them, a relayed session is cut at the credential's
     * expiry. Which one a deployment has decides how much credential renewal is worth, so it is measured
     * on the actual nodes, not assumed from the documentation.
     *
     * @return array<string, mixed>
     */
    public function expirySemantics(TurnNode $node, string $transport = 'tcp', string $subject = 'expiry-test'): array
    {
        $target = null;
        foreach ($node->turnUrls() as $url) {
            $parsed = self::parseTurnUrl($url);
            if ($parsed !== null && $parsed['transport'] === $transport) {
                $target = $parsed;
                break;
            }
        }
        if ($target === null) {
            return ['ok' => false, 'error' => 'no_such_transport'];
        }

        $credential = $node->credentialsFor($subject, CredentialMinter::MIN_TTL, time());
        if ($credential === null || $credential['expiresAt'] === null) {
            return ['ok' => false, 'error' => 'needs_a_rest_secret'];
        }

        $result = ['transport' => $transport, 'credentialLifetimeSeconds' => CredentialMinter::MIN_TTL, 'steps' => []];

        try {
            $socket = StunSocket::open($transport, $target['host'], $target['port'], $this->timeout, $this->tls);
            $requested = [StunMessage::ATTR_REQUESTED_TRANSPORT, pack('C', StunMessage::TRANSPORT_UDP) . "\0\0\0"];

            $txid      = StunMessage::newTransactionId();
            $challenge = $socket->exchange(StunMessage::encode(StunMessage::ALLOCATE_REQUEST, $txid, [$requested]), $txid);
            $realm     = $challenge->first(StunMessage::ATTR_REALM);
            $nonce     = $challenge->first(StunMessage::ATTR_NONCE);
            if ($realm === null || $nonce === null) {
                return $result + ['ok' => false, 'error' => 'unexpected_challenge'];
            }

            $key  = StunMessage::longTermKey($credential['username'], $realm, $credential['credential']);
            $auth = static fn (string $n): array => [
                [StunMessage::ATTR_USERNAME, $credential['username']], [StunMessage::ATTR_REALM, $realm], [StunMessage::ATTR_NONCE, $n],
            ];

            // Allocate, and Refresh with one retry on a stale nonce (438) using the SAME credential.
            $send = function (int $type, array $extra) use ($socket, $auth, $key, &$nonce): array {
                for ($try = 0; $try < 2; $try++) {
                    $tx    = StunMessage::newTransactionId();
                    $reply = $socket->exchange(StunMessage::encode($type, $tx, [...$extra, ...$auth($nonce)], $key), $tx);
                    $error = $reply->errorCode();
                    if (($error['code'] ?? 0) === 438 && ($fresh = $reply->first(StunMessage::ATTR_NONCE)) !== null) {
                        $nonce = $fresh;

                        continue;
                    }

                    return ['ok' => $error === null, 'error' => $error === null ? null : self::reasonFor($error['code'])];
                }

                return ['ok' => false, 'error' => 'stale_nonce'];
            };

            $allocated = $send(StunMessage::ALLOCATE_REQUEST, [$requested]);
            $result['steps'][] = ['at' => 'issued', 'action' => 'allocate'] + $allocated;
            if (! $allocated['ok']) {
                return $result + ['ok' => false, 'error' => 'allocation_failed'];
            }

            $refresh = [[StunMessage::ATTR_LIFETIME, pack('N', 600)]];

            sleep(25);
            $before = $send(StunMessage::REFRESH_REQUEST, $refresh);
            $result['steps'][] = ['at' => 'credential valid', 'action' => 'refresh'] + $before;

            $wait = $credential['expiresAt'] + 12 - time();
            if ($wait > 0) {
                sleep($wait);
            }
            $after = $send(StunMessage::REFRESH_REQUEST, $refresh);
            $result['steps'][] = ['at' => 'credential EXPIRED', 'action' => 'refresh'] + $after;

            // The same expired credential, asking for a second allocation on a new connection.
            $fresh = $this->allocation($transport, $target['host'], $target['port'], $credential['username'], $credential['credential']);
            $result['steps'][] = ['at' => 'credential EXPIRED', 'action' => 'new allocation'] + ['ok' => $fresh['ok'], 'error' => $fresh['error'] ?? null];

            $send(StunMessage::REFRESH_REQUEST, [[StunMessage::ATTR_LIFETIME, pack('N', 0)]]);
            $socket->close();
        } catch (ProbeException $e) {
            return $result + ['ok' => false, 'error' => $e->reason, 'detail' => $e->getMessage()];
        }

        $result['establishedSurvivesExpiry'] = $after['ok'];
        $result['newAllocationRefusedAfterExpiry'] = ! $fresh['ok'];
        $result['ok'] = $before['ok'];

        return $result;
    }

    /**
     * Three ways to ask for a relay that must all be refused: a credential signed with the wrong
     * secret, one past its expiry, and none at all.
     *
     * `verdict`: `rejected` (the relay answered with an auth error), `accepted` (it allocated — a
     * finding), or `inconclusive` (no answer: not evidence either way).
     *
     * @return array<string, array{verdict: string, error?: string|null}>
     */
    public function negativeChecks(TurnNode $node, string $transport, string $host, int $port, string $subject = 'probe'): array
    {
        $now     = time();
        $results = [];

        foreach (['wrong-secret' => 'wrongSecret', 'expired' => 'expired'] as $kind => $label) {
            $forged = $node->refusableCredential($kind, $subject, $now);
            if ($forged === null) {
                $results[$label] = ['verdict' => 'inconclusive', 'error' => 'no_secret'];

                continue;
            }

            $outcome = $this->allocation($transport, $host, $port, $forged['username'], $forged['credential']);
            $results[$label] = [
                'verdict' => match (true) {
                    // An allocation at the anonymous step is an allocation for no valid credential at all.
                    $outcome['ok'] === true || ($outcome['error'] ?? '') === 'open_relay'                => 'accepted',
                    in_array($outcome['error'] ?? '', ['auth_rejected', 'wrong_credentials', 'forbidden'], true) => 'rejected',
                    default                                                                              => 'inconclusive',
                },
                'error' => $outcome['error'] ?? null,
            ];
        }

        $results['anonymous'] = $this->anonymous($transport, $host, $port);

        return $results;
    }

    /**
     * An Allocate with no credential: a relay must answer 401 and offer a realm and nonce.
     *
     * @return array{verdict: string, error?: string|null}
     */
    public function anonymous(string $transport, string $host, int $port): array
    {
        try {
            $socket = StunSocket::open($transport, $host, $port, $this->timeout, $this->tls);
            $txid   = StunMessage::newTransactionId();
            $reply  = $socket->exchange(StunMessage::encode(StunMessage::ALLOCATE_REQUEST, $txid, [
                [StunMessage::ATTR_REQUESTED_TRANSPORT, pack('C', StunMessage::TRANSPORT_UDP) . "\0\0\0"],
            ]), $txid);
            $socket->close();
        } catch (ProbeException $e) {
            return ['verdict' => 'inconclusive', 'error' => $e->reason];
        }

        if ($reply->type === StunMessage::ALLOCATE_SUCCESS) {
            return ['verdict' => 'accepted', 'error' => 'open_relay'];
        }

        return ['verdict' => ($reply->errorCode()['code'] ?? 0) === 401 ? 'rejected' : 'inconclusive', 'error' => self::reasonFor($reply->errorCode()['code'] ?? 0)];
    }

    private function release(StunSocket $socket, string $username, string $realm, string $nonce, string $key): void
    {
        try {
            $txid = StunMessage::newTransactionId();
            $socket->exchange(StunMessage::encode(StunMessage::REFRESH_REQUEST, $txid, [
                [StunMessage::ATTR_LIFETIME, pack('N', 0)],
                [StunMessage::ATTR_USERNAME, $username],
                [StunMessage::ATTR_REALM, $realm],
                [StunMessage::ATTR_NONCE, $nonce],
            ], $key), $txid);
        } catch (ProbeException) {
            // The allocation expires on its own; the probe's answer is already known.
        }
    }

    private static function describeError(StunMessage $message): string
    {
        $error = $message->errorCode();

        return $error === null
            ? sprintf('STUN message type 0x%04x', $message->type)
            : trim($error['code'] . ' ' . $error['reason']);
    }
}
