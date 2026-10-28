# Publishing a release to downloads.aicountly.com

`release-windows.yml` ends with a GitHub release. That is a build somebody can
be *sent*; it is not yet a build somebody can *find*. This is the step that
puts it on the website.

Why the two are separate workflows: building an installer and publishing one
are different decisions, and only one of them is reversible by deleting a tag.
Nobody should be able to make a release downloadable by merging something.

## Where a release goes

```
downloads.aicountly.com/
  remote/
    manifest.json                               what the website reads
    1.0.0/
      Aicountly-Remote-1.0.0-x64-Setup.exe
      SHA256SUMS.txt
```

Each release has its own version directory. Publishing 1.1.0 cannot disturb
1.0.0, a link that worked yesterday still works, and a rollback is a matter of
pointing `manifest.json` at a directory that is already there.

The host belongs to `aicountly/aicountly-downloads`, which owns its landing
page and its `.htaccess` and deliberately cannot delete the installers. The
download section on `aicountly.com/remote` reads `remote/manifest.json` and
offers whatever it names.

## The published file names

Tauri calls the bundle `AICOUNTLY Remote_1.0.0_x64-setup.exe`, with a space in
it, and GitHub rewrites spaces in release asset names — so what comes back out
of a release is not reliably what went in. The website refuses a file name
that is not a single safe path segment, which is the right rule and would
refuse both.

So `desktop/scripts/make-manifest.mjs` derives the published name from the
product, version and architecture instead of carrying it over from the build.
Every release names its files the same way, which is what lets a link be
written down. Authenticode signs a file's contents and not its name, so the
rename costs nothing — `verify.ps1` passes on the renamed file exactly as it
did on the built one.

## Running it

1. **`release-windows.yml`**, `channel: beta` or `production`. It signs,
   verifies, and publishes a GitHub release tagged `desktop-v<version>` (or
   `desktop-v<version>-beta`).
2. **`release-macos.yml`**, the same version and channel, if there is a Mac
   build to ship. It attaches to the same tag.
3. **`publish-downloads.yml`**, the same version and channel,
   `mode: files-and-manifest`.
4. Make sure `REMOTE_DESKTOP_RELEASED` is `true` on `aicountly/aicountly-com`
   and that site has been deployed — see "Publishing is not releasing" below.
5. Load `https://aicountly.com/remote#download`. The cards should name the new
   version, and the SHA-256 shown there should match `SHA256SUMS.txt` from the
   downloads host. The page caches for ten minutes, so give it a moment.

A rollback is step 2 again with the previous version and `mode: manifest-only`.
The files are still on the host; only the manifest moves.

## The order of the steps, which is the safety property

The website cannot check that the files a manifest names are really there —
they are on another host. So the workflow does it instead, and in this order:

1. the installers go up, into the version's own directory;
2. every one of them is fetched back from the public HTTPS URL a visitor would
   use, and must answer `200` with the exact size the manifest claims;
3. and only then does the manifest go up.

A manifest naming a file is this workflow's word that it already looked.
Nothing may write the manifest first, and nothing writes it at all if step 2
did not pass. If it fails, the files stay uploaded and the download page goes
on showing nothing, which is the correct outcome: no button is better than a
broken one.

`make-manifest.mjs` is the second lock. It computes every hash from the file
itself, cross-checks `SHA256SUMS.txt` and refuses to publish if the two
disagree, refuses a build whose architecture it cannot read from its name,
refuses two builds that would take one published name, and refuses a directory
with no installer in it. It is tested in Desktop CI (`npm run test:scripts`)
and again at the start of every publish.

## Secrets this needs

Five, as **repository** secrets on `aicountly/remote-aicountly`:

| Secret | What it is |
|---|---|
| `DOWNLOAD_PROD_SSH_HOST` | the downloads host |
| `DOWNLOAD_PROD_SSH_PORT` | its SSH port |
| `DOWNLOAD_PROD_SSH_USER` | the SSH user |
| `DOWNLOAD_PROD_SSH_PRIVATE_KEY` | that user's private key |
| `DOWNLOAD_PROD_SSH_REMOTE_ROOT` | the **document root of downloads.aicountly.com** |

**The `DOWNLOAD_` prefix is load-bearing.** These repositories also carry a
`PROD_SSH_*` set for a different cPanel account, and it is set — so an
unprefixed name does not fail, it resolves to a real host that serves none of
this. That is how Connect's first publish put 460 MB into an account no
hostname points at, with every step green. Connect renamed them for this
reason and Remote uses the same names.

`DOWNLOAD_PROD_SSH_REMOTE_ROOT` must be the same value it has in
`aicountly/aicountly-downloads`. A secret belongs to one repository: copies
in that repository, or in `connect-aicountly`, are invisible here.

The `preflight` job checks all five before anything is downloaded, prints
their lengths (never their values, because a secret pasted with a stray
newline is set, wrong and otherwise invisible), and names the ones that are
missing.

The length is worth reading rather than skimming. The downloads host's
document root is `/home/downloadsaic/public_html`, which is **30
characters**; if `DOWNLOAD_PROD_SSH_REMOTE_ROOT` prints as 31 it has a
trailing newline, and nothing else about the run will tell you.

