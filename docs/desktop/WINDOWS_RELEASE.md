# Releasing AICOUNTLY Remote for Windows

Building, signing, publishing, and the state of automatic updates. Nothing here
runs on a push or a merge.

## Where the version comes from

**One value.** `version` under `[workspace.package]` in `desktop/Cargo.toml`.

Everything else derives from it or is checked against it:

| | |
|---|---|
| `remote_core::AGENT_VERSION` | `env!("CARGO_PKG_VERSION")` |
| The About panel | `AGENT_VERSION` |
| `remote_devices.agent_version` | sent at enrolment and with every presence report |
| The service's `--version` | `env!("CARGO_PKG_VERSION")` |
| Installer metadata | `tauri.conf.json`'s `version` |
| The release tag and artifact names | the workflow input |

Three of those are separate files, so `build.ps1` and the release workflow both
**refuse to run** when `Cargo.toml`, `tauri.conf.json` and `package.json`
disagree, or when the requested version is not the one in the workspace. A
release tagged 1.2.0 containing a binary that reports 1.1.0 is the kind of
thing nobody notices until a support call.

To release 1.1.0, edit three lines and nothing else:

```
desktop/Cargo.toml            version = "1.1.0"
desktop/src-tauri/tauri.conf.json    "version": "1.1.0"
desktop/package.json                 "version": "1.1.0"
```

## Building

```powershell
cd desktop
pwsh -File scripts/windows/build.ps1           # unsigned: a development build
pwsh -File scripts/windows/build.ps1 -Sign     # signed all the way through
```

Which is, in order: build the service → stage it as a Tauri sidecar → `npm ci`
→ `tauri build` → report what was produced and whether each piece of it is
signed.

Output:

```
desktop/target/x86_64-pc-windows-msvc/release/bundle/nsis/*.exe
```

