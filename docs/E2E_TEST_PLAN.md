# End-to-end test plan — Remote and Connect over the Mumbai / Greater Noida relays

Identical in `aicountly/remote-aicountly` and `aicountly/connect-aicountly`. It starts where the production TURN authentication checks
(`docs/production/TURN_PRODUCTION_SECRETS.md`) stop. Those checks prove the API can mint credentials the relays accept. This plan proves
that browsers, and then the products, actually carry calls and sessions through the relays.

| Stage | What it proves | How | Who |
|---|---|---|---|
| 1 | A real browser carries **media and data** through each relay, with credentials **the API host minted** | `E2E — browser through the TURN relays` workflow (`e2e-relay-browser.yml`), sandbox then production | automated (CI) |
| 2 | The **products** carry it: sign-in, admission, Remote screen and control, the desktop agent, Connect calls and rooms | Checklists below, on the GH sandbox first, with relay forced there | people + test accounts + devices |
| 3 | Real networks and regions: nearest node, fallback, restrictive networks | Stage 2 repeated from a Mumbai-region and a Delhi-region network, plus one UDP-blocked network | people |

Passing an earlier stage never stands in for a later one.

## Stage 1 — browser relay E2E (automated)

Run it from **Actions → E2E — browser through the TURN relays**, on `main`, first with `target = sandbox` and then with
`target = production`. Each target can be run from either repository; each repository mints with its own API.

How it works:

* The runner asks the chosen API host for **300-second credentials** (`scripts/ci/emit-grant.sh`), minted with that host's own node
  secrets. The node secret never leaves the host.
* The credentials are masked in the log, never printed and deleted at the end.
* Headless Chromium then runs `web/src/ice-kit/tools/relay-check.mjs` with **two relay-only peers**. A connection can only succeed through
  the relays.

| Case | Real-world meaning | Must show |
|---|---|---|
| `x-udp/tcp/tls-mum-ncr`, `x-…-ncr-mum` | Mumbai-region user ↔ Delhi-region user, each on their nearest relay, over UDP 3478, TCP 3478 and TLS 443 | path `relay` via the expected node and transport on both sides; video decoded (≥ 30 frames); audio received; 5 echo round trips; 1 MiB transfer |
| `same-mum`, `same-ncr` | two users on the same relay | the same |
| `restart-mum` | a long session renews credentials | after an ICE restart onto fresh credentials, data still flows, still relayed |

The results are annotations, ending with a `summary`. The JSON per case (paths, candidates, stats, timings, no credential) is uploaded as an
artifact. Echo RTT and bulk Mbit/s are measured from a GitHub runner, which is usually outside India. **Read them as a sanity floor, not as
user latency** (stage 3 measures that).

If `same-*` fails while the `x-*` cases pass, the relay refuses peers at its own address (`denied-peer-ip` / own-IP policy). Two users who
both need the relay and share a node would then fail. That is a coturn configuration finding to report to the infrastructure owner. Do not
change it here.

## Stage 2 — product flows (GH sandbox first)

Prerequisites (**owner decisions**, see the end of this document):

* `WEBRTC_FORCE_RELAY=true` in the **sandbox** API `.env` of the product under test, for the duration of the tests only. It is never set in
  production. Every connection is then relay-only, so a pass proves the relay carried it. Remove it afterwards.
* Test accounts:
  * **A, B:** two users in the same company, one with a paid plan if available.
  * **C:** a user in a different company.
  * **G:** one external guest, for Connect meetings.
* For Remote: one Windows machine with the sandbox desktop agent enrolled.
* Two browsers, ideally on two different networks.

### Remote (`remote.gh.aicountly.com`)

| # | Do | Expect |
|---|---|---|
| R1 | A starts a session; B joins with the code; A admits B | B gets no TURN credentials before admission (`…/ice-servers` → `403 AWAITING_APPROVAL`, then `200`) |
| R2 | A shares the screen; chat both ways; B sends A a file | Picture, chat and file arrive. Security panel on both: *through AICOUNTLY's Mumbai/Noida relay*, with the transport |
| R3 | Security panel → **Check relay connection** | *The relay is reachable from this network (…)* |
| R4 | Unattended connection to the enrolled **desktop agent**; request control; click and type | Picture and control work; the panel names the relay and node |
| R5 | Viewer offline ~10 s, then back | *Reconnecting…* then *Connected*; the report shows `restarts ≥ 1`, outcome `recovered` |
| R6 | Offline > 30 s | *Unable to reconnect* with a reason, and a working **Reconnect** button |
| R7 | Keep a relayed session open past one credential lifetime | Stays connected; `credentialRenewals ≥ 1` |
| R8 | A ends the session | Both sides close; `…/ice-servers` → `409 SESSION_ALREADY_ENDED` |
| R9 | C (other company) tries to join A's session or fetch its credentials | Refused; no credentials |

Evidence: `php lib/ice-kit/bin/summarize-reports.php writable/logs/log-<date>.log` on the sandbox API host, which shows relayed sessions by
node and transport, time to connect, restarts and renewals.

### Connect (`connect.gh.aicountly.com`)

| # | Do | Expect |
|---|---|---|
| C1 | A calls B (1:1 audio) | Rings, connects; the call surface shows **Relayed**; both hear audio |
| C2 | Upgrade to video; screen share | Both decode video; share visible |
| C3 | A in a room/meeting with B and external guest G | All three connected through the relay; G admitted under A's meeting; with the plan's guest limit (5) respected |
| C4 | One side offline ~10 s | Recovers without a new call |
| C5 | Call longer than one credential lifetime | Stays up (renewal) |
| C6 | C (other company) calls A with A's company context claimed | Licensed in C's own context only; nothing leaks across tenants |
| C7 | Mobile app (if a sandbox build exists) calls a browser | Connects and is relayed |

Evidence: `chrome://webrtc-internals` (selected candidate pair = `relay`), and the `connect.ice.report` lines in the sandbox API log.

## Stage 3 — real networks and regions (production allowed, relay NOT forced)

* From a **Mumbai-region** network and a **Delhi-region** network, run R2 and C1 in production with ordinary accounts. Note which node the
  Security panel or webrtc-internals names, and the time to connect. On an open network the path may be **direct** (that is correct; the
  relay is the fallback).
* From a **UDP-blocked** network (corporate guest Wi-Fi, a firewalled hotspot): R2 and C1 must still connect, **relayed over TCP or TLS
  443**.
* Fallback: no server is touched in production. The fallback logic is covered by `ACTIVE_ACTIVE_TURN.md` and the staging-only runbook Part D.

## Owner decisions needed for stages 2–3

1. **Who runs the browser and device tests**, and when.
2. **Test accounts:** A, B (same company), C (other company), G (external guest). If they should run unattended in CI later, their
   credentials go in GitHub secrets by the owner. They are never pasted into chat.
3. **Approval to set `WEBRTC_FORCE_RELAY=true` on the GH sandbox** (both products) while stage 2 runs, then remove it.
4. **A Windows machine** with the sandbox Remote desktop agent, for R4.
