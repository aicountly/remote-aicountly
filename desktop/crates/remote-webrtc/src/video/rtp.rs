//! Access units into RTP packets.
//!
//! The payloading is webrtc-rs's own [`H264Payloader`] — RFC 6184
//! non-interleaved mode: a NAL unit that fits goes as it is, a larger one as
//! FU-A fragments, and the SPS and PPS that precede an IDR as one STAP-A in
//! front of it. It splits its input on Annex-B start codes, which is the
//! framing OpenH264 writes, so an access unit goes in exactly as the encoder
//! produced it.
//!
//! # Why not `TrackLocalStaticSample`
//!
//! It wraps the same payloader, but its RTP timestamp is a running total of
//! sample *durations*, advanced after each sample — so a frame's timestamp is
//! fixed by the duration given for the frame before it, before anybody knows
//! when the next frame will come. With a capture that only delivers when the
//! screen changes, that is wrong in exactly the case that matters: after five
//! still seconds the next frame would be stamped one frame interval later,
//! and the browser's jitter buffer, seeing five seconds of arrival time for
//! 33 ms of media time, would decide the network had a five-second hiccup and
//! raise its playout delay. Here each packet's timestamp is the frame's own
//! media time on the 90 kHz clock, so the gaps the browser sees are the gaps
//! there were.

use bytes::Bytes;
use rtc::rtp::codec::h264::H264Payloader;
use rtc::rtp::header::Header;
use rtc::rtp::packet::Packet;
use rtc::rtp::packetizer::Payloader;
use rtc::rtp::sequence::{new_random_sequencer, Sequencer};

use super::VIDEO_CLOCK_RATE;

/// The largest RTP packet this sends, header included.
///
/// 1200 bytes is the figure webrtc-rs and libwebrtc both use: comfortably
/// under a 1280-byte IPv6 minimum MTU after SRTP's authentication tag, a TURN
/// channel header and the IP and UDP headers, so a packet is never fragmented
/// on the way — and a fragmented UDP packet is one that a single lost
/// fragment loses entirely.
const MAX_PACKET_BYTES: usize = 1200;

/// The fixed RTP header, without extensions or CSRCs.
const RTP_HEADER_BYTES: usize = 12;

/// Turns access units into the RTP packets of one stream.
pub(crate) struct H264Packetizer {
    payloader: H264Payloader,
    sequencer: Box<dyn Sequencer>,
    ssrc: u32,
    /// The RTP timestamp of media time zero. Random, as RFC 3550 asks, so a
    /// stream's timestamps say nothing about when it started.
    timestamp_base: u32,
}

impl H264Packetizer {
    pub(crate) fn new(ssrc: u32) -> Self {
        Self {
            payloader: H264Payloader::default(),
            sequencer: Box::new(new_random_sequencer()),
            ssrc,
            timestamp_base: rand_u32(),
        }
    }

    /// The RTP timestamp of a frame at `media_time_us`.
    pub(crate) fn rtp_timestamp(&self, media_time_us: u64) -> u32 {
        let ticks = media_time_us.saturating_mul(u64::from(VIDEO_CLOCK_RATE)) / 1_000_000;

        // RTP timestamps wrap; the receiver unwraps them. Truncation is the
        // wrap.
        self.timestamp_base.wrapping_add(ticks as u32)
    }

    /// The packets for one access unit: all with the frame's timestamp, the
    /// marker bit on the last, sequence numbers consecutive.
    pub(crate) fn packetize(
        &mut self,
        access_unit: Bytes,
        payload_type: u8,
        media_time_us: u64,
    ) -> Result<Vec<Packet>, String> {
        let payloads = self
            .payloader
            .payload(MAX_PACKET_BYTES - RTP_HEADER_BYTES, &access_unit)
            .map_err(|error| error.to_string())?;

        let timestamp = self.rtp_timestamp(media_time_us);
        let last = payloads.len().saturating_sub(1);

        Ok(payloads
            .into_iter()
            .enumerate()
            .map(|(index, payload)| Packet {
                header: Header {
                    version: 2,
                    marker: index == last,
                    payload_type,
                    sequence_number: self.sequencer.next_sequence_number(),
                    timestamp,
                    ssrc: self.ssrc,
                    ..Header::default()
                },
                payload,
            })
            .collect())
    }
}

/// A random `u32` from the standard library's per-process hash keys, which
/// are seeded from the operating system's generator.
///
/// Only the RTP timestamp offset uses it, and that needs to be unpredictable
/// rather than secret — SRTP is what keeps the stream private. This avoids
/// taking a dependency on `rand` for one number.
fn rand_u32() -> u32 {
    use std::collections::hash_map::RandomState;
    use std::hash::{BuildHasher, Hasher};

    let mut hasher = RandomState::new().build_hasher();
    hasher.write_u64(0x4149_434F_554E_544C);

    hasher.finish() as u32
}

