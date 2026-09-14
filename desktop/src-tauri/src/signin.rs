//! Signing in through the AICOUNTLY portal, for the one call that needs it.
//!
//! A machine cannot hold a portal session — see `docs/desktop/DEVICE_ENROLMENT.md`.
//! A *person* signing in to authorise registering this machine still has to go
//! through the same portal every other AICOUNTLY product uses, and the only
//! way a native window can receive what the portal hands back is to be
//! listening on `127.0.0.1` when the browser is told to come back there — the
//! loopback pattern RFC 8252 describes for exactly this shape of application.
//!
//! ```text
//!   this machine                             the portal, in the system browser
//!   ───────────                              ──────────────────────────────
//!   bind 127.0.0.1:0                ──open──▶  {portal}/login/authentication_jump/remote
//!   accept one request        ◀───redirect───  ?returnUrl=http://127.0.0.1:{port}/auth/callback
//!   read `auth_token`, answer 200
//! ```
//!
//! Nothing here mints a session key. The exchange for `ses_key` needs the
//! product's own API and its relay-then-direct fallback, which already exists
//! in TypeScript for the browser (`web/src/auth/portal.ts`) — duplicating it
//! in Rust would be a second implementation of the same policy to keep in
//! sync. This hands the window the one thing only a native process can get:
//! the `auth_token` itself.
//!
//! The literal `127.0.0.1` is deliberate — never `localhost` — so there is no
//! DNS step and no ambiguity with a system that resolves it to `::1`.

use std::collections::HashMap;
use std::time::Duration;

use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::{TcpListener, TcpStream};
use tokio::time::timeout;

/// How long to wait for the browser round trip.
///
/// Long enough for a person to type a password or get through 2FA; bounded so
/// a window nobody is looking at does not hold a listener open forever.
const SIGN_IN_TIMEOUT: Duration = Duration::from_secs(300);

/// The largest request line this will read.
///
/// A browser's `GET` line with a token on it, generously bounded — not a
/// whole request a hostile local process could use to make this allocate.
const MAX_REQUEST_LINE_BYTES: usize = 8 * 1024;

/// The only path this answers to.
const CALLBACK_PATH: &str = "/auth/callback";

/// Why signing in did not produce a token.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum SignInError {
    /// The loopback listener could not be opened.
    #[error("could not listen for the portal's answer: {0}")]
    Listen(String),
    /// The system browser could not be opened.
    #[error("could not open the portal in a browser: {0}")]
    Browser(String),
    /// Nobody finished signing in within the time allowed.
    #[error("signing in was not completed in time")]
    TimedOut,
    /// The portal's answer named a reason it could not complete the sign-in.
    #[error("{0}")]
    Refused(String),
    /// The socket failed before a usable request arrived.
    #[error("the sign-in connection failed: {0}")]
    Transport(String),
}

/// Open the portal and wait for it to answer this machine's loopback port.
///
/// `portal_url` is `AgentConfig::portal_url` — already validated `https`, or
/// `http://localhost` in a debug build. The returned string is the raw
/// `auth_token` the portal issued; exchanging it for a `ses_key` is the
/// caller's job, not this one's.
pub async fn sign_in(portal_url: &str) -> Result<String, SignInError> {
    let listener = SignInListener::bind().await?;

    let target = format!(
        "{}/login/authentication_jump/remote?returnUrl={}",
        portal_url.trim_end_matches('/'),
        urlencode(&listener.callback_url()),
    );

    open::that(&target).map_err(|error| SignInError::Browser(error.to_string()))?;

    listener.wait_for_token().await
}

/// A loopback listener bound and waiting for the portal's redirect.
struct SignInListener {
    listener: TcpListener,
    port: u16,
}

impl SignInListener {
    /// Bind an OS-assigned loopback port. Nothing is accepted yet.
    async fn bind() -> Result<Self, SignInError> {
        let listener = TcpListener::bind(("127.0.0.1", 0))
            .await
            .map_err(|error| SignInError::Listen(error.to_string()))?;

        let port = listener
            .local_addr()
            .map_err(|error| SignInError::Listen(error.to_string()))?
            .port();

        Ok(Self { listener, port })
    }

    /// The address the portal should redirect back to.
    fn callback_url(&self) -> String {
        format!("http://127.0.0.1:{}{CALLBACK_PATH}", self.port)
    }

    /// Wait for the portal's redirect, bounded by [`SIGN_IN_TIMEOUT`].
    async fn wait_for_token(self) -> Result<String, SignInError> {
        timeout(SIGN_IN_TIMEOUT, accept_until_answered(self.listener))
            .await
            .map_err(|_| SignInError::TimedOut)?
    }
}

