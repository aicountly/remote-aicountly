//! The AICOUNTLY Remote web portal, inside this window.
//!
//! Everything the portal offers — the dashboard, starting and joining a
//! session, support requests, history, computers, settings and the
//! administration pages — is the web application at the deployment's own
//! origin (`api_base_url` without its `/api`). Rather than a second copy of
//! every page, the window shows that application in a **child webview** laid
//! over the window's content area. The menus, permissions and behaviour are
//! the web portal's own, so they cannot drift from it.
//!
//! The boundary that makes this safe is Tauri's, and it is not configurable
//! by accident: a webview showing a remote origin can reach none of this
//! application's commands unless a capability names that origin under
//! `remote`, and none does (`capabilities/default.json`). The portal is a
//! browser tab that happens to be drawn inside this window.
//!
//! What it may load is narrower than a browser tab:
//!
//!   * the portal's own origin, which is the only thing it is ever *told* to
//!     open;
//!   * the hosts a sign-in passes through — AICOUNTLY's own and the identity
//!     providers the AICOUNTLY sign-in page offers — so signing in works here
//!     the way it does in a browser;
//!   * nothing else. Any other link opens in the person's own browser, and a
//!     link that asks for a new window does too, unless it is the portal
//!     itself, which then opens in place.

use std::sync::{Mutex, OnceLock};

use tauri::{Manager, Url};

/// The child webview's label.
pub const PORTAL_LABEL: &str = "portal";

/// Where the portal sits in the window, in logical pixels — what the window's
/// own layout measured for the space it leaves for it.
#[derive(Debug, Clone, Copy, PartialEq, serde::Deserialize)]
pub struct PortalBounds {
    /// From the left of the window's content area.
    pub x: f64,
    /// From the top of the window's content area.
    pub y: f64,
    /// Width.
    pub width: f64,
    /// Height.
    pub height: f64,
}

impl PortalBounds {
    fn rect(self) -> tauri::Rect {
        tauri::Rect {
            position: tauri::LogicalPosition::new(self.x.max(0.0), self.y.max(0.0)).into(),
            size: tauri::LogicalSize::new(self.width.max(1.0), self.height.max(1.0)).into(),
        }
    }
}

/// Identity providers the AICOUNTLY sign-in page sends people to. A sign-in
/// that leaves for one of these must stay in the portal, or it would finish
/// in a browser and the portal would never learn of it.
const IDENTITY_PROVIDERS: &[&str] = &[
    "accounts.google.com",
    "accounts.youtube.com",
    "login.microsoftonline.com",
    "login.live.com",
    "login.microsoft.com",
    "www.linkedin.com",
    "appleid.apple.com",
];

/// The portal's origin for this deployment: `api_base_url` without `/api`,
/// HTTPS only.
#[must_use]
pub fn portal_origin(api_base_url: &str) -> Option<Url> {
    let origin = api_base_url.trim_end_matches('/').strip_suffix("/api")?;
    let url = Url::parse(origin).ok()?;

    (url.scheme() == "https" && url.host_str().is_some()).then_some(url)
}

/// The portal URL for a path the window asked for.
///
/// Only a path — never a URL — so the window cannot point the portal anywhere
/// but this deployment, and only a plain absolute path: `//host` would be a
/// different host, and a backslash is one on Windows.
pub fn portal_url(origin: &Url, path: &str) -> Result<Url, String> {
    let valid = path.starts_with('/')
        && !path.starts_with("//")
        && !path.contains('\\')
        && !path.chars().any(char::is_control);

    if !valid {
        return Err("That is not a page in AICOUNTLY Remote.".into());
    }

    let url = origin
        .join(path)
        .map_err(|_| "That is not a page in AICOUNTLY Remote.".to_owned())?;

    if url.origin() != origin.origin() {
        return Err("That is not a page in AICOUNTLY Remote.".into());
    }

    Ok(url)
}

/// Whether the portal may navigate to `url` itself.
#[must_use]
pub fn may_navigate(url: &Url, origin: &Url) -> bool {
    match url.scheme() {
        // What a webview loads before anything else, and the error pages it
        // shows when a load fails.
        "about" => return true,
        "https" => {}
        _ => return false,
    }

    if url.origin() == origin.origin() {
        return true;
    }

    let Some(host) = url.host_str() else {
        return false;
    };

    host == "aicountly.com"
        || host.ends_with(".aicountly.com")
        || IDENTITY_PROVIDERS.contains(&host)
}

