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
pwsh -File scripts/windows/build.ps1
```

Which is, in order: build the service → stage it as a Tauri sidecar → `npm ci`
→ `tauri build` → report what was produced and whether any of it is signed.

Output:

```
desktop/target/x86_64-pc-windows-msvc/release/bundle/nsis/*.exe
desktop/target/x86_64-pc-windows-msvc/release/bundle/msi/*.msi
```

The script signs nothing. That is deliberate: it means it can be run on any
machine, including one that has no business holding a signing credential.

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
pwsh -File scripts/windows/sign.ps1   -Path target/.../bundle/nsis/*.exe
pwsh -File scripts/windows/verify.ps1 -Path target/.../bundle/nsis/*.exe
```

`verify.ps1` checks four things and exits non-zero on any of them: the
signature is valid, it is timestamped, the certificate is not self-signed, and
the digest is SHA-256.

## The release workflow

`.github/workflows/release-windows.yml`. **`workflow_dispatch` only.**

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

**Deliberately off.** `plugins.updater.active` is `false`, `endpoints` is
empty, and `pubkey` is empty.

Turning it on without the whole chain in place would mean an agent that
downloads and installs something on the strength of an unauthenticated
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

Until all three are in place, updating is: download the new installer and run
it. The installer stops the service first, so an upgrade over a running
installation is an ordinary install rather than a reboot-required one.

**No unsigned update will ever be executed.** The updater verifies before it
installs, and with `pubkey` empty there is nothing it could verify against —
which is exactly why it is off rather than "on and hoping".

## Before the first production release

Genuinely blocking, and none of it is something code can decide:

- [ ] **A publisher name.** `bundle.publisher` and `bundle.copyright` are empty
      because there is no authoritative legal entity name anywhere in this
      repository. They must match the code-signing certificate's subject.
- [ ] **A code-signing identity** — a Trusted Signing account, or an OV/EV
      certificate. Needs an organisation to be validated, which takes days to
      weeks.
- [ ] **The `windows-release` environment** configured as above.
- [ ] **A real end-to-end run on Windows.** Everything in this repository is
      compiled for Windows and checked by a Windows CI runner; the Windows-only
      code paths have not been *executed* on a Windows machine. See
      [TESTING.md](TESTING.md) and `desktop/tests/MANUAL.md`.
- [ ] **The remaining runtime work** — the encoder and the agent's signalling
      client. Until those exist the agent joins sessions and carries control
      messages but does not send a picture. See
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
```
