<?php

declare(strict_types=1);

namespace Aicountly\Ice;

/**
 * One client's ICE configuration: what to put in an RTCConfiguration, until when it is good, and which
 * relay nodes it names — the `aicountly.ice/1` contract both products return.
 *
 * `iceServers` is exactly the browser's RTCIceServer[] shape and nothing more, so it can be passed to
 * `new RTCPeerConnection()` (or the desktop agent's webrtc-rs) untouched. Everything a client needs to
 * *reason* about the relays — node ids, regions, which URL belongs to which node — sits beside it in
 * `relayNodes`, which carries no credential.
 */
final class IceGrant
{
    public const CONTRACT = 'aicountly.ice/1';

    /**
     * @param list<array{urls: list<string>, username?: string, credential?: string}> $iceServers
     * @param list<array<string, mixed>>                                               $relayNodes describe() of each node, plus `health`
     */
    public function __construct(
        private readonly array $iceServers,
        private readonly array $relayNodes,
        public readonly int $issuedAt,
        public readonly ?int $expiresAt,
        public readonly bool $forceRelay = false,
        public readonly int $candidatePoolSize = 0,
        public readonly bool $degraded = false,
        public readonly bool $failover = false,
    ) {
    }

    /** @return list<array{urls: list<string>, username?: string, credential?: string}> */
    public function iceServers(): array
    {
        return $this->iceServers;
    }

    /** @return list<array<string, mixed>> */
    public function relayNodes(): array
    {
        return $this->relayNodes;
    }

    public function relayAvailable(): bool
    {
        return $this->relayNodes !== [];
    }

    public function expiresIn(): ?int
    {
        return $this->expiresAt === null ? null : max(0, $this->expiresAt - $this->issuedAt);
    }

    /**
     * When the client should ask again: at 80% of the lifetime, so a fresh credential is in hand well
     * before coturn starts refusing the old one's allocation refreshes.
     */
    public function refreshAfter(): ?int
    {
        $ttl = $this->expiresIn();

        return $ttl === null ? null : max(30, (int) floor($ttl * 0.8));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'contract'             => self::CONTRACT,
            'iceServers'           => $this->iceServers,
            'iceTransportPolicy'   => $this->forceRelay ? 'relay' : 'all',
            'iceCandidatePoolSize' => $this->candidatePoolSize,
            'relayAvailable'       => $this->relayAvailable(),
            'issuedAt'             => gmdate('c', $this->issuedAt),
            'expiresAt'            => $this->expiresAt === null ? null : gmdate('c', $this->expiresAt),
            'expiresIn'            => $this->expiresIn(),
            'refreshAfter'         => $this->refreshAfter(),
            'relayNodes'           => $this->relayNodes,
            'degraded'             => $this->degraded,
            'failover'             => $this->failover,
        ];
    }
}
