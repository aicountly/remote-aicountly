//! Browser interop: a headless Chromium decodes the agent's H.264.
//!
//! The agent's real [`WebRtcSession`] streams a moving synthetic pattern;
//! `desktop/scripts/interop/chromium-h264.cjs` drives Chromium through
//! Playwright against it. Signalling is a tiny HTTP server on 127.0.0.1 that
//! carries exactly what the relay would — the offer, the answer and trickled
//! ICE candidates — so the session is used through the same calls the host's
//! session loop makes.
//!
//! Ignored by default: it needs Node, Playwright and Playwright's Chromium
//! (`require('playwright')` must resolve), and takes a few seconds a case.
//! Run it with:
//!
//! ```text
//! cd desktop
//! cargo test -p remote-webrtc --release --test browser_interop -- --ignored --nocapture --test-threads=1
//! ```
//!
//! `--release` is optional but makes the 1080p cases run at their real frame
//! rate; a debug build encodes them at a few frames a second, which still
//! passes. Each case prints the script's report — `getStats()` for the inbound
//! video, the `<video>` element's size, and sampled colours.

// The session is the webrtc-rs one; without it there is nothing to test.
#![cfg(feature = "webrtc-rs")]

use std::collections::HashMap;
use std::io::{BufRead, BufReader};
use std::process::{Command, Stdio};
use std::time::{Duration, Instant};

use remote_device::{CaptureProfile, Frame, PixelFormat};
use remote_webrtc::webrtc_rs::{WebRtcFactory, WebRtcSession};
use remote_webrtc::{IceConfiguration, PeerSession, PeerSessionFactory, PeerState};
use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::{TcpListener, TcpStream};
use tokio::sync::{mpsc, oneshot};

/// Who makes the offer. The host does both: it offers when it was in the
/// room first, and answers when the browser was.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum Mode {
    AgentOffers,
    BrowserOffers,
}

impl Mode {
    fn flag(self) -> &'static str {
        match self {
            Self::AgentOffers => "agent-offers",
            Self::BrowserOffers => "browser-offers",
        }
    }
}

/// 1280×720, agent's offer: the plain case.
#[tokio::test]
#[ignore = "needs Node, Playwright and Chromium; see the module documentation"]
async fn chromium_decodes_720p_from_the_agents_offer() {
    run(Mode::AgentOffers, (1280, 720), (1280, 720)).await;
}

/// 1920×1080, Chromium's offer: the payload type is Chromium's number, not
/// the agent's.
#[tokio::test]
#[ignore = "needs Node, Playwright and Chromium; see the module documentation"]
async fn chromium_decodes_1080p_when_it_makes_the_offer() {
    run(Mode::BrowserOffers, (1920, 1080), (1920, 1080)).await;
}

/// A Retina display — 3024×1964, rows padded to 256 bytes as the capture
/// pads them — box-filtered down to the adaptive profile's 1920 ceiling.
#[tokio::test]
#[ignore = "needs Node, Playwright and Chromium; see the module documentation"]
async fn chromium_decodes_a_retina_screen_scaled_to_the_profile() {
    run(Mode::AgentOffers, (3024, 1964), (1920, 1246)).await;
}

