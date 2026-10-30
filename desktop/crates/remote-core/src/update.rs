//! Is there a newer AICOUNTLY Remote than this one, and fetching it if so.
//!
//! There is no second release feed. The website's download section already
//! reads one file — `https://downloads.aicountly.com/remote/manifest.json`,
//! written by `desktop/scripts/make-manifest.mjs` and published by
//! `.github/workflows/publish-downloads.yml` only after every file it names
//! has been fetched back over HTTPS at the size it claims. That file is the
//! release feed: what it names is what a person would download from the page
//! today, so it is exactly what this offers.
//!
//! Three properties this holds on to:
//!
//!   * **never in the way.** A check that cannot reach the host, or reads
//!     something it does not understand, is "no update", not an error the
//!     window has to explain. The application works the same either way.
//!   * **only newer.** Versions are compared as semver; an unparseable
//!     version on either side is never "newer", so a malformed manifest
//!     cannot nag anybody. Several builds go out as the same version, so for
//!     the same version the build number decides (`crate::AGENT_BUILD`): a
//!     later build is offered, the same or an earlier one never is, and a
//!     local build — which has no number — is only ever offered a newer
//!     version.
//!   * **nothing runs that was not verified.** The installer is fetched from
//!     the downloads host under the name the manifest gives it, its size and
//!     SHA-256 are checked against the manifest, and only a file that matches
//!     both is handed back to be opened. Nothing about what to fetch comes
//!     from the window.

use std::path::{Path, PathBuf};
use std::time::Duration;

use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};

/// The file the website's download section reads.
pub const UPDATE_MANIFEST_URL: &str = "https://downloads.aicountly.com/remote/manifest.json";

/// Where each version's installers live: `<base>/<version>/<file>`, exactly
/// as the publish workflow uploads and verifies them.
pub const DOWNLOADS_BASE_URL: &str = "https://downloads.aicountly.com/remote";

/// The download page, for when there is no installer this build can fetch
/// for itself — and for anyone who would rather download it by hand.
pub const DOWNLOAD_PAGE_URL: &str = "https://aicountly.com/remote#download";

/// How long the launch check may take. Short: it runs while the window opens,
/// and a slow host must not be noticed.
const CHECK_TIMEOUT: Duration = Duration::from_secs(10);

/// How long fetching an installer may take, end to end. Installers are over
/// a hundred megabytes, so this is about a stalled transfer, not a slow one.
const DOWNLOAD_TIMEOUT: Duration = Duration::from_secs(30 * 60);

/// What can go wrong. The window never shows a check failure — only a failed
/// install, which a person asked for and is waiting on.
#[derive(Debug, thiserror::Error)]
pub enum UpdateError {
    /// The host could not be reached, or the transfer broke off.
    #[error("The update could not be downloaded: {0}")]
    Transport(String),
    /// The host answered with something other than the file.
    #[error("The update server answered {0}.")]
    Status(u16),
    /// The manifest was not one this build can read.
    #[error("The update information could not be read: {0}")]
    Manifest(String),
    /// What arrived is not what the manifest describes.
    #[error("The downloaded update did not match what was published, so it was not opened.")]
    Mismatch,
    /// Writing it to disk failed.
    #[error("The update could not be saved: {0}")]
    Io(String),
}

/// `manifest.json`, as `make-manifest.mjs` writes it. Only what this reads.
#[derive(Debug, Clone, PartialEq, Eq, Deserialize)]
pub struct ReleaseManifest {
    /// `remote`.
    pub product: String,
    /// The published version, e.g. `1.1.0`.
    pub version: String,
    /// `production`, `beta` or `sandbox`.
    #[serde(default)]
    pub channel: String,
    /// One entry per published build.
    pub files: Vec<ReleaseFile>,
}

/// One published build.
#[derive(Debug, Clone, PartialEq, Eq, Deserialize)]
pub struct ReleaseFile {
    /// `windows` or `macos`.
    pub platform: String,
    /// `x64` or `arm64`.
    pub arch: String,
    /// `installer`, `disk-image` or `archive`.
    pub kind: String,
    /// The published name, e.g.
    /// `Aicountly-Remote-1.0.0-b202610301030-x64-Setup.exe`.
    pub file: String,
    /// Which build of the version this is (`yyyyMMddHHmm`). Missing from a
    /// manifest written before builds were numbered.
    #[serde(default)]
    pub build: Option<String>,
    /// Bytes.
    pub size: u64,
    /// Lower-case hex.
    pub sha256: String,
}