/// What one connection turned out to be.
enum Accepted {
    /// Not the callback — a favicon request, a stray local probe. Keep
    /// listening; this is normal.
    Ignored,
    /// The callback, carrying a token.
    Token(String),
    /// The callback, but the portal reported it could not complete the
    /// sign-in — the person cancelled, or it refused for its own reasons.
    Refused(String),
}

/// Accept connections until one is the callback, one way or the other.
///
/// A connection that is not the callback — a probe, a malformed request, a
/// favicon fetch some browsers send speculatively — is answered and
/// forgotten. Only the callback itself, successful or not, ends the wait.
async fn accept_until_answered(listener: TcpListener) -> Result<String, SignInError> {
    loop {
        let (stream, _) = listener
            .accept()
            .await
            .map_err(|error| SignInError::Transport(error.to_string()))?;

        match handle_one(stream).await {
            Ok(Accepted::Token(token)) => return Ok(token),
            Ok(Accepted::Refused(reason)) => return Err(SignInError::Refused(reason)),
            Ok(Accepted::Ignored) => continue,
            Err(error) => {
                tracing::debug!(%error, "a sign-in connection was not usable");
                continue;
            }
        }
    }
}

/// Read one request, answer it, and say what it was.
async fn handle_one(mut stream: TcpStream) -> Result<Accepted, SignInError> {
    let request_line = read_request_line(&mut stream).await?;

    let Some(target) = parse_get_target(&request_line) else {
        respond(&mut stream, 400, "Bad request").await;
        return Ok(Accepted::Ignored);
    };

    let (path, query) = target.split_once('?').unwrap_or((target.as_str(), ""));

    if path != CALLBACK_PATH {
        respond(&mut stream, 404, "Not found").await;
        return Ok(Accepted::Ignored);
    }

    let params = parse_query(query);

    if let Some(token) = params.get("auth_token") {
        respond_html(&mut stream, &sign_in_page(true)).await;
        return Ok(Accepted::Token(token.clone()));
    }

    let reason = params
        .get("auth_error")
        .cloned()
        .unwrap_or_else(|| "the portal sent no token".to_owned());

    respond_html(&mut stream, &sign_in_page(false)).await;
    Ok(Accepted::Refused(reason))
}

/// Read one line from a fresh connection, bounded.
///
/// Only the request line is ever read — no header and no body, because
/// nothing here needs one. Reading in chunks and stopping at the first `\n`
/// is what keeps a connection that never sends one from growing this past
/// [`MAX_REQUEST_LINE_BYTES`].
async fn read_request_line(stream: &mut TcpStream) -> Result<String, SignInError> {
    let mut buffer = Vec::new();
    let mut chunk = [0u8; 512];

    loop {
        let read = stream
            .read(&mut chunk)
            .await
            .map_err(|error| SignInError::Transport(error.to_string()))?;

        if read == 0 {
            return Err(SignInError::Transport(
                "the connection closed before sending a request".into(),
            ));
        }

        buffer.extend_from_slice(&chunk[..read]);

        if buffer.len() > MAX_REQUEST_LINE_BYTES {
            return Err(SignInError::Transport("the request was too large".into()));
        }

        if let Some(newline) = buffer.iter().position(|&byte| byte == b'\n') {
            buffer.truncate(newline);
            break;
        }
    }

    Ok(String::from_utf8_lossy(&buffer)
        .trim_end_matches('\r')
        .to_owned())
}

/// The request-target out of a `GET` request line, or `None` for anything
/// else.
///
/// Nothing here needs another method: the portal's redirect is always a
/// browser navigation, which is always `GET`.
fn parse_get_target(request_line: &str) -> Option<String> {
    let mut parts = request_line.split(' ');

    if parts.next()? != "GET" {
        return None;
    }

    Some(parts.next()?.to_owned())
}

/// Parse `a=b&c=d` into decoded key/value pairs.
///
/// A tiny decoder rather than a dependency, matching how the signalling
/// client encodes a token: the input is a handful of known parameter names on
/// a redirect this process itself asked for, not an arbitrary document to
/// fully parse. A key repeated more than once keeps its last value.
fn parse_query(query: &str) -> HashMap<String, String> {
    query
        .split('&')
        .filter(|pair| !pair.is_empty())
        .map(|pair| {
            let (key, value) = pair.split_once('=').unwrap_or((pair, ""));

            (url_decode(key), url_decode(value))
        })
        .collect()
}

