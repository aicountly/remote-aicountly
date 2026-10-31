//! Reading an H.264 Annex-B byte stream.
//!
//! Annex-B is the framing OpenH264 writes and the framing webrtc-rs's H.264
//! payloader splits on: each NAL unit preceded by a `00 00 01` or
//! `00 00 00 01` start code. The encoder uses this to check what it produced
//! — that every IDR travels with its SPS and PPS — and the tests use it to
//! check what came out of the other end of a connection.

/// NAL unit type 1: a slice of a non-IDR picture — a P-frame here, since
/// Constrained Baseline has no B-frames.
pub const NAL_SLICE: u8 = 1;
/// NAL unit type 5: a slice of an IDR picture, from which a decoder can
/// start with no history at all.
pub const NAL_IDR: u8 = 5;
/// NAL unit type 6: supplemental enhancement information.
pub const NAL_SEI: u8 = 6;
/// NAL unit type 7: the sequence parameter set — profile, level, size.
pub const NAL_SPS: u8 = 7;
/// NAL unit type 8: the picture parameter set.
pub const NAL_PPS: u8 = 8;

/// The NAL units in an Annex-B stream, each without its start code.
///
/// Bytes before the first start code are not a NAL unit and are skipped, as
/// a decoder would skip them. A start code of any length is accepted, and the
/// trailing zero of a four-byte start code is not counted as part of the unit
/// before it.
pub fn nal_units(stream: &[u8]) -> impl Iterator<Item = &[u8]> {
    let mut rest = after_next_start_code(stream);

    std::iter::from_fn(move || {
        let current = rest?;

        let (unit, next) = match find_start_code(current) {
            Some((start, length)) => (&current[..start], Some(&current[start + length..])),
            None => (current, None),
        };

        rest = next;

        Some(unit)
    })
    .filter(|unit| !unit.is_empty())
}

/// The type of a NAL unit: the low five bits of its first byte.
#[must_use]
pub fn nal_type(unit: &[u8]) -> Option<u8> {
    unit.first().map(|header| header & 0x1F)
}

/// The types of every NAL unit in a stream, in order. For tests and logs.
#[must_use]
pub fn nal_types(stream: &[u8]) -> Vec<u8> {
    nal_units(stream).filter_map(nal_type).collect()
}

/// Whether an access unit can start a decoder: an IDR slice, preceded by the
/// SPS and PPS it refers to.
#[must_use]
pub fn is_self_contained_keyframe(stream: &[u8]) -> bool {
    let types = nal_types(stream);

    let position = |wanted: u8| types.iter().position(|&kind| kind == wanted);

    match (position(NAL_SPS), position(NAL_PPS), position(NAL_IDR)) {
        (Some(sps), Some(pps), Some(idr)) => sps < idr && pps < idr,
        _ => false,
    }
}

/// The bytes after the first start code, or `None` if there is none.
fn after_next_start_code(stream: &[u8]) -> Option<&[u8]> {
    find_start_code(stream).map(|(start, length)| &stream[start + length..])
}

/// Where the next start code begins, and how long it is: `00 00 01` is
/// three bytes, and every zero before it — the fourth byte of a four-byte
/// code, or trailing zeros of the unit before — belongs to it.
fn find_start_code(stream: &[u8]) -> Option<(usize, usize)> {
    let mut zeros = 0_usize;

    for (index, &byte) in stream.iter().enumerate() {
        match byte {
            0 => zeros += 1,
            1 if zeros >= 2 => {
                let start = index - zeros;

                return Some((start, zeros + 1));
            }
            _ => zeros = 0,
        }
    }

    None
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn units_are_split_on_both_start_code_lengths() {
        let stream = [
            0, 0, 0, 1, 0x67, 0xAA, // SPS, four-byte start code
            0, 0, 1, 0x68, 0xBB, // PPS, three-byte start code
            0, 0, 0, 1, 0x65, 0xCC, 0xDD, // IDR
        ];

        let units: Vec<&[u8]> = nal_units(&stream).collect();

        assert_eq!(
            units,
            vec![
                &[0x67, 0xAA][..],
                &[0x68, 0xBB][..],
                &[0x65, 0xCC, 0xDD][..]
            ]
        );
        assert_eq!(nal_types(&stream), vec![NAL_SPS, NAL_PPS, NAL_IDR]);
        assert!(is_self_contained_keyframe(&stream));
    }

    /// A decoder skips whatever precedes the first start code, and so does
    /// this — otherwise a stray byte would read as a NAL unit of some
    /// arbitrary type.
    #[test]
    fn bytes_before_the_first_start_code_are_not_a_unit() {
        let stream = [0x42, 0x42, 0, 0, 1, 0x41, 0x01];

        assert_eq!(nal_types(&stream), vec![NAL_SLICE]);
        assert_eq!(nal_types(&[0x65, 0x00]), Vec::<u8>::new());
    }

    #[test]
    fn an_idr_without_its_parameter_sets_cannot_start_a_decoder() {
        let idr_only = [0, 0, 0, 1, 0x65, 0x88];
        let parameter_sets_after = [0, 0, 0, 1, 0x65, 0x88, 0, 0, 1, 0x67, 1, 0, 0, 1, 0x68, 1];
        let p_frame = [0, 0, 0, 1, 0x41, 0x9A];

        assert!(!is_self_contained_keyframe(&idr_only));
        assert!(!is_self_contained_keyframe(&parameter_sets_after));
        assert!(!is_self_contained_keyframe(&p_frame));
    }
}
