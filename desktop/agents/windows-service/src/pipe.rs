//! The named pipe, and the ACL that decides who may speak on it.
//!
//! # Why a named pipe and not a localhost socket
//!
//! A TCP listener on `127.0.0.1` is reachable by **every** process on the
//! machine, including any that a browser can be made to talk to. It has no
//! notion of who is connecting, so authentication has to be invented on top —
//! and whatever secret that authentication uses has to be stored somewhere
//! every legitimate client can read, which is somewhere every illegitimate one
//! can read too.
//!
//! A named pipe has an ACL. Windows enforces it before a single byte is read,
//! and the identity of the connecting process is something the kernel knows
//! rather than something the protocol asks about.
//!
//! # The ACL
//!
//! ```text
//!   LocalSystem     full control     the service itself
//!   Administrators  read + write     the UI, when elevated
//!   Interactive     read + write     the UI in the signed-in user's session
//!   Everyone        (absent)         no entry at all
//! ```
//!
//! Written as an SDDL string ([`PipeSecurity::sddl`]) so it is one reviewable
//! line rather than a page of `AddAccessAllowedAce` calls where an omission is
//! invisible. `Interactive` — not `Users` — is deliberate: it is the group a
//! person who is actually signed in at this machine belongs to, and it
//! excludes a service account or a scheduled task running as a user nobody is
//! sitting in front of.
//!
//! The ACL is the first gate and not the only one: [`crate::IpcRequest::Hello`]
//! is exchanged before anything else happens, so a process that satisfies the
//! ACL still has to speak the protocol and agree a version.

/// The pipe's path, without the `\\.\pipe\` prefix.
pub const PIPE_BASENAME: &str = "AicountlyRemote.Agent";

/// The full pipe path Windows expects.
#[must_use]
pub fn pipe_name() -> String {
    format!(r"\\.\pipe\{PIPE_BASENAME}")
}

/// The security descriptor the service creates its pipe with.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default)]
pub struct PipeSecurity;

impl PipeSecurity {
    /// The SDDL for the pipe's DACL.
    ///
    /// ```text
    ///   D:                         a DACL follows
    ///   (A;;GA;;;SY)               Allow, Generic All,      LocalSystem
    ///   (A;;GA;;;BA)               Allow, Generic All,      Builtin Administrators
    ///   (A;;GRGW;;;IU)             Allow, Read + Write,     Interactive Users
    ///   S:(ML;;NWNRNX;;;LW)        Low-integrity processes may not write,
    ///                              read or execute — so a sandboxed browser
    ///                              or a low-IL process cannot reach the pipe
    ///                              even if it somehow satisfied the DACL.
    /// ```
    ///
    /// There is deliberately **no** entry for `Everyone` (`WD`), for
    /// `Anonymous` (`AN`), or for `Users` (`BU`). A pipe that any process can
    /// open is a pipe whose only protection is the protocol on top of it.
    #[must_use]
    pub fn sddl() -> &'static str {
        "D:(A;;GA;;;SY)(A;;GA;;;BA)(A;;GRGW;;;IU)S:(ML;;NWNRNX;;;LW)"
    }

    /// Whether an SDDL string would give a well-known unprivileged principal
    /// access.
    ///
    /// Used by the test below, and by the service at startup: a descriptor
    /// that somehow ended up permissive is refused rather than used, because a
    /// pipe with the wrong ACL fails open and nothing about its behaviour
    /// would look wrong.
    #[must_use]
    pub fn grants_world_access(sddl: &str) -> bool {
        let dacl = sddl.split("S:").next().unwrap_or(sddl);

        // `WD` Everyone, `AN` Anonymous, `BU` Builtin Users, `BG` Builtin Guests.
        ["(A;;", "WD)", "AN)", "BU)", "BG)"]
            .iter()
            .skip(1)
            .any(|principal| dacl.contains(principal))
    }
}

/// How many connections the service accepts at once.
///
/// One UI process per interactive session, plus headroom for a fast-user-switch
/// where two people are signed in. Not unbounded: an unbounded pipe server is a
/// resource-exhaustion primitive for anything that satisfies the ACL.
pub const MAX_PIPE_INSTANCES: u32 = 8;

/// How long a connected client may stay silent before it is dropped.
///
/// A UI that crashed leaves its end of the pipe open until Windows notices.
/// The heartbeat is what turns that into a closed instance rather than one of
/// eight slots held forever.
pub const IDLE_TIMEOUT_SECONDS: u64 = 120;

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_pipe_path_is_the_shape_windows_expects() {
        let name = pipe_name();

        assert!(name.starts_with(r"\\.\pipe\"));
        assert!(name.ends_with(PIPE_BASENAME));
        // A local pipe. `\\<host>\pipe\` would be reachable over SMB.
        assert!(!name.starts_with(r"\\?\"));
        assert!(name.starts_with(r"\\.\"));
    }

    /// A pipe any process can open is a pipe whose only protection is the
    /// protocol on top of it.
    #[test]
    fn the_acl_grants_nothing_to_everyone_or_to_anonymous() {
        let sddl = PipeSecurity::sddl();

        assert!(!PipeSecurity::grants_world_access(sddl));

        // The three that matter, spelled out so a future edit that drops one
        // fails here rather than in production.
        assert!(
            sddl.contains("(A;;GA;;;SY)"),
            "LocalSystem must have full control"
        );
        assert!(
            sddl.contains("(A;;GA;;;BA)"),
            "Administrators must have full control"
        );
        assert!(
            sddl.contains("(A;;GRGW;;;IU)"),
            "the interactive user must be able to speak"
        );

        // And the ones that must never appear.
        assert!(!sddl.contains(";WD)"), "Everyone must have no entry");
        assert!(!sddl.contains(";AN)"), "Anonymous must have no entry");
        assert!(
            !sddl.contains(";BU)"),
            "Users must have no entry — Interactive is narrower"
        );
    }

    /// A sandboxed browser runs at low integrity. The mandatory label keeps it
    /// out even if it somehow satisfied the DACL.
    #[test]
    fn low_integrity_processes_are_kept_out_by_the_mandatory_label() {
        let sddl = PipeSecurity::sddl();

        assert!(sddl.contains("S:(ML;;NWNRNX;;;LW)"));
    }

    #[test]
    fn the_permissive_descriptor_check_actually_catches_a_permissive_one() {
        assert!(PipeSecurity::grants_world_access(
            "D:(A;;GA;;;SY)(A;;GRGW;;;WD)"
        ));
        assert!(PipeSecurity::grants_world_access("D:(A;;GA;;;AN)"));
        assert!(PipeSecurity::grants_world_access("D:(A;;GRGW;;;BU)"));

        assert!(!PipeSecurity::grants_world_access(PipeSecurity::sddl()));
    }

    /// An unbounded pipe server is a resource-exhaustion primitive for
    /// anything that satisfies the ACL.
    ///
    /// These are constants, so the assertions are constant too — which is the
    /// point: they fail the build the moment somebody edits one of them past
    /// what the design intends, rather than at some later runtime.
    #[test]
    #[allow(clippy::assertions_on_constants)]
    fn the_number_of_connections_is_bounded() {
        assert!(MAX_PIPE_INSTANCES > 0);
        assert!(MAX_PIPE_INSTANCES <= 16);
        assert!(IDLE_TIMEOUT_SECONDS > 0);
    }
}