// ------------------------------------------------------ signing the portal in

/// The confirming person's sign-in, as a one-time code the agent's poll
/// received (`commands::poll_desktop_sign_in`), waiting for the portal to
/// redeem it. Held here rather than in the window, which never sees it.
static HANDOFF: Mutex<Option<String>> = Mutex::new(None);

/// Keep a hand-off code for the next time the portal is shown.
pub fn stash_handoff(code: String) {
    if is_handoff_code(&code) {
        if let Ok(mut held) = HANDOFF.lock() {
            *held = Some(code);
        }
    }
}

fn take_handoff() -> Option<String> {
    HANDOFF.lock().ok()?.take()
}

/// 64 lowercase hex, as the server issues it — and therefore safe to put in
/// a URL fragment unescaped.
fn is_handoff_code(code: &str) -> bool {
    code.len() == 64
        && code
            .bytes()
            .all(|byte| matches!(byte, b'0'..=b'9' | b'a'..=b'f'))
}

/// Random, new every time the application starts. The portal page reads it
/// from `__AICOUNTLY_REMOTE_DESKTOP__` (set by [`marker_script`]) and accepts a
/// hand-off only when the address carries the same value — which a link
/// cannot know, so no link can sign the portal in as somebody else.
#[must_use]
pub fn launch_nonce() -> &'static str {
    static NONCE: OnceLock<String> = OnceLock::new();

    NONCE.get_or_init(|| {
        format!(
            "{}{}",
            uuid::Uuid::new_v4().simple(),
            uuid::Uuid::new_v4().simple()
        )
    })
}

/// The page that redeems a hand-off, with the code and this launch's nonce in
/// the fragment — never sent to a server.
///
/// A path of its own, not `/`: if the portal were already on `/`, an address
/// that differed only in its fragment would not load the page again, and the
/// web application would never see the code. The web application turns
/// `/auth/desktop` back into `/` before anything renders.
#[must_use]
pub fn handoff_path(code: &str, nonce: &str) -> String {
    format!("/auth/desktop#desktop_handoff={code}&n={nonce}")
}

/// Tells the portal page it is inside the desktop application, and this
/// launch's nonce — on the portal's own origin only, never on a sign-in page
/// it passes through.
#[must_use]
pub fn marker_script(origin: &Url, nonce: &str) -> String {
    let origin = origin.origin().ascii_serialization();

    format!(
        "if (window.location.origin === {origin:?}) {{ \
           Object.defineProperty(window, '__AICOUNTLY_REMOTE_DESKTOP__', \
             {{ value: Object.freeze({{ nonce: {nonce:?} }}), configurable: false, enumerable: false, writable: false }}); \
         }}"
    )
}

/// Whether a link the portal would leave for belongs in the person's own
/// browser. Only `https`: nothing in the portal has reason to open anything
/// else, and `file:` or a custom scheme must never be handed to the system.
#[must_use]
pub fn opens_in_browser(url: &Url) -> bool {
    url.scheme() == "https"
}