/// A newer version, as the window is told about it.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct UpdateInfo {
    /// What is installed.
    pub current_version: String,
    /// Which build of it, when it has a number.
    pub current_build: Option<String>,
    /// What is published.
    pub latest_version: String,
    /// Which build of it, when the manifest says.
    pub latest_build: Option<String>,
    /// The installer for this operating system and architecture, if the
    /// release has one.
    pub installer: Option<UpdateInstaller>,
    /// Where a person downloads it by hand.
    pub download_page_url: String,
}

/// The one file that installs the update on this machine.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct UpdateInstaller {
    /// Its published name.
    pub file: String,
    /// Where it is downloaded from.
    pub url: String,
    /// Bytes, from the manifest.
    pub size: u64,
    /// SHA-256, from the manifest.
    pub sha256: String,
}

/// This build's platform and architecture, in the manifest's words. `None`
/// on a platform no installer is published for.
#[must_use]
pub fn current_platform() -> Option<(&'static str, &'static str)> {
    let platform = match std::env::consts::OS {
        "windows" => "windows",
        "macos" => "macos",
        _ => return None,
    };

    let arch = match std::env::consts::ARCH {
        "x86_64" => "x64",
        "aarch64" => "arm64",
        _ => return None,
    };

    Some((platform, arch))
}

/// Whether `latest` is a newer version than `installed`.
///
/// Semver precedence, so `1.10.0` is newer than `1.9.0` and `1.1.0` is newer
/// than `1.1.0-beta.1`. Either side failing to parse is "not newer": a
/// manifest this build cannot read must never produce a prompt.
#[must_use]
pub fn is_newer(installed: &str, latest: &str) -> bool {
    match (
        semver::Version::parse(installed.trim().trim_start_matches('v')),
        semver::Version::parse(latest.trim().trim_start_matches('v')),
    ) {
        (Ok(installed), Ok(latest)) => latest > installed,
        _ => false,
    }
}

/// Whether two versions are the same release, by semver precedence.
fn is_same_version(installed: &str, latest: &str) -> bool {
    match (
        semver::Version::parse(installed.trim().trim_start_matches('v')),
        semver::Version::parse(latest.trim().trim_start_matches('v')),
    ) {
        (Ok(installed), Ok(latest)) => latest.cmp_precedence(&installed).is_eq(),
        _ => false,
    }
}

/// A build number as the release workflows write it: the commit's time,
/// `yyyyMMddHHmm`, digits only.
fn parse_build(value: &str) -> Option<u64> {
    let value = value.trim();

    if value.len() != 12 || !value.bytes().all(|byte| byte.is_ascii_digit()) {
        return None;
    }

    value.parse().ok()
}

/// Whether `latest` is a later build than `installed`, of the same version.
///
/// Both have to be numbers. A local build has none and is never offered the
/// same version again, and a manifest from before builds were numbered never
/// offers one.
#[must_use]
pub fn is_newer_build(installed: Option<&str>, latest: Option<&str>) -> bool {
    match (
        installed.and_then(parse_build),
        latest.and_then(parse_build),
    ) {
        (Some(installed), Some(latest)) => latest > installed,
        _ => false,
    }
}

