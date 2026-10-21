<?php

declare(strict_types=1);

namespace Aicountly\Ice\Probe;

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
     * @return array{id: string, ok: bool, reachable: bool, allocated: bool|null, transports: array<string, array<string, mixed>>, error: string|null}
     */
    public function probeNode(TurnNode $node, ?array $transports = null, bool $allocate = false, int $ttlSeconds = 300, string $subject = 'probe'): array
    {
        $results   = [];
        $reachable = false;
        $allocated = $allocate ? false : null;
        $error     = null;

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

            $error ??= $step['binding']['ok'] ? null : ($step['binding']['error'] ?? null);
            $results[$key] = $step;
        }

        $ok = $allocate ? (bool) $allocated : $reachable;

        return [
            'id'         => $node->id,
            'ok'         => $ok && $results !== [],
            'reachable'  => $reachable,
            'allocated'  => $allocated,
            'transports' => $results,
            'error'      => $ok ? null : ($error ?? 'no_transport'),
        ];
    }

    /** @return array{ok: bool, rttMs?: int, error?: string, detail?: string} */
    public function binding(string $transport, string $host, int $port): array
    {
        $started = microtime(true);

        try {
            $socket = StunSocket::open($transport, $host, $port, $this->timeout, $this->tls);
            $txid   = StunMessage::newTransactionId();
            $reply  = $socket->exchange(
                StunMessage::encode(StunMessage::BINDING_REQUEST, $txid, [[StunMessage::ATTR_SOFTWARE, self::SOFTWARE]]),
                $txid,
            );
            $socket->close();
        } catch (ProbeException $e) {
            return ['ok' => false, 'error' => $e->reason, 'detail' => $e->getMessage()];
        }

        if ($reply->type !== StunMessage::BINDING_SUCCESS) {
            return ['ok' => false, 'error' => 'binding_refused'];
        }

        return ['ok' => true, 'rttMs' => (int) round((microtime(true) - $started) * 1000)];
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