/// Show the portal at `bounds`, creating it the first time, and open `path`
/// if one is given.
///
/// **Only ever from an `async` command or another thread** — never a
/// synchronous command or an event handler. Creating a webview waits on the
/// main thread, and a synchronous command *is* the main thread, so on Windows
/// it waits for itself forever (Tauri documents this on every webview
/// builder; it is a WebView2 limitation). That is what 1.0.2 shipped: the
/// first `portal_show` hung, and with the main thread stuck every command
/// after it — Open in browser, Back, Reload, Sign in again — went unanswered.
/// `commands::tests::every_command_that_touches_a_webview_is_async` holds the
/// line.
pub fn show(
    app: &tauri::AppHandle,
    origin: &Url,
    path: Option<&str>,
    bounds: PortalBounds,
) -> Result<(), String> {
    // A sign-in waiting to be handed over goes first: the portal opens on the
    // page that redeems it, and lands on its dashboard signed in.
    let target = match take_handoff() {
        Some(code) => Some(portal_url(origin, &handoff_path(&code, launch_nonce()))?),
        None => path.map(|path| portal_url(origin, path)).transpose()?,
    };

    if let Some(portal) = app.get_webview(PORTAL_LABEL) {
        portal
            .set_bounds(bounds.rect())
            .map_err(|error| error.to_string())?;
        if let Some(target) = target {
            portal.navigate(target).map_err(|error| error.to_string())?;
        }
        portal.show().map_err(|error| error.to_string())?;
        return Ok(());
    }

    let window = app
        .get_window("main")
        .ok_or("The AICOUNTLY Remote window is not open.")?;

    let start = match target {
        Some(target) => target,
        None => origin.clone(),
    };

    let navigation_origin = origin.clone();
    let window_origin = origin.clone();
    let handle = app.clone();

    let builder =
        tauri::webview::WebviewBuilder::new(PORTAL_LABEL, tauri::WebviewUrl::External(start))
            .initialization_script(marker_script(origin, launch_nonce()))
            .on_navigation(move |url| {
                if may_navigate(url, &navigation_origin) {
                    return true;
                }

                if opens_in_browser(url) {
                    let _ = open::that_detached(url.as_str());
                }
                false
            })
            .on_new_window(move |url, _features| {
                // The portal's own pages open in place; everything else in the
                // browser. Either way no second window appears.
                //
                // This runs inside WebView2's own callback, on the main
                // thread, so nothing here may wait on that thread: the
                // navigation is handed to the async runtime instead.
                if url.origin() == window_origin.origin() {
                    let handle = handle.clone();
                    tauri::async_runtime::spawn(async move {
                        if let Some(portal) = handle.get_webview(PORTAL_LABEL) {
                            let _ = portal.navigate(url);
                        }
                    });
                } else if opens_in_browser(&url) {
                    let _ = open::that_detached(url.as_str());
                }

                tauri::webview::NewWindowResponse::Deny
            });

    let rect = bounds.rect();
    window
        .add_child(builder, rect.position, rect.size)
        .map_err(|error| error.to_string())?;

    Ok(())
}

/// Move or resize the portal, when the window's layout changes.
pub fn set_bounds(app: &tauri::AppHandle, bounds: PortalBounds) -> Result<(), String> {
    match app.get_webview(PORTAL_LABEL) {
        Some(portal) => portal
            .set_bounds(bounds.rect())
            .map_err(|error| error.to_string()),
        None => Ok(()),
    }
}

/// Take the portal off the screen. It keeps its page and its sign-in, so
/// coming back to it is instant.
pub fn hide(app: &tauri::AppHandle) -> Result<(), String> {
    match app.get_webview(PORTAL_LABEL) {
        Some(portal) => portal.hide().map_err(|error| error.to_string()),
        None => Ok(()),
    }
}

