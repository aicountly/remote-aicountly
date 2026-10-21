# AICOUNTLY ICE kit (v1.4.0)

The one way an AICOUNTLY product decides **which TURN relays a client may use** and **mints the
short-lived credential for each**. Remote (`backend/lib/ice-kit`) and Connect
(`server-php/lib/ice-kit`) carry identical copies; the browser half is `web/src/ice-kit` in each.

> **Copy, don't edit.** Fix bugs here, bump `VERSION`, add a `CHANGELOG.md` entry, and copy the whole
> folder into every product again. `diff -r` between two products' copies must be empty.

PHP ≥ 8.2, no extensions beyond the standard ones (`openssl` for the TLS probe), no Composer, no
framework. Namespace `Aicountly\Ice`. `require_once autoload.php`, or map the prefix to `src/`.

## What it does

| File | What |
|---|---|
| `src/Topology.php` | Every node and the shared settings, from the `WEBRTC_*` environment keys both products use |
| `src/TurnNode.php` | One node: host, status, priority, region, transports, ports, secret (never printed) |
| `src/NodeStatus.php` | `active` · `standby` (served only when no active node is healthy) · `disabled` (default) |
| `src/CredentialMinter.php` | coturn REST credentials: `"<expiry>:<subject>"` and `base64(HMAC-SHA1(username, secret))` |
| `src/IceIssuer.php` | Selection (status → health → region hint → priority → cap) and minting — the product entry point |
| `src/IceGrant.php` | The `aicountly.ice/1` contract a product returns |
| `src/HealthStore.php` | The probe's results, one JSON file; biased hard towards serving a relay |
| `src/ConnectivityReport.php` | Allow-list for a client's connectivity report before it is logged |
| `src/Probe/*` | A STUN/TURN client (RFC 5389/5766): binding and a real authenticated allocation over udp/tcp/tls |
| `bin/probe.php` | CLI: probe nodes, record health, show what the API would serve |
| `tests/` | PHPUnit; the live tests run against a real coturn when `ICE_KIT_TEST_TURN_*` is set |

What it deliberately does not do: talk to coturn's admin interface, change any server configuration,
or know anything about signalling or SFUs. A TURN relay forwards one client's encrypted packets to
whatever that client is talking to; it is not a media server.

## Configuration

```
WEBRTC_TURN_NODES = mum,ncr                       # comma-separated, NO spaces (CI4 .env rule)
WEBRTC_TURN_NODE_MUM_HOST = turn-mum.aicountly.com
WEBRTC_TURN_NODE_MUM_STATUS = active              # active | standby | disabled (default)
WEBRTC_TURN_NODE_MUM_PRIORITY = 10                # lower is preferred (default 100)
WEBRTC_TURN_NODE_MUM_REGION = in-west             # matched against a client's region hint
WEBRTC_TURN_NODE_MUM_LABEL = Mumbai
WEBRTC_TURN_NODE_MUM_TRANSPORTS = udp,tcp,tls     # default all three
WEBRTC_TURN_NODE_MUM_PORT = 3478                  # default 3478
WEBRTC_TURN_NODE_MUM_TLS_PORT = 443               # default 5349
WEBRTC_TURN_NODE_MUM_STUN = true                  # also offer stun:<host>:<port>
WEBRTC_TURN_NODE_MUM_SECRET =                     # optional; default is the shared secret
WEBRTC_TURN_STATIC_AUTH_SECRET = <coturn static-auth-secret>
WEBRTC_TURN_TTL = 3600                            # 60..86400
WEBRTC_TURN_MAX_NODES = 2                         # 1..4
WEBRTC_STUN_URLS =                                # extra STUN after the nodes' own
WEBRTC_FORCE_RELAY = false                        # diagnostics only; ignored when no relay exists
WEBRTC_ICE_CANDIDATE_POOL = 0
WEBRTC_TURN_HEALTH_FILE / _FAILURES / _MAX_AGE    # writable/ice/turn-health.json, 2, 900
```

Without `WEBRTC_TURN_NODES`, the single-pool keys (`WEBRTC_TURN_URLS` with the secret, or
`WEBRTC_TURN_USERNAME`/`WEBRTC_TURN_CREDENTIAL`) become one node called `default` — the products'
configuration before this kit keeps working unchanged. A node that is misconfigured is left out and
reported in `Topology::problems()`; it never takes the API or another node down.

## Using it

```php
require_once ROOTPATH . 'lib/ice-kit/autoload.php';

$issuer = new Aicountly\Ice\IceIssuer(
    Aicountly\Ice\Topology::fromEnvironment(fn ($k) => env($k), ['stunUrls' => ['stun:stun.l.google.com:19302']]),
    Aicountly\Ice\HealthStore::fromEnvironment(fn ($k) => env($k), WRITEPATH . 'ice/turn-health.json'),
);

$grant = $issuer->issue($participantUuid, ['preferRegion' => $hint]);   // never an email address
return $grant->toArray();                                                 // aicountly.ice/1
```

