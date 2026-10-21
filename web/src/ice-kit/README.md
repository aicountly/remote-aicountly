# AICOUNTLY ICE kit — browser half (v1.2.0)

Plain JavaScript (ES2020 modules) with `index.d.ts` beside it, so Remote (TypeScript) and Connect
(JavaScript) carry the same files at `web/src/ice-kit`. The server half is `lib/ice-kit` (PHP).

> **Copy, don't edit.** Fix it here, bump `VERSION`, copy the folder into every product again.

| Export | What |
|---|---|
| `normalizeIceConfig(raw)` | Any product's ICE response (Remote's `ice`, Connect's `/calls/config`, a room's `ice`) → one frozen config, timed on *this* clock |
| `rtcConfiguration(config, overrides)` | An `RTCConfiguration` (bundle and rtcp-mux fixed) |
| `applyIceConfig(pc, config)` | Fresh servers onto a live connection; used from the next ICE restart; never loosens relay-only |
| `needsRefresh` / `isExpired` | Judged against `refreshAt` / `expiresAt` |
| `relayNodeForUrl(config, url)` | Which relay node a URL belongs to |
| `ConnectionSupervisor` | *When* to restart ICE: grace for `disconnected`, at once for `failed`, backoff, fresh credentials from the second attempt, waits out offline, gives up honestly; `retry()` after |
| `CredentialRefresher` | Asks again at `refreshAt`, shares concurrent requests, retries with backoff; a renewal denied for capacity is not adopted while a working relay is held (`onDenied`) |
| `IceDiagnostics` | Candidate types gathered, ICE errors by category and node, selected path from `getStats()`, a verdict, and the report the server's allow-list accepts — no address, SDP or credential ever |
| `LatencyMeter` | Times each relay node with the credential the API just issued (a relay-candidate gather), remembers the result for hours, and hands it back as `rtt` for the next request so the server can route by the real network path. Measure after a call has connected |
| `probeRelay(config)` | Relay-only gathering per node: proof this browser can get a relay |
| `isRestartDriver(self, peer)` | Which side of a pair restarts ICE when there is no perfect negotiation |

*How* to restart is the product's: Connect calls `pc.restartIce()` (perfect negotiation); Remote's
driver offers with `iceRestart: true` and the other side asks it to with `renegotiate`.

## Tests

```bash
node --test "src/ice-kit/tests/*.test.mjs"       # part of each product's own test script
```

## Relay check in a real browser

`tools/relay-check.mjs` runs the kit in Chromium against a real relay: per-node relay allocation, two
relay-only peers exchanging data, and an ICE restart onto a second grant. Use it to validate a node
before setting it `active`:

```bash
npm install --no-save playwright
# grant.json / grant2.json: the `ice` of a Remote signalling-token response, or Connect's
# GET /api/calls/config, fetched a minute apart. WEBRTC_FORCE_RELAY is not needed.
ICE_GRANT=grant.json ICE_FRESH_GRANT=grant2.json node src/ice-kit/tools/relay-check.mjs
```