/// One request from the page, and where to send the reply.
struct Request {
    method: String,
    path: String,
    body: String,
    reply: oneshot::Sender<(u16, &'static str, String)>,
}

async fn run(mode: Mode, source: (u32, u32), expected: (u32, u32)) {
    let profile = CaptureProfile::adaptive();
    assert_eq!(
        remote_webrtc::video::encoded_size(&profile, source.0, source.1),
        expected,
        "the expected size is the encoder's own"
    );

    let mut session = WebRtcFactory
        .create(IceConfiguration::default(), profile)
        .await
        .expect("a session");

    // The agent's offer is made up front, as the host makes it on
    // `peer-joined`; the page fetches it.
    let offer = match mode {
        Mode::AgentOffers => Some(session.create_offer().await.expect("an offer")),
        Mode::BrowserOffers => None,
    };

    let listener = TcpListener::bind("127.0.0.1:0").await.expect("a port");
    let url = format!("http://{}", listener.local_addr().expect("an address"));
    let (requests_tx, mut requests) = mpsc::channel::<Request>(64);
    tokio::spawn(serve(listener, requests_tx));

    let mut node = spawn_script(&url, mode, expected);
    let pattern = Pattern::new(source.0, source.1);

    let mut ticker = tokio::time::interval(Duration::from_millis(profile.frame_interval_ms()));
    ticker.set_missed_tick_behavior(tokio::time::MissedTickBehavior::Skip);

    let started = Instant::now();
    let mut offered = 0_u64;
    let mut sent_while_connected = 0_u64;
    let mut slowest_send = Duration::ZERO;

    let (status, report) = loop {
        tokio::select! {
            Some(request) = requests.recv() => {
                let reply = handle(&mut session, &offer, &request).await;
                let _ = request.reply.send(reply);
            }
            _ = ticker.tick() => {
                let frame = pattern.frame(offered, started.elapsed());
                offered += 1;

                let connected = session.state() == PeerState::Connected;
                let began = Instant::now();
                session.send_frame(frame).await.expect("a frame is accepted");
                if connected {
                    sent_while_connected += 1;
                    slowest_send = slowest_send.max(began.elapsed());
                }
            }
            finished = &mut node => break finished.expect("the script ran"),
        }

        assert!(
            started.elapsed() < Duration::from_secs(120),
            "the browser never finished"
        );
    };

    let congestion = session.congestion().await;
    session.close().await.expect("closes");

    println!(
        "agent: {mode:?}, source {}x{}, {offered} frames offered, {sent_while_connected} while connected, \
         slowest send_frame {:.1} ms, congestion reading {congestion:?}",
        source.0,
        source.1,
        slowest_send.as_secs_f64() * 1000.0,
    );
    println!(
        "{}",
        serde_json::to_string_pretty(&report).unwrap_or_default()
    );

    assert_eq!(
        status,
        Some(0),
        "the browser check failed: {}",
        report["failures"]
    );
}

/// The answers the page needs. Everything the session is asked here is what
/// the host's session loop asks it when a signalling message arrives.
async fn handle(
    session: &mut WebRtcSession,
    offer: &Option<serde_json::Value>,
    request: &Request,
) -> (u16, &'static str, String) {
    const JSON: &str = "application/json";

    let body = || serde_json::from_str::<serde_json::Value>(&request.body);

    match (request.method.as_str(), request.path.as_str()) {
        ("GET", "/") => (
            200,
            "text/html",
            "<!doctype html><title>AICOUNTLY interop</title><body></body>".into(),
        ),
        ("GET", "/offer") => match offer {
            Some(offer) => (200, JSON, offer.to_string()),
            None => (404, JSON, "{}".into()),
        },
        ("POST", "/offer") => match body() {
            Ok(remote) => match session.accept_offer(remote).await {
                Ok(answer) => (200, JSON, answer.to_string()),
                Err(error) => (
                    500,
                    JSON,
                    serde_json::json!({ "error": error.to_string() }).to_string(),
                ),
            },
            Err(error) => (
                400,
                JSON,
                serde_json::json!({ "error": error.to_string() }).to_string(),
            ),
        },
        ("POST", "/answer") => match body() {
            Ok(answer) => match session.accept_answer(answer).await {
                Ok(()) => (200, JSON, "{}".into()),
                Err(error) => (
                    500,
                    JSON,
                    serde_json::json!({ "error": error.to_string() }).to_string(),
                ),
            },
            Err(error) => (
                400,
                JSON,
                serde_json::json!({ "error": error.to_string() }).to_string(),
            ),
        },
        ("POST", "/candidate") => {
            if let Ok(candidate) = body() {
                if !candidate.is_null() {
                    let _ = session.add_ice_candidate(candidate).await;
                }
            }
            (200, JSON, "{}".into())
        }
        ("GET", "/candidates") => (
            200,
            JSON,
            serde_json::Value::Array(session.take_local_candidates()).to_string(),
        ),
        _ => (404, "text/plain", "not here".into()),
    }
}

/// Accept connections and pass each request to the test's own loop, which
/// owns the session.
async fn serve(listener: TcpListener, requests: mpsc::Sender<Request>) {
    while let Ok((stream, _)) = listener.accept().await {
        tokio::spawn(answer_one(stream, requests.clone()));
    }
}

/// One HTTP/1.1 request and its response, then close: all the page needs.
async fn answer_one(mut stream: TcpStream, requests: mpsc::Sender<Request>) {
    let mut buffer = Vec::new();
    let mut chunk = [0_u8; 16 * 1024];

    let header_end = loop {
        let Ok(read) = stream.read(&mut chunk).await else {
            return;
        };
        if read == 0 {
            return;
        }
        buffer.extend_from_slice(&chunk[..read]);
        if let Some(end) = buffer.windows(4).position(|window| window == b"\r\n\r\n") {
            break end + 4;
        }
    };

    let head = String::from_utf8_lossy(&buffer[..header_end]).to_string();
    let mut lines = head.lines();
    let mut request_line = lines.next().unwrap_or_default().split_whitespace();
    let method = request_line.next().unwrap_or_default().to_owned();
    let path = request_line
        .next()
        .unwrap_or("/")
        .split('?')
        .next()
        .unwrap_or("/")
        .to_owned();
    let headers: HashMap<String, String> = lines
        .filter_map(|line| line.split_once(':'))
        .map(|(name, value)| (name.trim().to_ascii_lowercase(), value.trim().to_owned()))
        .collect();
    let length: usize = headers
        .get("content-length")
        .and_then(|value| value.parse().ok())
        .unwrap_or(0);

    while buffer.len() < header_end + length {
        let Ok(read) = stream.read(&mut chunk).await else {
            return;
        };
        if read == 0 {
            break;
        }
        buffer.extend_from_slice(&chunk[..read]);
    }

    let body = String::from_utf8_lossy(&buffer[header_end..]).to_string();
    let (reply, response) = oneshot::channel();

    let request = Request {
        method,
        path,
        body,
        reply,
    };
    if requests.send(request).await.is_err() {
        return;
    }

    let (status, content_type, text) =
        response
            .await
            .unwrap_or((500, "text/plain", "the test stopped".into()));
    let message = format!(
        "HTTP/1.1 {status} OK\r\nContent-Type: {content_type}\r\nContent-Length: {}\r\n\
         Cache-Control: no-store\r\nConnection: close\r\n\r\n{text}",
        text.len()
    );
    let _ = stream.write_all(message.as_bytes()).await;
    let _ = stream.shutdown().await;
}

/// Run the Playwright script on a thread of its own and hand back its exit
/// status and its `INTEROP_RESULT` line. Its progress goes straight to this
/// test's stderr.
fn spawn_script(
    url: &str,
    mode: Mode,
    (width, height): (u32, u32),
) -> oneshot::Receiver<(Option<i32>, serde_json::Value)> {
    let script = concat!(
        env!("CARGO_MANIFEST_DIR"),
        "/../../scripts/interop/chromium-h264.cjs"
    );
    let arguments = [
        script.to_owned(),
        "--url".into(),
        url.to_owned(),
        "--mode".into(),
        mode.flag().into(),
        "--width".into(),
        width.to_string(),
        "--height".into(),
        height.to_string(),
    ];

    let (done, finished) = oneshot::channel();

    std::thread::spawn(move || {
        let child = Command::new("node")
            .args(&arguments)
            .stdout(Stdio::piped())
            .stderr(Stdio::inherit())
            .spawn();

        let mut child = match child {
            Ok(child) => child,
            Err(error) => {
                let _ = done.send((
                    None,
                    serde_json::json!({ "failures": [format!("node: {error}")] }),
                ));
                return;
            }
        };

        let mut report = serde_json::json!({ "failures": ["the script printed no result"] });
        if let Some(stdout) = child.stdout.take() {
            for line in BufReader::new(stdout).lines().map_while(Result::ok) {
                match line.strip_prefix("INTEROP_RESULT ") {
                    Some(json) => report = serde_json::from_str(json).unwrap_or(report),
                    None => eprintln!("{line}"),
                }
            }
        }

        let status = child.wait().ok().and_then(|status| status.code());
        let _ = done.send((status, report));
    });

    finished
}

/// The synthetic screen: eight colour bars over the top three quarters, a
/// band of fine texture under them, and a white square that travels across
/// the bars — drawn once, with only the square redrawn per frame, so that
/// even a 3024×1964 source costs a copy rather than a render.
struct Pattern {
    width: u32,
    height: u32,
    stride: usize,
    background: Vec<u8>,
}

impl Pattern {
    fn new(width: u32, height: u32) -> Self {
        // Rows padded to 256 bytes, as Windows.Graphics.Capture and
        // ScreenCaptureKit both do, so the stride path is exercised.
        let stride = (width as usize * 4).div_ceil(256) * 256;
        let mut background = vec![0x55_u8; stride * height as usize];

        for y in 0..height {
            for x in 0..width {
                let bar = x * 8 / width;
                let on = |bit: u32| if bar & bit != 0 { 230 } else { 20 };
                let bgra = if y > height * 3 / 4 {
                    let grain = ((x * 31 + y * 17) ^ (x * y)) as u8;
                    [grain, grain.wrapping_mul(3), grain.wrapping_add(90), 255]
                } else {
                    [on(1), on(2), on(4), 255]
                };
                let offset = y as usize * stride + x as usize * 4;
                background[offset..offset + 4].copy_from_slice(&bgra);
            }
        }

        Self {
            width,
            height,
            stride,
            background,
        }
    }

    fn frame(&self, index: u64, at: Duration) -> Frame {
        let mut data = self.background.clone();
        let side = self.height / 6;
        let left = ((index * 16) % u64::from(self.width - side)) as u32;
        let top = self.height / 3;

        for y in top..top + side {
            let row = y as usize * self.stride;
            data[row + left as usize * 4..row + (left + side) as usize * 4].fill(255);
        }

        // Microseconds since the capture started, as the Mac capture stamps
        // them; the encoder only ever uses the differences.
        let timestamp_us = u64::try_from(at.as_micros()).unwrap_or(u64::MAX);

        Frame::new(
            self.width,
            self.height,
            self.stride,
            PixelFormat::Bgra8,
            timestamp_us,
            data,
        )
        .expect("the pattern is well formed")
    }
}