/// Percent-decode, and turn `+` into a space the way a query string does.
///
/// Indexed rather than iterator-driven: an iterator's `.next()` calls for the
/// two digits after a `%` consume them whether or not they turn out to form a
/// valid escape, so a malformed sequence with exactly one hex digit — `%2` at
/// the end of a string — would silently drop that digit rather than keeping
/// it. Peeking by index first is what lets a rejected escape put every byte
/// it looked at back for the next iteration to read normally.
fn url_decode(value: &str) -> String {
    let bytes = value.as_bytes();
    let mut decoded = Vec::with_capacity(bytes.len());
    let mut index = 0;

    while index < bytes.len() {
        match bytes[index] {
            b'+' => {
                decoded.push(b' ');
                index += 1;
            }
            b'%' if index + 2 < bytes.len()
                && hex_digit(bytes[index + 1]).is_some()
                && hex_digit(bytes[index + 2]).is_some() =>
            {
                let high = hex_digit(bytes[index + 1]).unwrap_or(0);
                let low = hex_digit(bytes[index + 2]).unwrap_or(0);

                decoded.push(high * 16 + low);
                index += 3;
            }
            other => {
                decoded.push(other);
                index += 1;
            }
        }
    }

    String::from_utf8_lossy(&decoded).into_owned()
}

fn hex_digit(byte: u8) -> Option<u8> {
    match byte {
        b'0'..=b'9' => Some(byte - b'0'),
        b'a'..=b'f' => Some(byte - b'a' + 10),
        b'A'..=b'F' => Some(byte - b'A' + 10),
        _ => None,
    }
}

/// Percent-encode everything that is not unreserved — the same alphabet
/// `signalling::urlencode` uses, for the same reason: this is one value with
/// a known shape, not a whole URL crate in a signed binary.
fn urlencode(value: &str) -> String {
    let mut encoded = String::with_capacity(value.len());

    for byte in value.bytes() {
        match byte {
            b'A'..=b'Z' | b'a'..=b'z' | b'0'..=b'9' | b'-' | b'_' | b'.' | b'~' => {
                encoded.push(byte as char);
            }
            _ => encoded.push_str(&format!("%{byte:02X}")),
        }
    }

    encoded
}

async fn respond(stream: &mut TcpStream, status: u16, reason: &str) {
    let response = format!(
        "HTTP/1.1 {status} {reason}\r\n\
         Content-Type: text/plain; charset=utf-8\r\n\
         Content-Length: {}\r\n\
         Connection: close\r\n\r\n\
         {reason}",
        reason.len(),
    );

    let _ = stream.write_all(response.as_bytes()).await;
}

async fn respond_html(stream: &mut TcpStream, body: &str) {
    let response = format!(
        "HTTP/1.1 200 OK\r\n\
         Content-Type: text/html; charset=utf-8\r\n\
         Content-Length: {}\r\n\
         Connection: close\r\n\r\n\
         {body}",
        body.len(),
    );

    let _ = stream.write_all(response.as_bytes()).await;
}