### Checking the secrets without publishing anything

Run `publish-downloads.yml` with `mode: check-access`. It needs no version
and no release, uploads nothing, and is green only if all five secrets are
set here, the key opens that account, and the root is the one
`downloads.aicountly.com` serves — proved by finding the landing page
`aicountly/aicountly-downloads` deploys. It also lists what is already on the
host, so the log shows `connect/` (Connect has published) and, once this
workflow has run for real, `remote/`.

This is the way to confirm the five secrets today, before the Windows signing
credentials are sorted out and there is a release to publish.

### Two guards worth knowing about

Both are Connect's, and both exist because something went wrong without them.

**The root is checked for what it is, not what it is not.** Ruling out the
website does not find the downloads host: a root that is neither accepts the
upload and serves nothing. So the workflow looks over SSH for the landing
page that `aicountly/aicountly-downloads` deploys. Nothing else has it, and
it is the only evidence from this side that the root is the one
`downloads.aicountly.com` actually serves. If that deploy has never run, run
it first.

**The served manifest is compared on `generated`, not `version`.** Two runs
of the same version produce the same version string and different timestamps,
so `generated` is the only field that distinguishes "the manifest I just
wrote" from "a manifest that was already there".

## macOS

`release-macos.yml`, which is the counterpart of `build-macos-test.yml` and
differs from it in the two ways that decide whether a stranger can open the
app at all:

* **Developer ID Application certificate**, not a Development one. A
  Development certificate signs a build that runs on machines in your own
  provisioning profile and is refused from a download. The workflow checks
  the identity starts with `Developer ID Application:` and stops if it does
  not, because this is a failure nobody notices until a customer does.
* **Notarised by Apple and stapled.** Without the staple the first launch on
  a machine that has never seen the app shows "cannot be opened because the
  developer cannot be verified", with no way past — not a warning with a
  *Run anyway*, as on Windows, but a refusal.

Three checks run before anything is published, and each answers a different
question: `codesign --verify` (is the signature intact), `spctl --assess`
(would Gatekeeper open it), and `xcrun stapler validate` (is the ticket
attached). The last is the one that catches a build which notarised but was
not stapled: it opens on the runner, which has just talked to Apple, and is
refused on a customer's laptop.

It needs six secrets on the `macos-release` environment:
`MACOS_RELEASE_CERTIFICATE` (base64 .p12),
`MACOS_RELEASE_CERTIFICATE_PASSWORD`, `MACOS_RELEASE_SIGNING_IDENTITY`,
`APPLE_ID`, `APPLE_APP_SPECIFIC_PASSWORD`, `APPLE_TEAM_ID`.

Until those exist there is no macOS build to publish, the website refuses an
unsigned one (`REMOTE_DOWNLOAD_REQUIRES_SIGNING` names both platforms), and
`/remote` shows macOS as coming soon. That is the correct state, not a bug.

### One tag, both platforms

`release-windows.yml` and `release-macos.yml` attach their artefacts to the
same tag — `desktop-v<version>` or `desktop-v<version>-beta` — so one tag
carries every platform of a version and `publish-downloads.yml` collects them
in a single fetch. Whichever runs second adds to the release the first one
created.

They cannot share a checksum file, though. One `SHA256SUMS.txt` uploaded
twice means the second silently replaces the first, after which every
checksum in it describes a file from the other platform — and the publish
refuses, correctly, because the sums disagree with the files. So macOS writes
`SHA256SUMS-macos.txt`, `make-manifest.mjs` reads every `SHA256SUMS*.txt` it
finds, and what it uploads is one `SHA256SUMS.txt` written under the
published names.

## Publishing is not releasing

A published manifest is not a visible download. `aicountly.com` has its own
switch, `REMOTE_DESKTOP_RELEASED` in `includes/remote-downloads.php`, and
while it is `false` the download section shows both platforms as coming soon
whatever is on the downloads host. Releasing the desktop app means changing
that line in a reviewed commit on `aicountly/aicountly-com`, and then
deploying that site — it is deliberately not something a build can do.

## Until the certificate is issued

The downloads host redirects to HTTPS unconditionally and has no HTTP
fallback, so until a certificate is installed for it these URLs do not work at
all. That is the correct failure for a host that serves installers: the one
file where a man-in-the-middle matters most is the one somebody is about to run
as themselves.

What that means in practice:

* **The download section stays hidden.** The website fetches the manifest over
  verified HTTPS and renders nothing if that fails, so no visitor is ever sent
  to a certificate warning. There is no workaround to add here and none should
  be added.
* **A publish gets as far as the files.** Step 1 above is SSH and works fine;
  step 2 fails on TLS, so the manifest is withheld and the run goes red with
  that explanation. Re-run with `mode: manifest-only` once the certificate is
  in place and the publish finishes in under a minute.
* **cPanel AutoSSL can issue one for free in the meantime**, as soon as the
  subdomain's DNS resolves to the server: SSL/TLS Status → Run AutoSSL. An
  ordered certificate can replace it later without anything in either
  repository changing. The host's `.htaccess` already lets
  `/.well-known/` through for the ACME challenge, and both deploys protect it.