**NSIS only.** `installers/windows/hooks.nsh` — the service registration, the
data directory's ACLs, the sign-in Run key, and the uninstall cleanup that
removes the device key and config — is NSIS-specific, and WiX has no
equivalent wired up. An MSI built from the same `tauri.conf.json` installed
the binaries and nothing else: no service, no cleanup on uninstall. Building
one target that actually works beats building two where only one does — see
[DEVICE_ENROLMENT.md](DEVICE_ENROLMENT.md#uninstalling) for what the
uninstall is supposed to guarantee.

Without `-Sign` the script signs nothing, and can be run on any machine,
including one that has no business holding a signing credential.

### The application manifest, and how to edit it safely

`src-tauri/windows-app-manifest.xml` is what declares per-monitor-v2 DPI
awareness and `asInvoker`. It is embedded by `tauri-winres`, which does **not**
pass the file to the resource compiler: it writes one quoted RC string per
line, each trimmed, and the compiler concatenates them with no newlines. What
Windows parses is every line of that file joined by single spaces.

So the file must have **no XML declaration**. `<?xml ... ?>` has to be the very
first thing in a document, and after the join it is preceded by a space;
Windows then cannot build an activation context and every run of the binary
fails with `ERROR_SXS_CANT_GEN_ACTCTX` (14001) — "the application has failed to
start because its side-by-side configuration is incorrect" — before `main` is
reached. Nothing fails at build time, the installer builds and installs, and
the application simply will not start. Comments and non-ASCII characters are
out for the same reason: they are prose compiled into every shipped binary
through a quoting path with its own escaping rules. The reasoning lives in
`src-tauri/build.rs`, and `src-tauri/tests/app_manifest.rs` applies the same
transformation and checks the result.

## What "signed" has to mean

**Signing the installer is not signing the product.** The installer is a
container: by the time it exists, the application and the service have already
been packaged into it. A signature applied to the installer afterwards says
nothing about the two executables a customer actually runs, and they were the
ones SmartScreen complained about.

This was a real defect here, and the shape of it is worth remembering: the
release signed `bundle/nsis/*.exe` after the build, verified *that file*,
found it correctly signed and timestamped, and published an installer
containing two unsigned binaries. Every check passed. The check simply was not
looking at them.

So signing happens **during** the build. `build.ps1 -Sign` passes
`scripts/windows/sign.ps1` to the Tauri bundler as its `signCommand`, and the
bundler calls it, in this order, on:

1. `AicountlyRemote.exe` — the application;
2. `AicountlyRemoteService.exe` — the service sidecar;
3. the NSIS plugin DLLs that go inside the installer;
4. the uninstaller (through NSIS's `!uninstfinalize`);
5. the installer itself.

Each one before it is packaged into the next, which is the only order in which
the result is signed all the way down. `sign.ps1` still owns every credential
and still refuses to run without one, so `-Sign` with nothing configured fails
on the first binary rather than quietly producing an unsigned build.

`verify.ps1` is then run on the installer **and** on both executables, because
a verification that only reads the installer is the one that missed this.

## Signing

> **Two different credentials, and they are not interchangeable.**
>
> * an **Authenticode certificate** signs the executables and installers, and
>   is what Windows and SmartScreen check;
> * an **updater signing key** signs the update manifest, and is what the
>   Tauri updater checks before installing anything.
>
> Having one does not give you the other. Configuring the first does not enable
> automatic updates, and the updater's key is not a code-signing certificate.

### The supported paths

**Microsoft Trusted Signing** (recommended). The key never exists outside
Microsoft's HSM: there is no certificate file to leak, to rotate by hand, or to
find on somebody's laptop in three years. Certificates are short-lived and
issued per signing operation.

```
AICOUNTLY_SIGN_METHOD=trusted-signing
AICOUNTLY_SIGN_ENDPOINT=https://eus.codesigning.azure.net
AICOUNTLY_SIGN_ACCOUNT=<account name>
AICOUNTLY_SIGN_PROFILE=<certificate profile name>
AZURE_TENANT_ID / AZURE_CLIENT_ID / AZURE_CLIENT_SECRET
```

**An OV or EV Authenticode certificate**, installed in the runner's certificate
store and addressed by thumbprint. EV is on hardware and gets SmartScreen
reputation immediately; OV builds reputation over time and downloads will warn
until it does.

```
AICOUNTLY_SIGN_METHOD=certificate
AICOUNTLY_SIGN_THUMBPRINT=<40 hex characters>
```

### A self-signed certificate is not a path

`sign.ps1` inspects the certificate and **refuses** one whose subject equals its
issuer. A self-signed signature satisfies a signature *check* while telling a
customer's machine nothing about who published the software — it is worse than
being honestly unsigned, because it looks like something.

### Timestamping

Every signature is timestamped (RFC 3161, `/tr` + `/td SHA256`). Without one a
signature stops verifying the day the certificate expires — including on
machines that downloaded the installer years earlier. `verify.ps1` fails a
release whose signature has no timestamp.

```powershell
# What the bundler runs per file, and what you can run by hand on one.
pwsh -File scripts/windows/sign.ps1   -Path target/.../bundle/nsis/*.exe

pwsh -File scripts/windows/verify.ps1 -Path `
  target/.../bundle/nsis/*.exe, `
  target/.../AicountlyRemote.exe, `
  src-tauri/binaries/AicountlyRemoteService-x86_64-pc-windows-msvc.exe
```

`verify.ps1` checks four things and exits non-zero on any of them: the
signature is valid, it is timestamped, the certificate is not self-signed, and
the digest is SHA-256. `sign.ps1` runs it on whatever it just signed and
**throws** if it fails — a signature that did not take used to be printed and
then ignored, because a script called with `&` that exits does not stop its
caller.

## The release workflow

`.github/workflows/release-windows.yml`. **`workflow_dispatch` only.** Its first
job calls `desktop-ci.yml` (formatting, Clippy and tests on Linux and Windows,
the interface's tests and build, advisories) on the commit being released; the
build job `needs:` it, so a red suite stops the release before anything is
signed.

| Input | |
|---|---|
| `version` | must equal the workspace version |
| `channel` | `sandbox`, `beta` or `production` |

| Channel | Signed | Published as | Retention |
|---|---|---|---|
| `sandbox` | no | a workflow artifact, labelled `UNSIGNED DEVELOPMENT BUILD` | 7 days |
| `beta` | yes | a GitHub **pre-release** | 90 days |
| `production` | yes | a GitHub release | 90 days |

`beta` and `production` run in the **`windows-release` GitHub Environment**,
which is where the signing secrets live and where required reviewers are
configured. A job that cannot reach that environment cannot sign — the
protection is GitHub's, not a check inside a script that somebody could edit.

There is **no branch by which an unsigned artifact becomes a GitHub release**:
the publish step is `if: inputs.channel != 'sandbox'`, and the sandbox channel
ships a file next to the installer saying what it is.

It touches neither cPanel deploy workflow. A desktop release does not go near
the web deployment.

### Setting up the environment

1. **Settings → Environments → New environment → `windows-release`.**
2. Add required reviewers, and restrict it to the branches you release from.
3. Variables (not secret): `AICOUNTLY_SIGN_METHOD`, and for Trusted Signing
   `AICOUNTLY_SIGN_ENDPOINT`, `AICOUNTLY_SIGN_ACCOUNT`,
   `AICOUNTLY_SIGN_PROFILE`. Optionally
   `AICOUNTLY_SIGN_TIMESTAMP_URL`.
4. Secrets: `AZURE_TENANT_ID`, `AZURE_CLIENT_ID`, `AZURE_CLIENT_SECRET` — or
   `AICOUNTLY_SIGN_THUMBPRINT` for the certificate path.

**No signing material is ever in this repository**, in a workflow input, or in
a file the build writes. A `.pfx` committed to a repository is a certificate
that has to be revoked.

## Automatic updates

**Not Tauri's updater — the application's own check.** Each time the window
opens it reads the manifest the website's download section reads, and offers
**Install update** when a newer version, or a later build of this one, is
published for this computer; the installer is downloaded, checked against the
manifest's size and SHA-256, and run (`desktop/crates/remote-core/src/update.rs`,
"The application reads the same manifest" in `PUBLISHING.md`). Nothing below
about `tauri-plugin-updater` changes because of it: that plugin, its signing
keys and its update feed are still not in the build.

`tauri-plugin-updater` is not a dependency, there is no `plugins.updater`
block in `tauri.conf.json`, and the window has no updater permission.

It used to be present and switched off — `active: false`, no endpoints, no
public key — waiting for the chain below. What ended that was
`cargo audit`: the plugin was the only thing pulling in `zip`, which carries
three advisories including a 9.1 critical (RUSTSEC-2026-0367/0368/0369, fixed
in a major release the plugin does not take yet). Carrying a critical
advisory inside a binary that runs as the signed-in person, for a feature
that was turned off, is not a trade worth making, so the dependency went
instead of being annotated. See `desktop/.cargo/audit.toml`.

Updating is therefore: the application's prompt, or download the new
installer and run it. Either way the installer stops the service first, so an
upgrade over a running installation is an ordinary install rather than a
reboot-required one. Installing a later build of the same version shows
NSIS's "already installed" page; **Add/Reinstall** is the choice that
replaces it.

### Putting it back

Four edits, and then the three things that were always the real blockers:

```
desktop/src-tauri/Cargo.toml              tauri-plugin-updater = "2"
desktop/src-tauri/src/lib.rs              .plugin(tauri_plugin_updater::Builder::new().build())
desktop/src-tauri/capabilities/default.json   "updater:default"
desktop/src-tauri/tauri.conf.json         the "plugins": { "updater": { … } } block
desktop/package.json                      @tauri-apps/plugin-updater
```

Check `cargo audit` at that point: re-adding the plugin re-adds `zip`, and
whether that is acceptable depends on which version the plugin has moved to
by then.

Turning the updater on without the whole chain in place would mean an agent
that downloads and installs something on the strength of an unauthenticated
manifest, on machines it can control. Three things are needed first, and none
of them exists yet:

1. **An updater keypair** — `npm run tauri signer generate`. The private half
   is a release secret (`TAURI_SIGNING_PRIVATE_KEY`); the public half goes in
   `tauri.conf.json`. Again: **this is not the Authenticode certificate.**
2. **Somewhere to host the manifest and the artifacts**, over HTTPS, at a
   stable URL. `AgentAdvisory.updateFeedUrl` is already carried by
   `GET /devices/me` so the deployment can name it rather than the binary
   hardcoding it.
3. **A release process that signs the manifest**, so the agent can verify it
   before installing. Tauri's updater does this check; what it cannot do is
   check a signature against a key nobody configured.

**No unsigned update will ever be executed** — today because there is no
updater at all, and after it returns because it verifies before it installs
and would have nothing to verify against until step 1 is done.

## Before the first production release

Genuinely blocking, and none of it is something code can decide:

- [ ] **Confirm the publisher against the certificate.** `bundle.publisher` is
      `Aicountly Interactive Services Private Limited` and `bundle.copyright`
      matches. When the certificate is issued, check its subject `O=` field
      reads exactly that; if the CA normalised the name, change
      `tauri.conf.json` to match the certificate, not the reverse.
- [ ] **A code-signing identity** — a Trusted Signing account, or an OV/EV
      certificate. Needs an organisation to be validated, which takes days to
      weeks.
- [ ] **The `windows-release` environment** configured as above.
- [ ] **A real end-to-end run on Windows.** Everything in this repository is
      compiled for Windows and checked by a Windows CI runner; the native
      Windows calls have not been *executed* on a Windows machine. See
      [TESTING.md](TESTING.md) and `desktop/tests/MANUAL.md`.
- [ ] **The remaining runtime work** — the encoder. Until it exists the agent
      enrols, stays reachable, joins sessions over the same signalling socket
      the browser uses and carries control messages through its gate, but does
      not send a picture. See
      [ARCHITECTURE.md](ARCHITECTURE.md#what-is-built-and-what-is-not).

## Checklist for a release that is ready

```
[ ] the three version strings agree
[ ] Desktop CI is green, including the Windows job
[ ] cargo audit and npm audit are clean
[ ] the manual pass in desktop/tests/MANUAL.md has been done on real Windows
[ ] Run workflow → channel: beta → installed and used on a clean machine
[ ] verify.ps1 passes on the downloaded artifacts, not only on the built ones
[ ] Run workflow → channel: production
[ ] the SHA256SUMS in the release match a fresh hash of the downloads
[ ] Run publish-downloads.yml → the download section on /remote offers it
```

A GitHub release is a build somebody can be sent. Making it a build somebody
can find is a separate, manual step — see [PUBLISHING.md](PUBLISHING.md).
