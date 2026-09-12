//! The signalling socket, as the agent speaks it.
//!
//! **The same relay the browser uses**, the same two-minute token from the same
//! API, the same room, and the same five message types. There is no second
//! signalling service, no second protocol and nothing here the browser does
//! not also send — which is the point: a desktop agent that invented its own
//! handshake would be a second product wearing the first one's name.
//!
//! ```text
//!   agent                          relay                        browser
//!   ─────                          ─────                        ───────
//!   connect ?token=…      ─────▶   verify (HMAC, room, expiry)
//!                         ◀─────   joined { peers }
//!                         ◀─────   peer-joined { peer }
//!   offer  { to, payload} ─────▶   relayed, in-room only  ─────▶
//!                         ◀─────                          ◀────  answer
//!   ice-candidate         ◀────▶                          ◀───▶  ice-candidate
//! ```
//!
//! # What this deliberately does not do
//!
//! * **It does not decide anything.** The room is inside the signed token, so
//!   there is no room parameter to get wrong and no way to ask for another
//!   machine's. Authorisation happened in the API before the token existed.
//! * **It carries no media and no control input.** SDP and ICE only. Input
//!   travels on the WebRTC data channel, peer to peer, and never through a
//!   server.
//! * **It does not reconnect on its own.** A signalling token lasts two
//!   minutes; a socket that outlived it would be a connection outliving its
//!   authorisation. Reconnecting means asking the API for a new token, which
//!   is the session runtime's job and not this file's.

use std::time::Duration;

use futures_util::{SinkExt, StreamExt};
use serde::{Deserialize, Serialize};
use tokio_tungstenite::tungstenite::protocol::Message;

/// How long to wait for the socket to open.
const CONNECT_TIMEOUT: Duration = Duration::from_secs(15);

/// The largest frame this will read.
///
/// SDP at worst. The relay applies its own 256 KiB ceiling; this one exists so
/// a relay that stopped applying it could not make the agent allocate.
const MAX_MESSAGE_BYTES: usize = 256 * 1024;

/// A peer in the room, as the relay describes it.
#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct Peer {
    /// The participant's public identifier.
    pub participant_uuid: String,
    /// `SHARER`, `VIEWER`, `SUPPORT_TECHNICIAN`, …
    #[serde(default)]
    pub role: String,
    /// What to show beside the pointer.
    #[serde(default)]
    pub display_name: String,
    /// What the peer says it can do. **An upper bound, never a grant.**
    #[serde(default)]
    pub capabilities: serde_json::Value,
}

/// One message from the relay.
///
/// `#[serde(tag = "type")]` with a catch-all, so a relay that grows a message
/// type this build does not know produces [`Signal::Unknown`] rather than a
/// parse failure that drops the connection.
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
#[serde(tag = "type", rename_all = "kebab-case")]
pub enum Signal {
    /// We are in. Everyone already here is listed.
    Joined {
        /// Our own participant uuid, echoed back.
        #[serde(default, rename = "participantUuid")]
        participant_uuid: String,
        /// Who was already in the room.
        #[serde(default)]
        peers: Vec<Peer>,
    },

    /// Somebody arrived. **We were here first, so we make the offer.**
    PeerJoined {
        /// Who sent it.
        #[serde(default)]
        from: String,
        /// Who arrived.
        peer: Peer,
    },

    /// Somebody left.
    PeerLeft {
        /// Who.
        #[serde(default)]
        from: String,
    },

    /// An SDP offer.
    Offer {
        /// Who from.
        from: String,
        /// The offer.
        payload: serde_json::Value,
    },

    /// An SDP answer.
    Answer {
        /// Who from.
        from: String,
        /// The answer.
        payload: serde_json::Value,
    },

    /// A trickled ICE candidate.
    IceCandidate {
        /// Who from.
        from: String,
        /// The candidate.
        payload: serde_json::Value,
    },

    /// A peer asking for renegotiation.
    Renegotiate {
        /// Who from.
        from: String,
    },

    /// The session ended.
    SessionEnded {
        /// Who from.
        #[serde(default)]
        from: String,
    },

