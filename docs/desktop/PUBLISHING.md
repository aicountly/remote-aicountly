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
2. **`publish-downloads.yml`**, the same version and channel,
   `mode: files-and-manifest`.
3. Load `https://aicountly.com/remote#download`. The card should name the new
   version, and the SHA-256 shown there should match `SHA256SUMS.txt` from the
   release. The page caches for five minutes, so give it a moment.

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

The same five that `aicountly/aicountly-downloads` uses, as repository secrets
here:

| Secret | What it is |
|---|---|
| `PROD_SSH_HOST` | the downloads host |
| `PROD_SSH_PORT` | its SSH port |
| `PROD_SSH_USER` | the SSH user |
| `PROD_SSH_PRIVATE_KEY` | that user's private key |
| `PROD_SSH_REMOTE_ROOT` | the **document root of downloads.aicountly.com** |

`PROD_SSH_REMOTE_ROOT` is the one worth reading twice. It is the downloads
host's document root, not aicountly.com's — the workflow appends
`remote/<version>` to it, and pointing it at the website's root would write a
`remote/` directory into the middle of the site.

The first step of the workflow checks all five and names the ones that are
missing, rather than failing later inside `rsync` with a blank hostname.

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
