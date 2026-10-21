<?php

declare(strict_types=1);

namespace App\Domain\Signalling;

use Aicountly\Ice\HealthStore;
use Aicountly\Ice\IceGrant;
use Aicountly\Ice\IceIssuer;
use Aicountly\Ice\Topology;
use Config\Remote as RemoteConfig;

/**
 * The ICE configuration handed to a browser or a desktop agent (§20).
 *
 * Remote's adapter over the AICOUNTLY ICE kit (lib/ice-kit), which Connect carries verbatim too. The
 * kit decides which TURN nodes a client gets — status, priority, region hint, probe health — and mints
 * a coturn REST credential per node; this class only says where Remote's configuration comes from:
 *
 *   * **Nodes** — WEBRTC_TURN_NODES and the WEBRTC_TURN_NODE_* keys, shared with every AICOUNTLY
 *     product. Mumbai active, Greater Noida disabled until it is validated. Preferred.
 *   * **Single pool** — the original `remote.turnUrls` with `remote.turnStaticAuthSecret` (or a static
 *     `remote.turnUsername`/`remote.turnCredential` pair), as one node. Used when no nodes are set.
 *
 * No credential is ever hardcoded, and the shared secret never leaves this server: a client receives
 * `<expiry>:<subject>` and its HMAC, useless after the TTL. A static pair never expires and reaches
 * every client, which is why it is only the fallback it always was.
 */
class IceConfigService
{
    private ?IceIssuer $issuer = null;

    public function __construct(private readonly RemoteConfig $config)
    {
    }

    /**
     * A credentialed ICE configuration for one client.
     *
     * @param string $subject an opaque reference bound into the TURN username — a participant uuid, or
     *                        `device-<uuid>` — so relay usage is attributable without an email address
     */
    public function grant(string $subject, ?string $preferRegion = null): IceGrant
    {
        return $this->issuer()->issue($subject, ['preferRegion' => $preferRegion]);
    }

    /**
     * Just the RTCIceServer list, for callers that predate the grant.
     *
     * @return list<array{urls: list<string>, username?: string, credential?: string}>
     */
    public function iceServers(string $subject = 'remote'): array
    {
        return $this->grant($subject)->iceServers();
    }

    /**
     * Whether a relay is available at all.
     *
     * The UI uses this to explain a failure honestly: without TURN, two peers
     * behind symmetric NAT simply cannot connect, and telling the user
     * "reconnecting" forever would be a lie.
     */
    public function hasRelay(): bool
    {
        return $this->issuer()->relayConfigured();
    }

    /**
     * Every node and its probe health, for the health endpoint. No secret, no credential.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return $this->issuer()->status();
    }

    public function issuer(): IceIssuer
    {
        return $this->issuer ??= new IceIssuer(
            $this->topology(),
            new HealthStore($this->config->turnHealthFile !== '' ? $this->config->turnHealthFile : WRITEPATH . 'ice/turn-health.json'),
            null,
            'remote',
        );
    }

    public function topology(): Topology
    {
        if ($this->config->turnTopology !== null) {
            return $this->config->turnTopology;
        }

        $legacy = Topology::legacyNode(
            $this->config->turnUrls,
            $this->config->turnStaticAuthSecret,
            $this->config->turnUsername,
            $this->config->turnCredential,
        );

        return new Topology(
            $legacy === null ? [] : [$legacy],
            $this->config->stunUrls,
            ttlSeconds: max(60, min(86400, $this->config->turnCredentialTtlSeconds)),
            maxRelayNodes: 1,
            source: 'legacy',
        );
    }
}