/// What a manifest means for this installation: `Some` only when it
/// publishes a newer version — or a later build of the same one — **for this
/// platform**.
///
/// A release that carries no build for this operating system is not an
/// update for it — Windows and macOS are built by separate workflows, and a
/// Windows-only release must not prompt a Mac. A build for this platform but
/// another architecture is still an update; it has no installer this build
/// may fetch for itself, so the window sends the person to the download page.
#[must_use]
pub fn evaluate(
    manifest: &ReleaseManifest,
    installed: &str,
    installed_build: Option<&str>,
    platform: &str,
    arch: &str,
    base_url: &str,
) -> Option<UpdateInfo> {
    if manifest.product != "remote" || manifest.channel == "sandbox" {
        return None;
    }

    let for_platform: Vec<&ReleaseFile> = manifest
        .files
        .iter()
        .filter(|file| file.platform == platform && is_installable(&file.kind))
        .collect();

    if for_platform.is_empty() {
        return None;
    }

    let candidate = for_platform
        .iter()
        .find(|file| file.arch == arch && is_safe_file_name(&file.file) && is_sha256(&file.sha256));

    // The build this machine would get: its own installer's, or — when there
    // is none to fetch for this architecture — the newest of the platform's,
    // which is what the download page would hand it.
    let latest_build = match candidate {
        Some(file) => file
            .build
            .as_deref()
            .filter(|build| parse_build(build).is_some()),
        None => for_platform
            .iter()
            .filter_map(|file| file.build.as_deref())
            .filter(|build| parse_build(build).is_some())
            .max_by_key(|build| parse_build(build)),
    };

    let newer = is_newer(installed, &manifest.version)
        || (is_same_version(installed, &manifest.version)
            && is_newer_build(installed_build, latest_build));

    if !newer {
        return None;
    }

    let installer = candidate.map(|file| UpdateInstaller {
        file: file.file.clone(),
        url: format!(
            "{}/{}/{}",
            base_url.trim_end_matches('/'),
            manifest.version.trim(),
            file.file
        ),
        size: file.size,
        sha256: file.sha256.to_ascii_lowercase(),
    });

    Some(UpdateInfo {
        current_version: installed.to_owned(),
        current_build: installed_build.map(str::to_owned),
        latest_version: manifest.version.trim().to_owned(),
        latest_build: latest_build.map(str::to_owned),
        installer,
        download_page_url: DOWNLOAD_PAGE_URL.to_owned(),
    })
}

/// A Windows installer or a macOS disk image — what a person installs from.
fn is_installable(kind: &str) -> bool {
    matches!(kind, "installer" | "disk-image")
}

/// One path segment, of the characters `make-manifest.mjs` publishes names
/// in. It becomes both a URL path segment and a file name on this machine,
/// so nothing that could leave either is accepted.
fn is_safe_file_name(name: &str) -> bool {
    !name.is_empty()
        && name.len() <= 128
        && !name.starts_with('.')
        && name
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'.' | b'-' | b'_'))
}

fn is_sha256(value: &str) -> bool {
    value.len() == 64 && value.bytes().all(|byte| byte.is_ascii_hexdigit())
}

/// Checks for, and fetches, updates.
#[derive(Debug, Clone)]
pub struct UpdateChecker {
    http: reqwest::Client,
    manifest_url: String,
    base_url: String,
}

impl UpdateChecker {
    /// The checker for the published feed.
    pub fn new() -> Result<Self, UpdateError> {
        Self::with_urls(UPDATE_MANIFEST_URL, DOWNLOADS_BASE_URL)
    }

    /// A checker against another feed. For tests; the application only ever
    /// uses [`UpdateChecker::new`].
    pub fn with_urls(manifest_url: &str, base_url: &str) -> Result<Self, UpdateError> {
        let http = reqwest::Client::builder()
            .connect_timeout(CHECK_TIMEOUT)
            .user_agent(format!(
                "AicountlyRemote/{} ({})",
                crate::AGENT_VERSION,
                std::env::consts::OS
            ))
            // The publish workflow verifies every file at its own URL with
            // no redirect followed; anything else answering is not it.
            .redirect(reqwest::redirect::Policy::none())
            .build()
            .map_err(|error| UpdateError::Transport(error.to_string()))?;

        Ok(Self {
            http,
            manifest_url: manifest_url.to_owned(),
            base_url: base_url.to_owned(),
        })
    }

    /// Read the published manifest.
    pub async fn manifest(&self) -> Result<ReleaseManifest, UpdateError> {
        let response = self
            .http
            .get(&self.manifest_url)
            // Never a cached answer: the manifest changes in place.
            .header("Cache-Control", "no-cache")
            .timeout(CHECK_TIMEOUT)
            .send()
            .await
            .map_err(|error| UpdateError::Transport(error.to_string()))?;

        if !response.status().is_success() {
            return Err(UpdateError::Status(response.status().as_u16()));
        }

        response
            .json::<ReleaseManifest>()
            .await
            .map_err(|error| UpdateError::Manifest(error.to_string()))
    }

    /// Whether there is a newer version, or a later build of this one, for
    /// this platform.
    pub async fn check(
        &self,
        installed: &str,
        installed_build: Option<&str>,
        platform: &str,
        arch: &str,
    ) -> Result<Option<UpdateInfo>, UpdateError> {
        let manifest = self.manifest().await?;

        Ok(evaluate(
            &manifest,
            installed,
            installed_build,
            platform,
            arch,
            &self.base_url,
        ))
    }