    /// The peer named in a directed message is not in the room.
    PeerUnavailable {
        /// Who was named.
        #[serde(default)]
        to: String,
    },

    /// The relay refused something.
    Error {
        /// A machine code.
        #[serde(default)]
        code: String,
        /// A sentence.
        #[serde(default)]
        message: String,
    },

    /// The heartbeat's answer.
    Pong,

    /// Something this build does not know about. Ignored rather than fatal.
    #[serde(other)]
    Unknown,
}

/// One message to the relay.
#[derive(Debug, Clone, PartialEq, Serialize)]
#[serde(tag = "type", rename_all = "kebab-case")]
pub enum Outbound {
    /// Send an offer to one peer.
    Offer {
        /// Which peer.
        to: String,
        /// The offer.
        payload: serde_json::Value,
    },
    /// Answer one peer's offer.
    Answer {
        /// Which peer.
        to: String,
        /// The answer.
        payload: serde_json::Value,
    },
    /// Trickle a candidate to one peer.
    IceCandidate {
        /// Which peer.
        to: String,
        /// The candidate.
        payload: serde_json::Value,
    },
    /// Tell the room this session has ended.
    SessionEnded,
    /// Keep the socket honest.
    Ping,
}

/// Why the socket failed.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum SignallingError {
    /// The URL is not one this will connect to.
    #[error("the signalling address is not usable: {0}")]
    Address(String),

    /// The socket could not be opened, or dropped.
    #[error("the signalling connection failed: {0}")]
    Transport(String),

    /// The relay closed it, with its own code.
    #[error("the signalling connection was closed: {0}")]
    Closed(String),

    /// A frame that was not a signalling message.
    #[error("the signalling message could not be read")]
    Malformed,

    /// Larger than any legitimate signalling message.
    #[error("a signalling message of {0} bytes was refused")]
    TooLarge(usize),
}

/// Whether an address is one the agent will open a signalling socket to.
///
/// `wss://` only, outside a debug build. A signalling socket carries the SDP
/// that sets up an encrypted media path; over `ws://` an observer can rewrite
/// the fingerprint in it and become the other end of the session.
pub fn is_permitted_endpoint(url: &str) -> bool {
    if url.starts_with("wss://") {
        return true;
    }

    #[cfg(debug_assertions)]
    if url.starts_with("ws://localhost") || url.starts_with("ws://127.0.0.1") {
        return true;
    }

    false
}

/// Put the token on the URL the way the relay reads it.
///
/// A query parameter rather than a header, because that is what a browser's
/// `WebSocket` can do and the agent uses the same endpoint the browser does.
/// The token is short-lived, single-room and single-participant, which is what
/// makes a URL an acceptable place for it.
pub fn connect_url(base: &str, token: &str) -> String {
    let separator = if base.contains('?') { '&' } else { '?' };

    format!("{base}{separator}token={}", urlencode(token))
}

/// Percent-encode everything that is not unreserved.
///
/// A tiny encoder rather than a dependency: the input is a token this API
/// issued, the alphabet is known, and a whole URL crate to escape one value
/// would be a whole URL crate in a signed binary.
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

/// An open signalling socket.
pub struct SignallingSocket {
    stream: tokio_tungstenite::WebSocketStream<
        tokio_tungstenite::MaybeTlsStream<tokio::net::TcpStream>,
    >,
}

impl SignallingSocket {
    /// Open a socket to one room, with one token.
    ///
    /// The room is inside the token. There is no room parameter here, so there
    /// is nothing for a caller to get wrong and no way to ask for another
    /// machine's.
    pub async fn connect(url: &str, token: &str) -> Result<Self, SignallingError> {
        if !is_permitted_endpoint(url) {
            return Err(SignallingError::Address(
                "a signalling connection must use wss".into(),
            ));
        }

        let target = connect_url(url, token);

        let (stream, _) =
            tokio::time::timeout(CONNECT_TIMEOUT, tokio_tungstenite::connect_async(target))
                .await
                .map_err(|_| SignallingError::Transport("the relay did not answer".into()))?
                .map_err(|error| SignallingError::Transport(error.to_string()))?;

        Ok(Self { stream })
    }

