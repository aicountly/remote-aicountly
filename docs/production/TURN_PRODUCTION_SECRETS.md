# Production TURN authentication — per-node secrets (Remote and Connect)

Identical in `aicountly/remote-aicountly` and `aicountly/connect-aicountly`.

## 1. What was wrong (2026-10-22 production deploys)

Remote run 39828598655 and Connect run 39828602358 deployed successfully, but their `TURN secrets` step received **empty**
`TURN_MUM_SECRET` and `TURN_NCR_SECRET`. The injector correctly ignored the empty values, which left production `api/.env` with no node secret.
The production TURN check then reported `relay configured: NO` and `allocation FAILED no_credentials` on both nodes. The job still showed
green, because that step had `continue-on-error: true` and the check script always exited 0.

**Why the values were empty.** If a secret does not exist in a scope the job can read, `${{ secrets.NAME }}` gives an empty string. GitHub
cannot store an empty secret. So for these jobs, `TURN_MUM_SECRET` and `TURN_NCR_SECRET` do not exist:

| Job | Secret scopes it can read |
|---|---|
| Remote `deploy-production.yml` | repository and organisation secrets only (the job declares no `environment:`) |
| Connect `deploy-prod-cpanel.yml` | repository and organisation secrets, plus the `production` environment |

The sandbox jobs also received empty `SANDBOX_TURN_MUM_SECRET` / `SANDBOX_TURN_NCR_SECRET`. The sandbox works only because the values were
placed directly in the staging `api/.env`. The production `.env` files never received them. Secret names cannot be listed from the build
tooling, so the exact miss is unconfirmed. Either the secrets were never created in GitHub, or they were created under another name or in
an environment the job does not use. For example, an environment secret in Remote is invisible to Remote's production job, which has no
environment.

## 2. Where the secrets live (system of record)

The **host `.env` is the system of record** for TURN node secrets, as it already is for staging. Each value came from its coturn server's
`static-auth-secret`. No new or alternative secret is created anywhere. GitHub secrets are **optional**: they are a rotation path only. When
a secret is set for the job, the deploy writes it over the host value. When it is not set, the host value is kept.

What changed so that a gap can no longer pass silently:

* **Presence check.** After the injector, the deploy runs `scripts/ci/turn-secret-presence.sh` on the host. It prints key names and
  `set` / `MISSING`, never a value. A missing secret for an active node is an `::error::`, and the job **fails at its end**, after the
  live-app check.
* **Strict TURN check.** `TURN check (read-only)` runs `turn-staging-check.sh … strict` without `continue-on-error`. The step fails unless the
  API reports `relay configured: yes` and both nodes pass allocation, the negative tests and the expected-IP check.
* **Check without a deploy.** The new `TURN check — Production (read-only)` workflow (`turn-check-production.yml`, `main` only) runs both
  checks against production with no build, rsync, migration or `.env` write.

If the owner chooses to keep the secrets in GitHub as well, create them **with exactly these names and the same values as the host**:
`TURN_MUM_SECRET` and `TURN_NCR_SECRET` as repository secrets (for Connect, the `production` environment also works). A different value
there would replace the working host value on the next deploy.

## 3. Populating production (root, on the cPanel host)

The four `.env` files are on one cPanel host. GH staging is `ghaicountly`; production is `remoteaicountly` and `connectaicountly`. The
secrets are copied **on that host**. They never pass through a workflow, a runner, a log, an annotation or a clipboard.
Use `scripts/ops/promote-turn-secrets.sh`, as root (WHM → Terminal, or root SSH).

What the script does:

1. **Sources.** It reads the two keys from both staging `.env` files and requires all of the following:
   * Each region's secret is identical in Remote and Connect staging.
   * Each key appears exactly once in each file.
   * Each value passes the injector's secret format.
   * Mumbai ≠ Noida.
2. **Production policy.** Before anything is written, it checks:
   * `WEBRTC_TURN_ADMISSION` is `observe`.
   * `WEBRTC_FORCE_RELAY` is unset or false.
   * `mum` and `ncr` are both listed and `active`, with the expected hosts.

   A mismatch stops the run. The script never changes policy.
3. **Targets.**
   * Each production `.env` must be a regular file, not a symlink, with one link, owned by its account user.
   * An existing production secret with a **different** value is not overwritten without `--replace-existing`.
4. **Backup.** It makes a full copy of each production `.env` to `/root/aicountly-turn-backups/<UTC>/` (dir 700, files 600). The manifest
   records owner, group, mode and SHA-256.
5. **Atomic write.** For each file:
   * A new file is built in the same directory, and only the two keys change.
   * The script proves that every other line is byte-identical.
   * It copies the owner, group, mode, ACL and SELinux label, then `rename(2)`s the new file over the old one.
   * If the second product fails, the first is restored.
6. **Read-only probes.** Run as each account user, never root, using the app's own `probe.php`:
   * For each node: `--allocate --negative --expect-ip`, covering UDP 3478, TCP 3478 and TLS 443, and the wrong, expired and anonymous
     refusals.
   * One node-health refresh, the same command the `turn-probe` cron runs every 5 minutes.
   * `--status`. Probe output passes through a redactor.

Nothing the script prints contains a secret or a hash of one.

Getting the script onto the host: copy `scripts/ops/promote-turn-secrets.sh` from this repository (for example
`/root/promote-turn-secrets.sh`, mode 700). Compare `sha256sum` with the copy in GitHub. Then run:

```bash
bash /root/promote-turn-secrets.sh check     # read-only: sources agree? targets safe? policy as expected? what would change
bash /root/promote-turn-secrets.sh apply     # back up, write atomically, verify files, probe both APIs; exit 0 only if all pass
bash /root/promote-turn-secrets.sh verify    # probes only, any time later
bash /root/promote-turn-secrets.sh rollback /root/aicountly-turn-backups/<UTC>   # restore both production .env files
```

Exit codes:

| Code | Meaning |
|---|---|
| 0 | all passed |
| 1 | a probe failed |
| 2 | not root, or the files are not on this host |
| 3 | sources disagree or are invalid |
| 4 | production policy or target unsafe; nothing written |
| 5 | write failed, rolled back |

PHP reads `.env` per request (CodeIgniter's DotEnv), so no service restart is needed. Nothing on the coturn servers, firewalls, relay port
ranges or application code is touched.

After the GitHub check below passes, delete the backup directory or keep it root-only. It contains a full copy of each production `.env`.

## 4. Verifying from GitHub (read-only, no redeploy)

Run **Actions → TURN check — Production (read-only) → Run workflow** on `main`, in each repository. Expected annotations:

* `TURN secrets (production): presence`: both keys `set`.
* `TURN check (production): status`: `relay configured: yes`, admission `observe`, both nodes `SERVING`.
* `mum` / `ncr`: `PASS` with the expected IPs (`148.135.210.65` / `103.69.196.150`).
* `verdict`: `PASS`.

A TURN allocation probe that passes proves that the API can mint credentials the relays accept. It does **not** prove that a real Remote
desktop session or a Connect call works end to end. That is a separate test.