/// What the browser tab shows once the redirect lands. Never anything with
/// the token in it — this page is the one place in the whole flow where it
/// would be visible on screen.
fn sign_in_page(success: bool) -> String {
    let (title, detail) = if success {
        (
            "Signed in",
            "You can close this tab and return to AICOUNTLY Remote.",
        )
    } else {
        (
            "Sign-in was not completed",
            "You can close this tab and try again from AICOUNTLY Remote.",
        )
    };

    format!(
        "<!doctype html><html><head><meta charset=\"utf-8\"><title>{title}</title></head>\
         <body style=\"font-family:sans-serif;text-align:center;padding:4rem\">\
         <h1>{title}</h1><p>{detail}</p></body></html>"
    )
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_get_lines_target_is_read_and_nothing_else_is_accepted() {
        assert_eq!(
            parse_get_target("GET /auth/callback?auth_token=abc HTTP/1.1"),
            Some("/auth/callback?auth_token=abc".into())
        );
        assert_eq!(parse_get_target("GET / HTTP/1.1"), Some("/".into()));

        assert_eq!(parse_get_target("POST /auth/callback HTTP/1.1"), None);
        assert_eq!(parse_get_target(""), None);
        assert_eq!(parse_get_target("GET"), None);
    }

    #[test]
    fn a_query_string_parses_into_decoded_pairs() {
        let params = parse_query("auth_token=abc.123&other=1");

        assert_eq!(params.get("auth_token"), Some(&"abc.123".to_owned()));
        assert_eq!(params.get("other"), Some(&"1".to_owned()));
    }

    #[test]
    fn an_empty_query_string_parses_to_nothing() {
        assert!(parse_query("").is_empty());
    }

    /// The token this test cares most about surviving the round trip intact:
    /// a JWT-shaped value with `.`, `-` and `_` in it.
    #[test]
    fn percent_encoding_and_decoding_round_trip() {
        let token = "header.payload-part_1.signature";

        assert_eq!(url_decode(&urlencode(token)), token);
    }

    #[test]
    fn a_space_is_carried_as_a_plus_and_read_back_as_a_space() {
        assert_eq!(url_decode("a+b"), "a b");
        assert_eq!(url_decode("a%20b"), "a b");
    }

    /// A malformed escape must not silently delete characters — it is kept
    /// literally instead.
    #[test]
    fn a_malformed_escape_is_kept_rather_than_dropped() {
        assert_eq!(url_decode("100%"), "100%");
        assert_eq!(url_decode("100%2"), "100%2");
        assert_eq!(url_decode("100%zz"), "100%zz");
    }

    #[test]
    fn a_key_with_no_value_decodes_to_an_empty_string() {
        let params = parse_query("auth_error");

        assert_eq!(params.get("auth_error"), Some(&String::new()));
    }

    /// The callback URL is what gets embedded in the portal redirect — it has
    /// to be the loopback address, literally, never `localhost`.
    #[tokio::test]
    async fn the_callback_url_is_the_literal_loopback_address() {
        let listener = SignInListener::bind().await.expect("binds");

        assert!(listener.callback_url().starts_with("http://127.0.0.1:"));
        assert!(listener.callback_url().ends_with("/auth/callback"));
        assert_ne!(listener.port, 0);
    }

    /// The property the whole module exists for: a real request on the real
    /// socket produces the token, end to end.
    #[tokio::test]
    async fn a_real_callback_request_yields_its_token() {
        let listener = SignInListener::bind().await.expect("binds");
        let port = listener.port;

        let client = tokio::spawn(async move {
            let mut stream = TcpStream::connect(("127.0.0.1", port))
                .await
                .expect("connects");
            stream
                .write_all(b"GET /auth/callback?auth_token=abc.123 HTTP/1.1\r\nHost: x\r\n\r\n")
                .await
                .expect("writes");

            let mut response = Vec::new();
            stream.read_to_end(&mut response).await.expect("reads");
            response
        });

        let token = listener.wait_for_token().await.expect("a token arrives");
        let response = client.await.expect("the client task completes");

        assert_eq!(token, "abc.123");
        assert!(String::from_utf8_lossy(&response).contains("200 OK"));
    }

    /// The portal reporting a refusal must reach the caller as a refusal, not
    /// silently be treated as an unrelated connection.
    #[tokio::test]
    async fn a_refusal_from_the_portal_is_returned_as_one() {
        let listener = SignInListener::bind().await.expect("binds");
        let port = listener.port;

        tokio::spawn(async move {
            let mut stream = TcpStream::connect(("127.0.0.1", port))
                .await
                .expect("connects");
            let _ = stream
                .write_all(b"GET /auth/callback?auth_error=access_denied HTTP/1.1\r\n\r\n")
                .await;
        });

        let error = listener.wait_for_token().await.expect_err("refused");

        assert_eq!(error, SignInError::Refused("access_denied".into()));
    }

    /// An unrelated local connection — a favicon fetch, a stray probe — must
    /// not end the wait; only the callback itself does.
    #[tokio::test]
    async fn an_unrelated_connection_is_ignored_and_the_wait_continues() {
        let listener = SignInListener::bind().await.expect("binds");
        let port = listener.port;

        tokio::spawn(async move {
            let mut probe = TcpStream::connect(("127.0.0.1", port))
                .await
                .expect("connects");
            let _ = probe.write_all(b"GET /favicon.ico HTTP/1.1\r\n\r\n").await;
            drop(probe);

            // The real callback, a moment later.
            let mut stream = TcpStream::connect(("127.0.0.1", port))
                .await
                .expect("connects");
            let _ = stream
                .write_all(b"GET /auth/callback?auth_token=real HTTP/1.1\r\n\r\n")
                .await;
        });

        let token = listener.wait_for_token().await.expect("a token arrives");

        assert_eq!(token, "real");
    }

    /// A connection that never sends a newline must not be read without
    /// bound.
    #[tokio::test]
    async fn an_oversized_request_line_is_refused_rather_than_read_without_bound() {
        let listener = SignInListener::bind().await.expect("binds");
        let port = listener.port;

        tokio::spawn(async move {
            let mut stream = TcpStream::connect(("127.0.0.1", port))
                .await
                .expect("connects");
            let oversized = vec![b'a'; MAX_REQUEST_LINE_BYTES + 1024];
            let _ = stream.write_all(b"GET /").await;
            let _ = stream.write_all(&oversized).await;
            // No newline is ever sent; the connection is simply held open.
            drop(stream);
        });

        // The oversized connection is refused and logged, not fatal to the
        // wait — the real callback that follows still gets through.
        let following = tokio::spawn(async move {
            tokio::time::sleep(Duration::from_millis(50)).await;

            let port = { port };
            let mut stream = TcpStream::connect(("127.0.0.1", port))
                .await
                .expect("connects");
            let _ = stream
                .write_all(b"GET /auth/callback?auth_token=after-oversized HTTP/1.1\r\n\r\n")
                .await;
        });

        let token = listener.wait_for_token().await.expect("a token arrives");
        let _ = following.await;

        assert_eq!(token, "after-oversized");
    }
}