    /// Send one message.
    pub async fn send(&mut self, message: &Outbound) -> Result<(), SignallingError> {
        let text = serde_json::to_string(message).map_err(|_| SignallingError::Malformed)?;

        self.stream
            .send(Message::Text(text.into()))
            .await
            .map_err(|error| SignallingError::Transport(error.to_string()))
    }

    /// Wait for the next message.
    ///
    /// `Ok(None)` means the relay closed the socket cleanly — an expired token,
    /// the session ending, or a newer connection replacing this one. All three
    /// are ordinary, and none is an error to report to a person.
    pub async fn next(&mut self) -> Result<Option<Signal>, SignallingError> {
        loop {
            let Some(frame) = self.stream.next().await else {
                return Ok(None);
            };

            let frame = frame.map_err(|error| SignallingError::Transport(error.to_string()))?;

            match frame {
                Message::Text(text) => {
                    if text.len() > MAX_MESSAGE_BYTES {
                        return Err(SignallingError::TooLarge(text.len()));
                    }

                    return Ok(Some(parse(&text)?));
                }

                // The relay speaks JSON. A binary frame is not a message it
                // sends, so it is not one this reads.
                Message::Binary(_) => return Err(SignallingError::Malformed),

                Message::Close(frame) => {
                    return Ok(Some(Signal::Error {
                        code: frame
                            .as_ref()
                            .map(|frame| frame.code.to_string())
                            .unwrap_or_else(|| "CLOSED".into()),
                        message: frame
                            .map(|frame| frame.reason.to_string())
                            .unwrap_or_default(),
                    }));
                }

                // Ping/Pong/Frame: tungstenite answers pings itself.
                _ => continue,
            }
        }
    }

    /// Close the socket.
    pub async fn close(mut self) {
        let _ = self.stream.close(None).await;
    }
}

/// Parse one relay message.
pub fn parse(text: &str) -> Result<Signal, SignallingError> {
    if text.len() > MAX_MESSAGE_BYTES {
        return Err(SignallingError::TooLarge(text.len()));
    }

    serde_json::from_str(text).map_err(|_| SignallingError::Malformed)
}

#[cfg(test)]
mod tests {
    use super::*;

    /// A signalling socket carries the SDP that sets up the encrypted media
    /// path. Over `ws://` an observer can rewrite the fingerprint in it.
    #[test]
    fn only_wss_is_permitted_outside_a_debug_build() {
        assert!(is_permitted_endpoint("wss://remote.aicountly.com/signal"));
        assert!(!is_permitted_endpoint("ws://remote.aicountly.com/signal"));
        assert!(!is_permitted_endpoint("http://remote.aicountly.com/signal"));
        assert!(!is_permitted_endpoint("remote.aicountly.com/signal"));
        assert!(!is_permitted_endpoint(""));

        // The localhost exception exists only in a debug build, and is
        // compiled out rather than being a flag somebody could set.
        assert_eq!(
            is_permitted_endpoint("ws://localhost:8787/signal"),
            cfg!(debug_assertions)
        );
    }

    #[test]
    fn the_token_goes_on_the_url_the_way_the_relay_reads_it() {
        assert_eq!(
            connect_url("wss://example.test/signal", "abc.def"),
            "wss://example.test/signal?token=abc.def"
        );

        assert_eq!(
            connect_url("wss://example.test/signal?x=1", "abc"),
            "wss://example.test/signal?x=1&token=abc"
        );
    }

    /// A token is base64url plus dots; anything else in one would still have
    /// to survive being put in a URL.
    #[test]
    fn a_token_is_escaped_rather_than_pasted() {
        assert_eq!(
            connect_url("wss://example.test/s", "a b&c=d#e"),
            "wss://example.test/s?token=a%20b%26c%3Dd%23e"
        );
    }

