<?php

declare(strict_types=1);

namespace App\Domain\Signalling;

use Aicountly\Ice\ClientContext;
use Aicountly\Ice\GeoHeaders;
use Aicountly\Ice\IceGrant;
use Aicountly\Ice\IceIssuer;
use Aicountly\Ice\IceRuntime;
use Aicountly\Ice\Licence\LicenceResolver;
use Aicountly\Ice\Licence\LicenceRuntime;
use Aicountly\Ice\Licence\LicenceState;
use Aicountly\Ice\Licence\Resolution;
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
 * (docs/ACTIVE_ACTIVE_TURN.md). Remote's tier is the licence state of the company that owns the session, as the
 * CENTRAL licensing backend states it (docs/LICENCE_ENTITLEMENT_CONTRACT.md): a signed entitlement read through the common
 * adapter (lib/ice-kit, Licence\LicenceResolver). Remote keeps no licensing authority of its own. Until that backend exists,
 * a company's own `remote_entitlements.plan_code` on `WEBRTC_TURN_PAID_PLAN_CODES` is accepted as a development-time
 * compatibility mapping (evidence `compat`, honoured only while WEBRTC_LICENCE_COMPAT is not `deny`); any other plan, an
 * unknown company, a stale or unverifiable document, or a failed lookup is free.
 *
 * No credential is ever hardcoded, and the shared secret never leaves this server: a client receives
 * `<expiry>:<subject>` and its HMAC, useless after the TTL. A static pair never expires and reaches
 * every client, which is why it is only the fallback it always was.
 */
class IceConfigService
{
    private ?IceIssuer $issuer = null;

    /**
     * @param LicenceResolver|null          $licence   the common licence adapter; defaults to one built from the environment
     * @param (\Closure(): list<string>)|null $readiness why paid status cannot be trusted yet (empty = ready); defaults to the
     *        adapter's central-source check. Seams for tests.
     */
    public function __construct(
        private readonly RemoteConfig $config,
        private ?LicenceResolver $licence = null,
        private readonly ?\Closure $readiness = null,
    ) {
    }

    /** The product id Remote is known by at the central licensing backend (Manage's app_key). */
    public const LICENCE_PRODUCT = 'remote';

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
     * @return array{tier: Tier, licence: array<string, mixed>, sessionRef: string, profile: string, client: ClientContext}
     */
    public function contextFor(array $session, array $body, callable $header): array
    {
        $geo = GeoHeaders::fromEnvironment(static fn (string $key): ?string => is_scalar($v = env($key)) ? (string) $v : null);

        $licence = $this->licenceForSession($session);

        return [
            'tier'       => $licence->tier(),
            'licence'    => $licence->audit(),
            'sessionRef' => (string) ($session['uuid'] ?? ''),
            'profile'    => 'remote',
            'client'     => ClientContext::fromRequest($body, $geo->extract($header)),
        ];
    }

    /**
     * Paid only on trusted server-side evidence: the central backend's signed, fresh, unrevoked entitlement for the
     * company that owns the session, in state `paid` (Licence\LicenceResolver states every condition). Everything else is
     * free — a personal session with no company, no entitlement, an expired or suspended licence, a stale or unverifiable
     * document, a lookup that fails. Nothing the client sent is consulted.
     *
     * @param array<string, mixed> $session
     */
    public function tierForSession(array $session): Tier
    {
        return $this->licenceForSession($session)->tier();
    }

    /**
     * The licence resolution for the company that owns the session: state (free / paid / expired / suspended / unknown),
     * the reason, and how much the evidence is worth (`central` / `compat` / `none`).
     *
     * @param array<string, mixed> $session
     */
    public function licenceForSession(array $session): Resolution
    {
        $company = $session['company_id'] ?? null;
        if ($company === null || $company === '') {
            return Resolution::unknown('no_customer');
        }

        try {
            return $this->licence()->forCustomer(
                (string) (int) $company,
                self::LICENCE_PRODUCT,
                fn (): ?Resolution => $this->compatResolution((int) $company),
            );
        } catch (\Throwable) {
            return Resolution::unknown('lookup_failed');
        }
    }

    public function licence(): LicenceResolver
    {
        return $this->licence ??= LicenceRuntime::resolver(
            static fn (string $key): ?string => is_scalar($v = env($key)) ? (string) $v : null,
            WRITEPATH,
        );
    }

    /**
     * DEVELOPMENT-TIME COMPATIBILITY: the company's own `remote_entitlements` row, valid now and recently updated, on a plan
     * the operator listed as paid. Evidence `compat` — never accepted for enforcement. The platform DEFAULT row
     * (company_id NULL, seeded `REMOTE_PROFESSIONAL`) is a feature set for companies nobody has set up, not a subscription, and
     * never makes a company paid. Returns null when there is nothing to say (so the answer stays `unknown`).
     */
    private function compatResolution(int $companyId): ?Resolution
    {
        $paid = $this->config->turnPaidPlanCodes;
        if ($paid === []) {
            return null;
        }

        $row = \Config\Database::connect()->table('remote_entitlements')
            ->select('plan_code, updated_at')
            ->where('company_id', $companyId)
            ->groupStart()->where('valid_from', null)->orWhere('valid_from <=', 'NOW()', false)->groupEnd()
            ->groupStart()->where('valid_until', null)->orWhere('valid_until >', 'NOW()', false)->groupEnd()
            ->get()
            ->getRowArray();

        if ($row === null) {
            return null;
        }

        $maxAge = $this->config->turnEntitlementMaxAgeHours;
        if ($maxAge > 0 && isset($row['updated_at']) && strtotime((string) $row['updated_at']) < time() - $maxAge * 3600) {
            return LicenceResolver::compat(LicenceState::Unknown, 'compat_stale');
        }

        $plan = strtoupper((string) ($row['plan_code'] ?? ''));

        return in_array($plan, $paid, true)
            ? LicenceResolver::compat(LicenceState::Paid, 'compat_plan_paid', $plan)
            : LicenceResolver::compat(LicenceState::Free, 'compat_plan_not_paid', $plan);
    }

    /**
     * Why hard enforcement must not start yet — empty only when the central licence source is operational and validated.
     * Consulted only when `WEBRTC_TURN_ADMISSION=enforce`; while this list is not empty the application observes instead.
     *
     * @return list<string>
     */
    public function licenceBlockers(): array
    {
        if ($this->readiness !== null) {
            return ($this->readiness)();
        }

        return (LicenceRuntime::readiness(
            static fn (string $key): ?string => is_scalar($v = env($key)) ? (string) $v : null,
            WRITEPATH,
            self::LICENCE_PRODUCT,
        ))();
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