/// Back, reload, or open the current page in the browser.
pub fn act(app: &tauri::AppHandle, action: &str) -> Result<(), String> {
    let portal = app
        .get_webview(PORTAL_LABEL)
        .ok_or("AICOUNTLY Remote is not open yet.")?;

    match action {
        "back" => portal
            .eval("history.back()")
            .map_err(|error| error.to_string()),
        "reload" => portal.reload().map_err(|error| error.to_string()),
        "open_in_browser" => {
            let url = portal.url().map_err(|error| error.to_string())?;
            if !opens_in_browser(&url) {
                return Err("This page cannot be opened in a browser.".into());
            }
            open::that_detached(url.as_str()).map_err(|error| error.to_string())
        }
        // "Sign this computer out": the portal's sign-in goes with it, so the
        // next person to use this computer is not signed in as the last one.
        "sign_out" => {
            portal
                .clear_all_browsing_data()
                .map_err(|error| error.to_string())?;
            portal.hide().map_err(|error| error.to_string())
        }
        _ => Err("Unknown action.".into()),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn origin() -> Url {
        portal_origin("https://remote.aicountly.com/api").unwrap()
    }

    fn url(value: &str) -> Url {
        Url::parse(value).unwrap()
    }

    #[test]
    fn the_portal_is_the_deployment_one_level_above_its_api() {
        assert_eq!(origin().as_str(), "https://remote.aicountly.com/");
        assert_eq!(
            portal_origin("https://remote.gh.aicountly.com/api/")
                .unwrap()
                .as_str(),
            "https://remote.gh.aicountly.com/"
        );

        // Not HTTPS, or not an API URL the portal can be derived from.
        assert_eq!(portal_origin("http://remote.aicountly.com/api"), None);
        assert_eq!(portal_origin("https://remote.aicountly.com/v1"), None);
        assert_eq!(portal_origin("file:///api"), None);
    }

    #[test]
    fn a_page_is_a_path_on_the_portal_and_nothing_else() {
        assert_eq!(
            portal_url(&origin(), "/").unwrap().as_str(),
            "https://remote.aicountly.com/"
        );
        assert_eq!(
            portal_url(&origin(), "/desktop-signin?code=ABCD-1234")
                .unwrap()
                .as_str(),
            "https://remote.aicountly.com/desktop-signin?code=ABCD-1234"
        );
        assert_eq!(
            portal_url(&origin(), "/sessions").unwrap().as_str(),
            "https://remote.aicountly.com/sessions"
        );

        for path in [
            "https://evil.example/",
            "//evil.example/x",
            "/\\evil.example",
            "sessions",
            "",
            "javascript:alert(1)",
            "/x\ny",
        ] {
            assert!(
                portal_url(&origin(), path).is_err(),
                "{path:?} must be refused"
            );
        }
    }

    #[test]
    fn the_portal_and_its_sign_in_stay_in_the_window() {
        for candidate in [
            "https://remote.aicountly.com/sessions",
            "https://my.aicountly.com/login?returnUrl=x",
            "https://aicountly.com/remote",
            "https://accounts.google.com/o/oauth2/auth?x=1",
            "https://login.microsoftonline.com/common/oauth2",
            "about:blank",
        ] {
            assert!(
                may_navigate(&url(candidate), &origin()),
                "{candidate:?} should stay in the portal"
            );
        }
    }

    #[test]
    fn everything_else_leaves_for_the_browser_or_is_refused() {
        for candidate in [
            "https://example.com/",
            "https://aicountly.com.evil.example/",
            "https://evilaicountly.com/",
            "http://remote.aicountly.com/",
            "file:///C:/Windows/System32/cmd.exe",
            "javascript:alert(1)",
        ] {
            assert!(
                !may_navigate(&url(candidate), &origin()),
                "{candidate:?} must not load in the portal"
            );
        }

        assert!(opens_in_browser(&url("https://example.com/")));
        assert!(!opens_in_browser(&url(
            "file:///C:/Windows/System32/cmd.exe"
        )));
        assert!(!opens_in_browser(&url("ms-settings:privacy")));
        assert!(!opens_in_browser(&url("http://example.com/")));
    }

    #[test]
    fn a_handoff_opens_the_portal_start_page_with_the_code_and_nonce_in_the_fragment() {
        let code = "c".repeat(64);
        let url = portal_url(&origin(), &handoff_path(&code, "n0nce")).unwrap();

        assert_eq!(url.path(), "/auth/desktop");
        assert_eq!(url.query(), None, "the code must never be sent to a server");
        assert_eq!(
            url.fragment(),
            Some(format!("desktop_handoff={code}&n=n0nce").as_str())
        );
    }

    #[test]
    fn only_a_well_formed_code_is_kept_for_the_portal() {
        assert!(is_handoff_code(&"0a".repeat(32)));

        for code in [
            "",
            "short",
            &"Z".repeat(64),
            &"A".repeat(64),
            &format!("{}&n=x", "a".repeat(60)),
        ] {
            assert!(!is_handoff_code(code), "{code:?} must not be kept");
        }
    }

    #[test]
    fn a_stashed_code_is_given_up_once() {
        let code = "d".repeat(64);
        stash_handoff(code.clone());

        assert_eq!(take_handoff(), Some(code));
        assert_eq!(take_handoff(), None);

        stash_handoff("not a code".into());
        assert_eq!(take_handoff(), None);
    }

    #[test]
    fn the_nonce_is_random_hex_and_the_same_for_the_whole_launch() {
        let nonce = launch_nonce();

        assert_eq!(nonce.len(), 64);
        assert!(nonce.bytes().all(|byte| byte.is_ascii_hexdigit()));
        assert_eq!(launch_nonce(), nonce);
    }

    #[test]
    fn the_marker_is_set_on_the_portal_origin_only() {
        let script = marker_script(&origin(), "abc123");

        assert!(script.contains(r#"window.location.origin === "https://remote.aicountly.com""#));
        assert!(script.contains("__AICOUNTLY_REMOTE_DESKTOP__"));
        assert!(script.contains(r#"nonce: "abc123""#));
    }

    #[test]
    fn bounds_never_collapse_to_nothing_or_go_negative() {
        let rect = PortalBounds {
            x: -5.0,
            y: 10.0,
            width: 0.0,
            height: -1.0,
        }
        .rect();

        assert_eq!(rect.position, tauri::LogicalPosition::new(0.0, 10.0).into());
        assert_eq!(rect.size, tauri::LogicalSize::new(1.0, 1.0).into());
    }
}