#[cfg(test)]
mod tests {
    use rtc::rtp::codec::h264::H264Packet;
    use rtc::rtp::packetizer::Depacketizer;

    use super::*;
    use crate::video::annexb;

    fn idr_access_unit(idr_bytes: usize) -> Vec<u8> {
        let mut unit = vec![0, 0, 0, 1, 0x67, 0x42, 0xC0, 0x1F, 0xDA];
        unit.extend([0, 0, 0, 1, 0x68, 0xCE, 0x3C, 0x80]);
        unit.extend([0, 0, 0, 1, 0x65]);
        unit.extend((0..idr_bytes).map(|i| (i % 251) as u8 | 0x10));
        unit
    }

    /// An IDR bigger than a packet: STAP-A with the parameter sets, then
    /// FU-A fragments, every packet within the MTU, one timestamp, the
    /// marker on the last — and the depacketiser puts back exactly the NAL
    /// units that went in.
    #[test]
    fn a_large_idr_round_trips_through_the_payloader() {
        let mut packetizer = H264Packetizer::new(0x1234_5678);
        let unit = idr_access_unit(5_000);

        let packets = packetizer
            .packetize(Bytes::from(unit.clone()), 125, 1_000_000)
            .expect("packetizes");

        assert!(packets.len() >= 6, "{} packets", packets.len());
        assert_eq!(packets[0].payload[0] & 0x1F, 24, "STAP-A first");
        assert!(
            packets[1..]
                .iter()
                .all(|packet| packet.payload[0] & 0x1F == 28),
            "then FU-A"
        );

        let timestamp = packets[0].header.timestamp;
        for (index, packet) in packets.iter().enumerate() {
            assert!(RTP_HEADER_BYTES + packet.payload.len() <= MAX_PACKET_BYTES);
            assert_eq!(packet.header.timestamp, timestamp);
            assert_eq!(packet.header.payload_type, 125);
            assert_eq!(packet.header.ssrc, 0x1234_5678);
            assert_eq!(packet.header.marker, index == packets.len() - 1);
            assert_eq!(
                packet.header.sequence_number,
                packets[0].header.sequence_number.wrapping_add(index as u16)
            );
        }

        let mut depacketizer = H264Packet::default();
        let mut rebuilt = Vec::new();
        for packet in &packets {
            rebuilt.extend_from_slice(
                &depacketizer
                    .depacketize(&packet.payload)
                    .expect("depacketizes"),
            );
        }

        let sent: Vec<&[u8]> = annexb::nal_units(&unit).collect();
        let received: Vec<&[u8]> = annexb::nal_units(&rebuilt).collect();
        assert_eq!(sent, received);
        assert!(annexb::is_self_contained_keyframe(&rebuilt));
    }

    /// The RTP timestamp is the frame's media time at 90 kHz: 33 333 µs is
    /// 2 999 ticks, five still seconds are 450 000.
    #[test]
    fn timestamps_are_media_time_at_ninety_kilohertz() {
        let mut packetizer = H264Packetizer::new(1);
        let p_frame = Bytes::from_static(&[0, 0, 0, 1, 0x41, 0x9A, 0x22]);

        let first = packetizer.packetize(p_frame.clone(), 125, 0).unwrap();
        let second = packetizer.packetize(p_frame.clone(), 125, 33_333).unwrap();
        let later = packetizer.packetize(p_frame, 125, 5_033_333).unwrap();

        let base = first[0].header.timestamp;
        assert_eq!(second[0].header.timestamp.wrapping_sub(base), 2_999);
        assert_eq!(later[0].header.timestamp.wrapping_sub(base), 452_999);

        // One packet per small frame, each the end of its frame.
        assert!(first.len() == 1 && first[0].header.marker);
        assert_eq!(
            second[0].header.sequence_number,
            first[0].header.sequence_number.wrapping_add(1)
        );
    }

    /// Thirteen hours in, the timestamp wraps, as RTP's does.
    #[test]
    fn timestamps_wrap_rather_than_saturate() {
        let packetizer = H264Packetizer {
            timestamp_base: u32::MAX - 10,
            ..H264Packetizer::new(1)
        };

        assert_eq!(packetizer.rtp_timestamp(0), u32::MAX - 10);
        assert_eq!(packetizer.rtp_timestamp(1_000), 79);
    }
}