    #[test]
    fn the_relays_messages_parse() {
        let joined = parse(
            r#"{"type":"joined","participantUuid":"me","peers":[
                 {"participantUuid":"them","role":"VIEWER","displayName":"Sam",
                  "capabilities":{"remote_control":false}}]}"#,
        )
        .expect("parses");

        match joined {
            Signal::Joined {
                participant_uuid,
                peers,
            } => {
                assert_eq!(participant_uuid, "me");
                assert_eq!(peers.len(), 1);
                assert_eq!(peers[0].participant_uuid, "them");
                assert_eq!(peers[0].display_name, "Sam");
            }
            other => panic!("expected joined, got {other:?}"),
        }

        assert!(matches!(
            parse(r#"{"type":"offer","from":"them","payload":{"sdp":"v=0"}}"#).unwrap(),
            Signal::Offer { .. }
        ));
        assert!(matches!(
            parse(r#"{"type":"ice-candidate","from":"them","payload":{"candidate":"x"}}"#).unwrap(),
            Signal::IceCandidate { .. }
        ));
        assert!(matches!(
            parse(r#"{"type":"peer-left","from":"them"}"#).unwrap(),
            Signal::PeerLeft { .. }
        ));
        assert!(matches!(
            parse(r#"{"type":"session-ended","from":"them"}"#).unwrap(),
            Signal::SessionEnded { .. }
        ));
        assert!(matches!(parse(r#"{"type":"pong"}"#).unwrap(), Signal::Pong));
    }

    /// A relay that grows a message type must not drop the agent's connection.
    #[test]
    fn an_unknown_message_is_ignored_rather_than_fatal() {
        assert_eq!(
            parse(r#"{"type":"something-new","payload":{}}"#).unwrap(),
            Signal::Unknown
        );
    }

    #[test]
    fn a_frame_that_is_not_a_message_is_refused() {
        assert_eq!(parse("not json"), Err(SignallingError::Malformed));
        assert_eq!(parse("[]"), Err(SignallingError::Malformed));
        assert_eq!(parse(""), Err(SignallingError::Malformed));
    }

    /// The relay applies its own ceiling; this one exists so a relay that
    /// stopped applying it could not make the agent allocate.
    #[test]
    fn an_oversized_message_is_refused_by_size_alone() {
        let huge = format!(
            r#"{{"type":"offer","from":"x","payload":"{}"}}"#,
            "a".repeat(MAX_MESSAGE_BYTES)
        );

        assert!(matches!(parse(&huge), Err(SignallingError::TooLarge(_))));
    }

    #[test]
    fn what_the_agent_sends_is_shaped_the_way_the_relay_routes_it() {
        let offer = serde_json::to_value(Outbound::Offer {
            to: "them".into(),
            payload: serde_json::json!({ "type": "offer", "sdp": "v=0" }),
        })
        .unwrap();

        assert_eq!(offer["type"], "offer");
        assert_eq!(offer["to"], "them");
        assert_eq!(offer["payload"]["sdp"], "v=0");

        let candidate = serde_json::to_value(Outbound::IceCandidate {
            to: "them".into(),
            payload: serde_json::json!({ "candidate": "x" }),
        })
        .unwrap();

        // kebab-case, because that is what the relay's RELAYABLE set holds.
        assert_eq!(candidate["type"], "ice-candidate");
    }

    /// Nothing the agent sends carries input, a frame, or a credential — those
    /// travel peer to peer or not at all.
    #[test]
    fn the_signalling_protocol_carries_only_the_handshake() {
        for message in [
            Outbound::Offer {
                to: "a".into(),
                payload: serde_json::json!({}),
            },
            Outbound::Answer {
                to: "a".into(),
                payload: serde_json::json!({}),
            },
            Outbound::IceCandidate {
                to: "a".into(),
                payload: serde_json::json!({}),
            },
            Outbound::SessionEnded,
            Outbound::Ping,
        ] {
            let json = serde_json::to_string(&message).unwrap().to_lowercase();

            for forbidden in [
                "token",
                "secret",
                "credential",
                "frame",
                "keystroke",
                "clipboard",
            ] {
                assert!(
                    !json.contains(forbidden),
                    "{message:?} must not carry {forbidden}"
                );
            }
        }
    }
}