    /// Download an installer into `dir` and verify it against the manifest.
    ///
    /// Written to `<file>.part` and renamed only once its size and SHA-256
    /// both match, so a file under the installer's own name in `dir` is
    /// always one that was verified. Anything that does not match is deleted.
    pub async fn download(
        &self,
        installer: &UpdateInstaller,
        dir: &Path,
    ) -> Result<PathBuf, UpdateError> {
        if !is_safe_file_name(&installer.file) || !is_sha256(&installer.sha256) {
            return Err(UpdateError::Mismatch);
        }

        tokio::fs::create_dir_all(dir)
            .await
            .map_err(|error| UpdateError::Io(error.to_string()))?;

        let target = dir.join(&installer.file);
        let partial = dir.join(format!("{}.part", installer.file));

        let result = self.fetch_into(installer, &partial).await;

        if let Err(error) = result {
            let _ = tokio::fs::remove_file(&partial).await;
            return Err(error);
        }

        // Replacing a previous attempt at the same version, verified or not.
        let _ = tokio::fs::remove_file(&target).await;
        tokio::fs::rename(&partial, &target)
            .await
            .map_err(|error| UpdateError::Io(error.to_string()))?;

        Ok(target)
    }

    async fn fetch_into(
        &self,
        installer: &UpdateInstaller,
        path: &Path,
    ) -> Result<(), UpdateError> {
        use tokio::io::AsyncWriteExt;

        let mut response = self
            .http
            .get(&installer.url)
            .timeout(DOWNLOAD_TIMEOUT)
            .send()
            .await
            .map_err(|error| UpdateError::Transport(error.to_string()))?;

        if !response.status().is_success() {
            return Err(UpdateError::Status(response.status().as_u16()));
        }

        let mut file = tokio::fs::File::create(path)
            .await
            .map_err(|error| UpdateError::Io(error.to_string()))?;

        let mut hasher = Sha256::new();
        let mut received: u64 = 0;

        while let Some(chunk) = response
            .chunk()
            .await
            .map_err(|error| UpdateError::Transport(error.to_string()))?
        {
            received += chunk.len() as u64;

            // Stop at once rather than writing out whatever a wrong answer
            // happens to be.
            if received > installer.size {
                return Err(UpdateError::Mismatch);
            }

            hasher.update(&chunk);
            file.write_all(&chunk)
                .await
                .map_err(|error| UpdateError::Io(error.to_string()))?;
        }

        file.flush()
            .await
            .map_err(|error| UpdateError::Io(error.to_string()))?;
        drop(file);

        if received != installer.size
            || hex::encode(hasher.finalize()) != installer.sha256.to_ascii_lowercase()
        {
            return Err(UpdateError::Mismatch);
        }

        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    use std::collections::HashMap;
    use std::sync::Arc;

    use tokio::io::{AsyncReadExt, AsyncWriteExt};
    use tokio::net::TcpListener;

    const INSTALLER: &[u8] = b"pretend this is a signed installer";

    fn sha(bytes: &[u8]) -> String {
        hex::encode(Sha256::digest(bytes))
    }

    fn manifest(version: &str) -> ReleaseManifest {
        let file = |platform: &str, arch: &str, kind: &str, name: &str| ReleaseFile {
            platform: platform.into(),
            arch: arch.into(),
            kind: kind.into(),
            file: name.into(),
            build: None,
            size: INSTALLER.len() as u64,
            sha256: sha(INSTALLER),
        };

        ReleaseManifest {
            product: "remote".into(),
            version: version.into(),
            channel: "production".into(),
            files: vec![
                file(
                    "windows",
                    "x64",
                    "installer",
                    &format!("Aicountly-Remote-{version}-x64-Setup.exe"),
                ),
                file(
                    "macos",
                    "arm64",
                    "disk-image",
                    &format!("Aicountly-Remote-{version}-arm64.dmg"),
                ),
                file(
                    "macos",
                    "x64",
                    "disk-image",
                    &format!("Aicountly-Remote-{version}-x64.dmg"),
                ),
            ],
        }
    }

    /// What `make-manifest.mjs --build` publishes: every file numbered, and
    /// named with its number.
    fn built(version: &str, build: &str) -> ReleaseManifest {
        let mut m = manifest(version);
        for file in &mut m.files {
            file.file = file
                .file
                .replace(&format!("-{version}-"), &format!("-{version}-b{build}-"));
            file.build = Some(build.into());
        }
        m
    }

    fn manifest_json(version: &str) -> String {
        to_json(&manifest(version))
    }

    /// As the publish workflow writes it, including the fields this does not
    /// read.
    fn to_json(m: &ReleaseManifest) -> String {
        let files: Vec<String> = m
            .files
            .iter()
            .map(|f| {
                let build = f
                    .build
                    .as_ref()
                    .map(|build| format!(r#""build":"{build}","#))
                    .unwrap_or_default();
                format!(
                    r#"{{"platform":"{}","arch":"{}","kind":"{}","label":"x","file":"{}",{build}"size":{},"sha256":"{}","signed":true}}"#,
                    f.platform, f.arch, f.kind, f.file, f.size, f.sha256
                )
            })
            .collect();

        format!(
            r#"{{"product":"remote","version":"{}","channel":"production","generated":"2026-10-29T00:00:00Z","files":[{}]}}"#,
            m.version,
            files.join(",")
        )
    }

    /// A tiny HTTP/1.1 server: path → (status, body). Enough to drive the
    /// real reqwest client through the real code paths.
    async fn serve(routes: HashMap<String, (u16, Vec<u8>)>) -> String {
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let address = listener.local_addr().unwrap();
        let routes = Arc::new(routes);

        tokio::spawn(async move {
            loop {
                let Ok((mut socket, _)) = listener.accept().await else {
                    return;
                };
                let routes = routes.clone();

                tokio::spawn(async move {
                    let mut buffer = vec![0u8; 8192];
                    let mut read = 0;
                    loop {
                        let Ok(n) = socket.read(&mut buffer[read..]).await else {
                            return;
                        };
                        if n == 0 {
                            return;
                        }
                        read += n;
                        if buffer[..read].windows(4).any(|w| w == b"\r\n\r\n") {
                            break;
                        }
                    }

                    let request = String::from_utf8_lossy(&buffer[..read]);
                    let path = request.split_whitespace().nth(1).unwrap_or("/").to_owned();

                    let (status, body) = routes
                        .get(&path)
                        .cloned()
                        .unwrap_or((404, b"not found".to_vec()));

                    let head = format!(
                        "HTTP/1.1 {status} X\r\nContent-Length: {}\r\nContent-Type: application/octet-stream\r\nConnection: close\r\n\r\n",
                        body.len()
                    );
                    let _ = socket.write_all(head.as_bytes()).await;
                    let _ = socket.write_all(&body).await;
                    let _ = socket.shutdown().await;
                });
            }
        });

        format!("http://{address}")
    }

    fn temp_dir(name: &str) -> PathBuf {
        let dir = std::env::temp_dir().join(format!(
            "aicountly-remote-update-test-{name}-{}",
            uuid_like()
        ));
        let _ = std::fs::remove_dir_all(&dir);
        dir
    }

    fn uuid_like() -> String {
        format!(
            "{}-{:?}",
            std::process::id(),
            std::time::SystemTime::now()
                .duration_since(std::time::UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        )
    }

    #[test]
    fn versions_compare_as_semver() {
        assert!(is_newer("1.0.0", "1.0.1"));
        assert!(is_newer("1.0.0", "1.1.0"));
        assert!(is_newer("1.9.0", "1.10.0"), "numeric, not alphabetical");
        assert!(
            is_newer("1.1.0-beta.1", "1.1.0"),
            "a release is newer than its beta"
        );
        assert!(is_newer("1.0.0", "v1.0.1"));

        assert!(
            !is_newer("1.0.0", "1.0.0"),
            "the same version is not an update"
        );
        assert!(!is_newer("1.1.0", "1.0.9"), "an older one is never offered");
        assert!(!is_newer("1.1.0", "1.1.0-beta.1"));
        assert!(!is_newer("1.0.0", "not a version"));
        assert!(!is_newer("1.0.0", ""));
        assert!(!is_newer("garbage", "9.9.9"));
    }

    #[test]
    fn builds_of_one_version_compare_by_number() {
        assert!(is_newer_build(Some("202610300712"), Some("202610301030")));
        assert!(is_newer_build(Some("202612312359"), Some("202701010000")));

        assert!(
            !is_newer_build(Some("202610301030"), Some("202610301030")),
            "the same build is not an update"
        );
        assert!(
            !is_newer_build(Some("202610301030"), Some("202610300712")),
            "an earlier build is never offered"
        );
        assert!(
            !is_newer_build(None, Some("202610301030")),
            "a local build has no number to compare"
        );
        assert!(
            !is_newer_build(Some("202610301030"), None),
            "a manifest from before builds were numbered offers none"
        );
        for junk in [
            "",
            "2026103010301",
            "20261030103x",
            "-20261030103",
            " 2026-10-30 ",
        ] {
            assert!(
                !is_newer_build(Some("202610300712"), Some(junk)),
                "{junk:?} is not a build"
            );
            assert!(!is_newer_build(Some(junk), Some("202610301030")));
        }
    }

    #[test]
    fn a_later_build_of_the_same_version_is_an_update() {
        let info = evaluate(
            &built("1.0.0", "202610301030"),
            "1.0.0",
            Some("202610300712"),
            "windows",
            "x64",
            DOWNLOADS_BASE_URL,
        )
        .unwrap();

        assert_eq!(info.latest_version, "1.0.0");
        assert_eq!(info.latest_build.as_deref(), Some("202610301030"));
        assert_eq!(info.current_build.as_deref(), Some("202610300712"));
        assert_eq!(
            info.installer.unwrap().url,
            "https://downloads.aicountly.com/remote/1.0.0/Aicountly-Remote-1.0.0-b202610301030-x64-Setup.exe"
        );

        for installed in [Some("202610301030"), Some("202610311200"), None] {
            assert_eq!(
                evaluate(
                    &built("1.0.0", "202610301030"),
                    "1.0.0",
                    installed,
                    "windows",
                    "x64",
                    DOWNLOADS_BASE_URL
                ),
                None,
                "installed build {installed:?}: the same build, a later one, or a local build is told nothing"
            );
        }
    }

    #[test]
    fn the_version_outranks_the_build() {
        let info = evaluate(
            &built("1.1.0", "202601010000"),
            "1.0.0",
            Some("202610301030"),
            "macos",
            "arm64",
            DOWNLOADS_BASE_URL,
        )
        .unwrap();
        assert_eq!(info.latest_version, "1.1.0");
        assert_eq!(info.latest_build.as_deref(), Some("202601010000"));

        assert_eq!(
            evaluate(
                &built("1.0.0", "209912312359"),
                "1.1.0",
                Some("202610301030"),
                "macos",
                "arm64",
                DOWNLOADS_BASE_URL
            ),
            None,
            "an older version is never offered, however late its build"
        );
    }

    #[test]
    fn another_architecture_still_learns_of_a_later_build() {
        let info = evaluate(
            &built("1.0.0", "202610301030"),
            "1.0.0",
            Some("202610300712"),
            "windows",
            "arm64",
            DOWNLOADS_BASE_URL,
        )
        .unwrap();

        assert_eq!(info.installer, None);
        assert_eq!(info.latest_build.as_deref(), Some("202610301030"));
    }

    #[test]
    fn a_newer_release_offers_this_platforms_own_installer() {
        let windows = evaluate(
            &manifest("1.1.0"),
            "1.0.0",
            None,
            "windows",
            "x64",
            DOWNLOADS_BASE_URL,
        )
        .unwrap();
        let installer = windows.installer.unwrap();
        assert_eq!(windows.latest_version, "1.1.0");
        assert_eq!(windows.current_version, "1.0.0");
        assert_eq!(
            installer.url,
            "https://downloads.aicountly.com/remote/1.1.0/Aicountly-Remote-1.1.0-x64-Setup.exe"
        );

        let mac = evaluate(
            &manifest("1.1.0"),
            "1.0.0",
            None,
            "macos",
            "arm64",
            DOWNLOADS_BASE_URL,
        )
        .unwrap();
        assert_eq!(
            mac.installer.unwrap().url,
            "https://downloads.aicountly.com/remote/1.1.0/Aicountly-Remote-1.1.0-arm64.dmg"
        );

        let intel = evaluate(
            &manifest("1.1.0"),
            "1.0.0",
            None,
            "macos",
            "x64",
            DOWNLOADS_BASE_URL,
        )
        .unwrap();
        assert_eq!(
            intel.installer.unwrap().file,
            "Aicountly-Remote-1.1.0-x64.dmg"
        );
    }

    #[test]
    fn an_up_to_date_installation_is_told_nothing() {
        assert_eq!(
            evaluate(
                &manifest("1.0.0"),
                "1.0.0",
                None,
                "windows",
                "x64",
                DOWNLOADS_BASE_URL
            ),
            None
        );
        assert_eq!(
            evaluate(
                &manifest("0.9.0"),
                "1.0.0",
                None,
                "windows",
                "x64",
                DOWNLOADS_BASE_URL
            ),
            None
        );
    }

    #[test]
    fn a_release_with_no_build_for_this_platform_is_not_an_update_for_it() {
        let mut windows_only = manifest("1.1.0");
        windows_only.files.retain(|file| file.platform == "windows");

        assert_eq!(
            evaluate(
                &windows_only,
                "1.0.0",
                None,
                "macos",
                "arm64",
                DOWNLOADS_BASE_URL
            ),
            None
        );
        assert!(evaluate(
            &windows_only,
            "1.0.0",
            None,
            "windows",
            "x64",
            DOWNLOADS_BASE_URL
        )
        .is_some());
    }

    #[test]
    fn another_architecture_is_an_update_with_only_the_download_page() {
        let info = evaluate(
            &manifest("1.1.0"),
            "1.0.0",
            None,
            "windows",
            "arm64",
            DOWNLOADS_BASE_URL,
        )
        .unwrap();

        assert_eq!(info.installer, None);
        assert_eq!(info.download_page_url, DOWNLOAD_PAGE_URL);
    }

    #[test]
    fn a_sandbox_or_foreign_manifest_is_ignored() {
        let mut sandbox = manifest("9.0.0");
        sandbox.channel = "sandbox".into();
        assert_eq!(
            evaluate(
                &sandbox,
                "1.0.0",
                None,
                "windows",
                "x64",
                DOWNLOADS_BASE_URL
            ),
            None
        );

        let mut connect = manifest("9.0.0");
        connect.product = "connect".into();
        assert_eq!(
            evaluate(
                &connect,
                "1.0.0",
                None,
                "windows",
                "x64",
                DOWNLOADS_BASE_URL
            ),
            None
        );
    }

    #[test]
    fn a_file_name_that_could_escape_its_directory_is_never_fetched() {
        for name in [
            "../evil.exe",
            "a/b.exe",
            "a\\b.exe",
            ".hidden",
            "",
            "x?y.exe",
            "x y.exe",
        ] {
            let mut m = manifest("1.1.0");
            m.files[0].file = name.into();

            let info = evaluate(&m, "1.0.0", None, "windows", "x64", DOWNLOADS_BASE_URL).unwrap();
            assert_eq!(
                info.installer, None,
                "{name:?} must not be offered as an installer"
            );
        }
    }

    #[tokio::test]
    async fn the_whole_flow_check_then_download_then_verify() {
        let name = "Aicountly-Remote-1.1.0-x64-Setup.exe";
        let base = serve(HashMap::from([
            (
                "/manifest.json".to_owned(),
                (200, manifest_json("1.1.0").into_bytes()),
            ),
            (format!("/1.1.0/{name}"), (200, INSTALLER.to_vec())),
        ]))
        .await;

        let checker = UpdateChecker::with_urls(&format!("{base}/manifest.json"), &base).unwrap();

        let info = checker
            .check("1.0.0", None, "windows", "x64")
            .await
            .unwrap()
            .unwrap();
        let installer = info.installer.unwrap();
        assert_eq!(installer.url, format!("{base}/1.1.0/{name}"));

        let dir = temp_dir("flow");
        let path = checker.download(&installer, &dir).await.unwrap();

        assert_eq!(path, dir.join(name));
        assert_eq!(std::fs::read(&path).unwrap(), INSTALLER);
        assert!(!dir.join(format!("{name}.part")).exists());

        let _ = std::fs::remove_dir_all(&dir);
    }

    #[tokio::test]
    async fn a_later_build_is_fetched_from_its_own_name() {
        let release = built("1.0.0", "202610301030");
        let name = "Aicountly-Remote-1.0.0-b202610301030-x64-Setup.exe";
        let base = serve(HashMap::from([
            (
                "/manifest.json".to_owned(),
                (200, to_json(&release).into_bytes()),
            ),
            (format!("/1.0.0/{name}"), (200, INSTALLER.to_vec())),
        ]))
        .await;

        let checker = UpdateChecker::with_urls(&format!("{base}/manifest.json"), &base).unwrap();

        let info = checker
            .check("1.0.0", Some("202610300712"), "windows", "x64")
            .await
            .unwrap()
            .unwrap();
        assert_eq!(info.latest_build.as_deref(), Some("202610301030"));

        let dir = temp_dir("build");
        let path = checker
            .download(&info.installer.unwrap(), &dir)
            .await
            .unwrap();
        assert_eq!(path, dir.join(name));

        assert_eq!(
            checker
                .check("1.0.0", Some("202610301030"), "windows", "x64")
                .await
                .unwrap(),
            None,
            "once installed, the same build is not offered again"
        );

        let _ = std::fs::remove_dir_all(&dir);
    }

    #[tokio::test]
    async fn an_up_to_date_check_against_the_server_is_none() {
        let base = serve(HashMap::from([(
            "/manifest.json".to_owned(),
            (200, manifest_json("1.0.0").into_bytes()),
        )]))
        .await;

        let checker = UpdateChecker::with_urls(&format!("{base}/manifest.json"), &base).unwrap();

        assert_eq!(
            checker
                .check("1.0.0", None, "windows", "x64")
                .await
                .unwrap(),
            None
        );
    }

    #[tokio::test]
    async fn a_tampered_or_truncated_download_is_refused_and_removed() {
        let name = "Aicountly-Remote-1.1.0-x64-Setup.exe";

        for body in [
            b"pretend this is a TAMPERED installer".to_vec(),
            INSTALLER[..10].to_vec(),
            [INSTALLER, b"and more"].concat(),
        ] {
            let base = serve(HashMap::from([(format!("/1.1.0/{name}"), (200, body))])).await;
            let checker =
                UpdateChecker::with_urls(&format!("{base}/manifest.json"), &base).unwrap();

            let installer = UpdateInstaller {
                file: name.into(),
                url: format!("{base}/1.1.0/{name}"),
                size: INSTALLER.len() as u64,
                sha256: sha(INSTALLER),
            };

            let dir = temp_dir("tampered");
            let error = checker.download(&installer, &dir).await.unwrap_err();

            assert!(matches!(error, UpdateError::Mismatch), "got {error:?}");
            assert!(
                !dir.join(name).exists(),
                "an unverified file must not be left under the installer's name"
            );
            assert!(!dir.join(format!("{name}.part")).exists());

            let _ = std::fs::remove_dir_all(&dir);
        }
    }

    #[tokio::test]
    async fn an_unreachable_or_broken_feed_is_an_error_not_a_prompt() {
        // Nothing listening.
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let address = listener.local_addr().unwrap();
        drop(listener);

        let checker =
            UpdateChecker::with_urls(&format!("http://{address}/manifest.json"), "http://x")
                .unwrap();
        assert!(matches!(
            checker.check("1.0.0", None, "windows", "x64").await,
            Err(UpdateError::Transport(_))
        ));

        let base = serve(HashMap::from([
            (
                "/manifest.json".to_owned(),
                (200, b"<html>not json</html>".to_vec()),
            ),
            ("/missing.json".to_owned(), (503, b"down".to_vec())),
        ]))
        .await;

        let broken = UpdateChecker::with_urls(&format!("{base}/manifest.json"), &base).unwrap();
        assert!(matches!(
            broken.check("1.0.0", None, "windows", "x64").await,
            Err(UpdateError::Manifest(_))
        ));

        let down = UpdateChecker::with_urls(&format!("{base}/missing.json"), &base).unwrap();
        assert!(matches!(
            down.check("1.0.0", None, "windows", "x64").await,
            Err(UpdateError::Status(503))
        ));
    }

    #[test]
    fn the_published_feed_is_https_on_the_downloads_host() {
        assert!(UPDATE_MANIFEST_URL.starts_with("https://downloads.aicountly.com/"));
        assert!(DOWNLOADS_BASE_URL.starts_with("https://downloads.aicountly.com/"));
        assert!(DOWNLOAD_PAGE_URL.starts_with("https://aicountly.com/"));
    }
}
