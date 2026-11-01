# OpenH264: Cisco's binary, downloaded on the person's own computer

AICOUNTLY Remote streams a shared screen as H.264, the one video codec every
browser this product supports decodes in WebRTC. This page sets out how the
application obtains its H.264 encoder, why, and where in the code each
condition of the licence that makes the arrangement work is met. It is a
description of what the software does, written for whoever signs off a
release — not legal advice.

## The question

OpenH264's *source code* is BSD-2-Clause, which this product could ship. H.264
is also covered by patents, licensed through the AVC/H.264 patent pool (Via
Licensing Alliance, formerly MPEG LA), and the code licence does not answer
that.

Cisco pays the pool's royalties for **one thing only: Cisco's own prebuilt
OpenH264 binary**, and only when the software that uses it meets four
conditions. An OpenH264 that we compile from source and ship inside our
installer is not covered, whoever wrote its source. Until this change the
application did exactly that (the `openh264` crate's `source` feature).

## The arrangement

* **The application ships no H.264 encoder.** `openh264` is built with
  `default-features = false, features = ["libloading"]` (`desktop/Cargo.toml`),
  so none of OpenH264's C++ is compiled into it and nothing in the installer
  is, or contains, Cisco's binary.
* **The person's computer downloads Cisco's binary from Cisco** —
  `ciscobinary.openh264.org`, the file for its platform, OpenH264 2.6.0:

  | Platform | File |
  |---|---|
  | Windows x64 | `openh264-2.6.0-win64.dll` |
  | macOS, Apple silicon | `libopenh264-2.6.0-mac-arm64.dylib` |
  | macOS, Intel | `libopenh264-2.6.0-mac-x64.dylib` |
  | anything else | nothing — no build of the application ships for it (a developer's Linux workstation may fetch `libopenh264-2.6.0-linux64.8.so` to experiment) |

* **It is checked before it is ever loaded, and every time it is loaded**:
  its SHA-256 against the hash pinned in the application for that file, then
  against the `openh264` crate's own list of Cisco builds, through
  `OpenH264API::from_blob_path`. Nothing in the application calls
  `from_blob_path_unchecked`.
* **It is kept per user**, in a folder only that person (and the machine's
  administrators) can write to: `%LOCALAPPDATA%\AICOUNTLY\Remote\codecs\` on
  Windows, `~/Library/Application Support/AICOUNTLY/Remote/codecs/` on macOS.
* **The person can switch it off and on** in App settings, beside the sentence
  "OpenH264 Video Codec provided by Cisco Systems, Inc.", and the licence text
  is in About → Third-party licences.

Cisco serves these files over plain HTTP. The application tries HTTPS first
and falls back to HTTP, which is safe because the transport is not what makes
the file trustworthy: what arrives is decompressed and hashed, and anything
but the pinned binary is discarded before it is written under its real name.
Somebody on the network path can delay or stop the download; they cannot
change what is loaded.

## The four conditions, and where each is met

The conditions are quoted from Cisco's licence text, which the application
carries unmodified as `desktop/legal/OPENH264_BINARY_LICENSE.txt` (a byte-for-
byte copy of the text in `openh264-sys2` 0.9.9; a test fails if it changes).

### 1. "The Cisco-provided binary is separately downloaded to an end user's device, and not integrated into or combined with third party software prior to being downloaded to the end user's device"

| What | Where |
|---|---|
| No encoder in the application: `libloading` only, never `source` | `desktop/Cargo.toml` (`openh264`), `desktop/crates/remote-webrtc/Cargo.toml` |
| The from-source encoder is a test-only feature that only the crate's dev-dependencies enable | `bundled-openh264` in `remote-webrtc/Cargo.toml`; resolver 2 keeps dev-dependency features out of normal builds |
| The download, from Cisco's server, onto this computer | `CodecManager` and `CISCO_BASE_URLS` in `desktop/crates/remote-webrtc/src/video/cisco.rs` |
| The per-user folder it is kept in | `remote_core::codec_directory` (`desktop/crates/remote-core/src/config.rs`) |
| Started on the computer itself, a few seconds after the application starts | `commands::codec::start` (`desktop/src-tauri/src/commands/codec.rs`), called from `lib.rs` |

AICOUNTLY never hosts, mirrors or forwards the file. The installer does not
fetch it either: the application does, on the person's computer, for the
person's account.

### 2. "The end user must have the ability to control (e.g., to enable, disable, or re-enable) the use of the Cisco-provided binary"

| What | Where |
|---|---|
| The switch, in App settings → Video codec | `VideoCodecSettings` (`desktop/src/features/settings/VideoCodec.tsx`) |
| Saved as `openh264Enabled` (on by default; a configuration file written before it existed reads as on) | `AgentConfig::openh264_enabled` (`desktop/crates/remote-core/src/config.rs`) |
| Off: every encoder lets go of the library at its next frame, and the file is deleted. On: downloaded again | `set_codec_enabled` (`commands/codec.rs`) → `CodecManager::set_enabled` (`cisco.rs`); encoders follow `CodecSource::generation` (`video/codec.rs`) |
| Off survives a restart: nothing is downloaded, and a file left behind is deleted at start | `CodecManager::start` |
| Saving the rest of the settings form never flips it back | `save_configuration` keeps the saved value (`desktop/src-tauri/src/commands/mod.rs`) |

### 3. "Third party software, in the location where end users can control the use of the Cisco-provided binary, must display the following text: "OpenH264 Video Codec provided by Cisco Systems, Inc.""

| What | Where |
|---|---|
| The sentence is the switch's own label (its accessible name), so the two cannot be separated | `VideoCodec.tsx`, `OPENH264_ATTRIBUTION` in `desktop/src/types/agent.ts` |
| The same sentence on the Rust side, checked against Cisco's licence text | `ATTRIBUTION` in `cisco.rs`, and its test |
| Tested: the switch's name is exactly the sentence | `desktop/src/features/settings/SettingsPage.test.tsx` |

### 4. "Any third-party software that makes use of the Cisco-provided binary must reproduce all of the above text, as well as this last condition, in the EULA and/or in another location where licensing information is to be presented to the end user"

| What | Where |
|---|---|
| The whole text, unmodified, conditions included | `desktop/legal/OPENH264_BINARY_LICENSE.txt` (kept byte for byte by `desktop/legal/.gitattributes`) |
| Shown in full in About → Third-party licences | `ThirdPartyLicences` in `desktop/src/features/settings/SettingsPage.tsx`, imported with Vite's `?raw` (`licences.ts`) |
| Tested: reachable, and identical to the file | `SettingsPage.test.tsx`; the file's own SHA-256 in `cisco.rs`'s tests |

The Windows installer shows no licence page today. If one is added, or an
EULA is written, it must carry this text as well.

## What the person sees

| Situation | What happens |
|---|---|
| First start, codec on | A few seconds after the window opens the codec is downloaded in the background; App settings shows progress, then "Installed · OpenH264 2.6.0 · size" |
| Cisco's server unreachable, or the download fails its check | Each address is tried three times with a growing pause; then App settings says why ("Cisco's server could not be reached …", "did not match …") with **Retry**. Nothing that failed its check is kept |
| A session starts with no codec | The connection still works — chat, control and files — but no picture is sent. The session logs it once; the window shows a notice under the session banner naming the setting; and, if the codec is switched on, a fresh download starts by itself (at most once a minute) so the picture starts as soon as it arrives |
| Get help, codec not ready | A notice before any code is given: the helper would see no picture, and which setting fixes it |
| Switched off | Encoders stop using it at the next frame and the file is deleted. On Windows a DLL still held by an encoder is retried for a few seconds, then deleted at the next start |
| Switched on again | Downloaded again from Cisco |

## macOS: library validation

A hardened-runtime, notarised Mac application may load only libraries signed
by Apple or by its own team. Cisco's dylib is neither, and re-signing it would
make it no longer Cisco's binary as Cisco distributed it. The Mac build
therefore carries `com.apple.security.cs.disable-library-validation`
(`desktop/src-tauri/Entitlements.plist`, referenced from
`tauri.macos.conf.json`). The trade-off — library validation's general
guarantee exchanged for the hash check on the one library the application
loads, with the rest of the hardened runtime, `DYLD_*` restrictions included,
left on — is written out in `desktop/src-tauri/tests/macos_entitlements.rs`,
which also holds the file to that single entitlement.

## What this does not settle

* Cisco's licence covers the royalties *the pool* charges for Cisco's binary.
  Cisco's FAQ is explicit that it gives no guarantee about patents outside the
  pool.
* The patent notice in the licence text is about uses that receive
  remuneration for encoding video. Whether a paid remote-support product's use
  falls within it is a question for whoever signs off the release, with advice.
* Cisco publishes OpenH264 2.6.0's binaries today. A later release means new
  pinned hashes in `cisco.rs` and an `openh264` crate that knows them.