`toArray()`: `contract`, `iceServers` (exactly `RTCIceServer[]`), `iceTransportPolicy`,
`iceCandidatePoolSize`, `relayAvailable`, `issuedAt`, `expiresAt`, `expiresIn`, `refreshAfter`
(80% of the lifetime), `relayNodes` (id, label, region, priority, status, credential mode, urls,
health — never a credential), `degraded` (served despite failing health), `failover` (standby
served).

## Probing

```bash
php lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --status            # what would be served
php lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --allocate          # every node not disabled
php lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --node=ncr --allocate   # validate a disabled node
php lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --allocate --write-health   # the cron job

# the launch validation (docs/TURN_VALIDATION_RUNBOOK.md):
php lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --node=mum --allocate --negative --expect-ip=mum=148.135.210.65
php lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --node=mum --transports=tcp --expiry-test=tcp
php lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --node=mum --transports=tls --emit-grant=/tmp/g.json --ttl=900
```

`--allocate` mints a credential exactly as the API does and asks the node to allocate a relay over
each transport, then releases it. It is the test that catches a secret mismatch (`auth_rejected`),
a full relay range (`insufficient_capacity`), a TLS certificate problem (`tls_failed`) and a firewall
(`connect_failed`/`timeout`) — none of which a STUN answer would reveal. An allocation granted with no
credential at all is reported as `open_relay`, a finding rather than a success.

## Active-active routing and admission

Nodes are regional peers. `IceIssuer::issue()` takes the client's `tier` (`paid`/`free`, unknown = free), a usage
`profile`, a `sessionRef` and a `ClientContext`; ranks the nodes by proximity, load and health; checks each
against its capacity and the paid reserve (`Admission`); and writes the lease in the same locked step.
`release()` frees a finished session, `noteConnection()` moves the bandwidth charge to where the traffic is,
`learnLatency()` folds a client's measured round trips into its region's average. See
`docs/ACTIVE_ACTIVE_TURN.md` in the product for the design, thresholds and what is proposed rather than built.

```bash
php lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --status --explain   # capacity, load, what each tier is offered
php lib/ice-kit/bin/summarize-usage.php /var/log/turnserver/*.log --window=3600   # traffic per tier, from the relay's log
bash lib/ice-kit/bin/turn-node-load.sh --node=mum | php lib/ice-kit/bin/ingest-load.php --bootstrap=config/ice-kit.php
bash lib/ice-kit/tests/simulation/active-active.sh                                 # local relays; logic only
```

## Selection and failover

Active nodes that are not `down` are served; standby nodes join whenever no active node is fully working;
if every servable node is down they are all offered, flagged `degraded`. Working nodes lead, then the region
hint, then priority, and the per-client cap is applied after that. `IceIssuer::status()` reports each node's
role, condition (`healthy | degraded | unreachable | unknown`), state and whether it is being served.

## Health rules

A node is skipped only after `WEBRTC_TURN_HEALTH_FAILURES` consecutive failed probes; results older
than `WEBRTC_TURN_HEALTH_MAX_AGE` are ignored; a missing or corrupt file means "no information"; and
the issuer never removes the last servable node on the file's word — it serves it with
`degraded: true`. A disabled node is never served, whatever its health.

## Tests

```bash
vendor/bin/phpunit lib/ice-kit/tests     # via each product's phpunit configuration

# the live tests, against a coturn with use-auth-secret:
ICE_KIT_TEST_TURN_HOST=localhost ICE_KIT_TEST_TURN_SECRET=… ICE_KIT_TEST_TURN_CA=cert.pem vendor/bin/phpunit lib/ice-kit/tests
```

The CI of both products starts a throwaway coturn for these. A local one:

```bash
turnserver -o --listening-ip=127.0.0.1 --relay-ip=127.0.0.1 --realm=test --use-auth-secret \
  --static-auth-secret=local --cert=cert.pem --pkey=key.pem --no-cli --allow-loopback-peers
```

## Licence entitlements (v1.4)

Who is *paid* is decided by the **central licensing backend**; products only consume its signed entitlements through `Aicountly\Ice\Licence`
(`docs/LICENCE_ENTITLEMENT_CONTRACT.md`). `LicenceResolver::forCustomer()` / `forSubject()` return a `Resolution` — state
(free / paid / expired / suspended / unknown), reason, evidence (`central` / `compat` / `none`) — and only a valid, fresh, unrevoked,
signature-verified, tenant-matched `paid` is `Tier::Paid`. `bin/sync-entitlements.php` keeps the cache current from the backend's snapshot and
changes feed; `bin/entitlement-fixture.php` signs and loads test entitlements for staging; `tests/contract/licence-v1-vectors.json` are the
conformance vectors. `LicenceReadiness` keeps `WEBRTC_TURN_ADMISSION=enforce` at `observe` until the central source is operational.
