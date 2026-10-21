<?php

declare(strict_types=1);

namespace App\Domain\Signalling;

use Aicountly\Ice\ClientContext;
use Aicountly\Ice\GeoHeaders;
use Aicountly\Ice\IceGrant;
use Aicountly\Ice\IceIssuer;
use Aicountly\Ice\IceRuntime;
use Aicountly\Ice\Tier;
use Aicountly\Ice\Topology;
use Config\Remote as RemoteConfig;
use Config\Services;

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
 * Nodes are regional peers (Mumbai, Greater Noida): each client is offered the best of them for it, and
 * every grant is accounted to a licence tier so the paid reserve of each node holds across fallback
 * (docs/ACTIVE_ACTIVE_TURN.md). Remote's tier is the plan of the company that owns the session:
 * `WEBRTC_TURN_PAID_PLAN_CODES` lists the `remote_entitlements.plan_code` values that are paid; any other
 * plan, an unknown company, or a failed lookup is free.
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
     * @param array{tier?: Tier, sessionRef?: string, profile?: string, client?: ClientContext} $context
     *        from contextFor(): who is asking, for which session, from where
     */
    public function grant(string $subject, ?string $preferRegion = null, ?int $ttl = null, array $context = []): IceGrant
    {
        return $this->issuer()->issue(
            $subject,
            ['preferRegion' => $preferRegion, 'profile' => 'remote'] + $context + ($ttl !== null ? ['ttl' => $ttl] : []),
        );
    }

    /**
     * A grant for a client that cannot renew — the desktop agent builds its ICE configuration once, at
     * join. Valid for the rest of the session (plus five minutes), so anything the agent has to gather
     * again during it — after a network change, or a restart the browser asks for — still authenticates.
     *
     * @param int $secondsRemaining how long the session has left
     * @param array<string, mixed> $context see grant()
     */
    public function grantForSession(string $subject, int $secondsRemaining, array $context = []): IceGrant
    {
        return $this->grant($subject, null, $this->topology()->sessionBoundTtl($secondsRemaining), $context);
    }

    /**
     * What the issuer needs to know about one request: the licence tier of the company that owns the
     * session, the session the capacity is reserved for, and where the client is.
     *
     * @param array<string, mixed>  $session
     * @param array<string, mixed>  $body    the request body (`region`, `rtt`)
     * @param callable(string): (string|null) $header reads one request header
     * @return array{tier: Tier, sessionRef: string, profile: string, client: ClientContext}
     */
    public function contextFor(array $session, array $body, callable $header): array
    {
        $geo = GeoHeaders::fromEnvironment(static fn (string $key): ?string => is_scalar($v = env($key)) ? (string) $v : null);

        return [
            'tier'       => $this->tierForSession($session),
            'sessionRef' => (string) ($session['uuid'] ?? ''),
            'profile'    => 'remote',
            'client'     => ClientContext::fromRequest($body, $geo->extract($header)),
        ];
    }

    /**
     * Paid only on positive, current evidence: the session's company has its OWN entitlement row, valid now
     * (`valid_from`/`valid_until`), updated recently enough (when a limit is set), on a plan the operator listed
     * as paid. Everything else is free:
     *
     *   * no company (a personal session), no row of its own, an expired or not-yet-valid row, a stale row;
     *   * the platform DEFAULT row (company_id NULL, seeded `REMOTE_PROFESSIONAL`) — it is a feature set for
     *     companies nobody has set up, not a subscription, and must never make a company paid;
     *   * a lookup that throws.
     *
     * @param array<string, mixed> $session
     */
    public function tierForSession(array $session): Tier
    {
        $paid = $this->config->turnPaidPlanCodes;
        if ($paid === [] || ($session['company_id'] ?? null) === null) {
            return Tier::Free;
        }

        try {
            $row = $this->entitlementRow((int) $session['company_id']);
        } catch (\Throwable) {
            return Tier::Free;
        }

        if ($row === null) {
            return Tier::Free;
        }

        $maxAge = $this->config->turnEntitlementMaxAgeHours;
        if ($maxAge > 0 && isset($row['updated_at']) && strtotime((string) $row['updated_at']) < time() - $maxAge * 3600) {
            return Tier::Free;
        }

        return in_array(strtoupper((string) ($row['plan_code'] ?? '')), $paid, true) ? Tier::Paid : Tier::Free;
    }

    /**
     * The company's own entitlement row, valid right now — never the platform default.
     *
     * @return array<string, mixed>|null
     */
    private function entitlementRow(int $companyId): ?array
    {
        return \Config\Database::connect()->table('remote_entitlements')
            ->select('plan_code, updated_at')
            ->where('company_id', $companyId)
            ->groupStart()->where('valid_from', null)->orWhere('valid_from <=', 'NOW()', false)->groupEnd()
            ->groupStart()->where('valid_until', null)->orWhere('valid_until >', 'NOW()', false)->groupEnd()
            ->get()
            ->getRowArray();
    }

    /**
     * Why enforcement must not start yet — empty when who-is-paid is known, current and complete. Consulted only when
     * `WEBRTC_TURN_ADMISSION=enforce`; while this list is not empty the application observes instead.
     *
     * @return list<string>
     */
    public function licenceBlockers(): array
    {
        $c       = $this->config;
        $reasons = [];

        if ($c->turnPaidPlanCodes === []) {
            $reasons[] = 'WEBRTC_TURN_PAID_PLAN_CODES is empty: no plan is mapped to paid';
        }
        if (! $c->turnLicenceFeedConfirmed) {
            $reasons[] = 'WEBRTC_TURN_LICENCE_FEED is not "confirmed": nothing attests that company entitlements are fed from the billing system of record';
        }
        if ($c->turnEntitlementMaxAgeHours <= 0) {
            $reasons[] = 'WEBRTC_TURN_ENTITLEMENT_MAX_AGE_HOURS is not set: a stopped feed would leave companies paid forever';
        }
        if (in_array('REMOTE_FREE', $c->turnPaidPlanCodes, true)) {
            $reasons[] = 'REMOTE_FREE is listed as paid';
        }

        try {
            $codes = array_map(
                static fn (array $r): string => strtoupper((string) $r['plan_code']),
                \Config\Database::connect()->query('SELECT DISTINCT plan_code FROM remote_entitlements WHERE company_id IS NOT NULL')->getResultArray(),
            );
            $unmapped = array_values(array_diff($codes, $c->turnPaidPlanCodes, $c->turnFreePlanCodes));
            if ($unmapped !== []) {
                $reasons[] = 'plan codes in use with no paid/free mapping: ' . implode(', ', array_slice($unmapped, 0, 10));
            }
            if ($codes === []) {
                $reasons[] = 'no company has an entitlement row: the feed has not populated remote_entitlements';
            }
        } catch (\Throwable) {
            $reasons[] = 'remote_entitlements could not be read';
        }

        return $reasons;
    }

    /** Free what a session (or one participant of it) held on the relays. Best effort: never throws. */
    public function releaseSession(string $sessionUuid, ?string $participantUuid = null): void
    {
        try {
            $this->issuer()->release($sessionUuid, $participantUuid);
        } catch (\Throwable) {
            // Capacity frees itself when the credentials expire; teardown must not fail over bookkeeping.
        }
    }

    /** A client said how it connected: charge the bandwidth where the traffic really is. */
    public function noteConnection(string $participantUuid, string $sessionUuid, string $path, ?string $nodeId): void
    {
        try {
            $this->issuer()->noteConnection($participantUuid, $sessionUuid, $path, $nodeId);
        } catch (\Throwable) {
        }
    }

    /** @param string $clientKey an opaque, stable reference to the client (the participant uuid); kept only as a hash */
    public function learnLatency(ClientContext $client, string $clientKey = ''): void
    {
        try {
            $this->issuer()->learnLatency($client, $clientKey);
        } catch (\Throwable) {
        }
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
        return $this->issuer ??= IceRuntime::issuer(
            static fn (string $key): ?string => is_scalar($v = env($key)) ? (string) $v : null,
            WRITEPATH,
            'remote',
            // One log line per decision: tier, profile, outcome, nodes and their load. No subject, no credential.
            static function (string $event, array $data): void {
                log_message('info', 'remote.ice.' . $event . ' {data}', ['data' => json_encode($data, JSON_UNESCAPED_SLASHES)]);
            },
            $this->topology(),
            null,
            [],
            fn (): array => $this->licenceBlockers(),
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
            restartOnRenewal: $this->config->turnRestartOnRenewal,
            sessionTtlMax: $this->config->turnSessionTtlMax,
        );
    }
}
