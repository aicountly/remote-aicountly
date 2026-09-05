//! What this agent declares it can do, and what the machine will actually let
//! it do.
//!
//! Two different things, deliberately kept apart:
//!
//! * [`AgentCapabilities`] is the **declaration** sent at enrolment. It says
//!   what the software is capable of on this platform. It is an upper bound
//!   and never a grant — the server intersects it with the organisation's
//!   policy, so editing it on the machine achieves nothing.
//! * [`PermissionSummary`] is what the **operating system** currently permits:
//!   is the service running, has macOS granted Screen Recording. The agent's
//!   Permissions panel renders from this, and it is why a capability can read
//!   "Ready" or "Needs attention" independently of what policy says.
//!
//! Conflating them is how a product ends up claiming a capability that the
//! machine will refuse the first time somebody tries to use it.

use serde::{Deserialize, Serialize};

/// The capability declaration sent to `POST /devices/enrol`.
///
/// The field names are the API's, so this serialises straight into the
/// enrolment body and into `remote_devices.capabilities`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
pub struct AgentCapabilities {
    /// Can share this machine's screen.
    pub screen_share: bool,
    /// Can display somebody else's shared screen.
    pub screen_view: bool,
    /// Can be controlled with keyboard and mouse.
    pub remote_control: bool,
    /// Can be reached with nobody at the machine.
    pub unattended_access: bool,
    /// Can send and receive files peer-to-peer.
    pub file_transfer: bool,
    /// Can synchronise the text clipboard.
    pub clipboard_sync: bool,
    /// Can be restarted remotely.
    pub reboot: bool,
}

impl AgentCapabilities {
    /// What the Windows agent can do.
    ///
    /// Every one of these is implemented. A capability listed here that the
    /// software could not actually perform would be a lie the server has no
    /// way to detect — the intersection only ever removes capabilities.
    #[must_use]
    pub fn windows() -> Self {
        Self {
            screen_share: true,
            screen_view: true,
            remote_control: true,
            unattended_access: true,
            file_transfer: true,
            clipboard_sync: true,
            reboot: true,
        }
    }

    /// What a platform with no implementation can do: nothing.
    ///
    /// This is what macOS declares until its platform layer is written. An
    /// agent that declared the Windows set on macOS would be enrolled as a
    /// device an administrator believes they can control, and could not.
    #[must_use]
    pub fn none() -> Self {
        Self {
            screen_share: false,
            screen_view: false,
            remote_control: false,
            unattended_access: false,
            file_transfer: false,
            clipboard_sync: false,
            reboot: false,
        }
    }

    /// The capabilities for the platform this binary was built for.
    #[must_use]
    pub fn for_current_platform() -> Self {
        #[cfg(target_os = "windows")]
        {
            Self::windows()
        }

        #[cfg(not(target_os = "windows"))]
        {
            // Including Linux, where this crate compiles for CI but the agent
            // is not a supported product.
            Self::none()
        }
    }

    /// The declaration ∧ what the server said the organisation permits.
    ///
    /// The agent computes this only to render its own UI honestly. The
    /// authoritative intersection happens on the server, and this must agree
    /// with it — if the two ever disagree, the server wins and the agent's
    /// button was wrong.
    #[must_use]
    pub fn intersect(self, allowed: Self) -> Self {
        Self {
            screen_share: self.screen_share && allowed.screen_share,
            screen_view: self.screen_view && allowed.screen_view,
            remote_control: self.remote_control && allowed.remote_control,
            unattended_access: self.unattended_access && allowed.unattended_access,
            file_transfer: self.file_transfer && allowed.file_transfer,
            clipboard_sync: self.clipboard_sync && allowed.clipboard_sync,
            reboot: self.reboot && allowed.reboot,
        }
    }
}

/// Where one native prerequisite stands.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum PermissionState {
    /// Granted, present, running. Nothing to do.
    Ready,
    /// The person has to grant or install something.
    NeedsAttention,
    /// The platform refused, and asking again will not help.
    Denied,
    /// Not applicable on this platform.
    ///
    /// Windows has no Screen Recording permission to grant, so showing one
    /// would be inventing a step nobody can take.
    NotApplicable,
    /// The platform has no implementation of this yet.
    Unsupported,
}

impl PermissionState {
    /// Whether the capability behind this can be used right now.
    #[must_use]
    pub fn is_usable(self) -> bool {
        matches!(self, Self::Ready | Self::NotApplicable)
    }
}

/// Every native prerequisite, as the Permissions panel shows them.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
pub struct PermissionSummary {
    /// Can the agent capture the screen.
    pub screen_capture: PermissionState,
    /// Can the agent inject keyboard and mouse input.
    pub input_injection: PermissionState,
    /// Can the agent read and write the clipboard.
    pub clipboard: PermissionState,
    /// Is the background service installed and running.
    pub background_service: PermissionState,
    /// Can this process restart the machine.
    pub power: PermissionState,
}

impl PermissionSummary {
    /// Everything ready — the normal state of a working Windows install.
    #[must_use]
    pub fn all_ready() -> Self {
        Self {
            screen_capture: PermissionState::Ready,
            input_injection: PermissionState::Ready,
            clipboard: PermissionState::Ready,
            background_service: PermissionState::Ready,
            power: PermissionState::Ready,
        }
    }

    /// Nothing implemented — what an unsupported platform reports.
    #[must_use]
    pub fn all_unsupported() -> Self {
        Self {
            screen_capture: PermissionState::Unsupported,
            input_injection: PermissionState::Unsupported,
            clipboard: PermissionState::Unsupported,
            background_service: PermissionState::Unsupported,
            power: PermissionState::Unsupported,
        }
    }

    /// Whether the agent can host a session at all.
    ///
    /// Screen capture is the floor: without it there is nothing to share, and
    /// offering to start a session would waste somebody's time.
    #[must_use]
    pub fn can_host_session(&self) -> bool {
        self.screen_capture.is_usable()
    }

    /// Whether the agent can be controlled right now.
    #[must_use]
    pub fn can_be_controlled(&self) -> bool {
        self.screen_capture.is_usable() && self.input_injection.is_usable()
    }

    /// What the declared capabilities become, given what the machine permits.
    ///
    /// This is what stops the agent from declaring `remote_control` on a
    /// machine where input injection is not available — the enrolment would be
    /// a device an administrator believes they can control and cannot.
    #[must_use]
    pub fn constrain(&self, capabilities: AgentCapabilities) -> AgentCapabilities {
        AgentCapabilities {
            screen_share: capabilities.screen_share && self.screen_capture.is_usable(),
            screen_view: capabilities.screen_view,
            remote_control: capabilities.remote_control && self.can_be_controlled(),
            unattended_access: capabilities.unattended_access
                && self.can_be_controlled()
                && self.background_service.is_usable(),
            file_transfer: capabilities.file_transfer,
            clipboard_sync: capabilities.clipboard_sync && self.clipboard.is_usable(),
            reboot: capabilities.reboot && self.power.is_usable(),
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_windows_agent_declares_what_it_actually_implements() {
        let windows = AgentCapabilities::windows();

        assert!(windows.remote_control);
        assert!(windows.unattended_access);
        assert!(windows.clipboard_sync);
        assert!(windows.reboot);
    }

    /// A platform with no implementation declares nothing, rather than
    /// enrolling as a device an administrator believes they can control.
    #[test]
    fn an_unimplemented_platform_declares_nothing() {
        let none = AgentCapabilities::none();

        assert!(!none.screen_share);
        assert!(!none.remote_control);
        assert!(!none.unattended_access);
    }

    /// The intersection only ever removes. It is what makes editing the
    /// declaration on the machine pointless.
    #[test]
    fn the_intersection_only_ever_removes() {
        let declared = AgentCapabilities::windows();
        let allowed = AgentCapabilities {
            remote_control: false,
            unattended_access: false,
            clipboard_sync: false,
            reboot: false,
            ..AgentCapabilities::windows()
        };

        let effective = declared.intersect(allowed);

        assert!(!effective.remote_control);
        assert!(!effective.unattended_access);
        assert!(effective.screen_share);

        // And a policy cannot conjure a capability the agent does not have.
        assert!(
            !AgentCapabilities::none()
                .intersect(AgentCapabilities::windows())
                .remote_control
        );
    }

    /// A machine where input injection is unavailable must not declare that
    /// it can be controlled.
    #[test]
    fn the_machines_own_permissions_constrain_the_declaration() {
        let summary = PermissionSummary {
            input_injection: PermissionState::NeedsAttention,
            ..PermissionSummary::all_ready()
        };

        let constrained = summary.constrain(AgentCapabilities::windows());

        assert!(!constrained.remote_control);
        assert!(!constrained.unattended_access);
        assert!(constrained.screen_share);
        assert!(!summary.can_be_controlled());
        assert!(summary.can_host_session());
    }

    /// Unattended access additionally needs the background service: without
    /// it, the machine is not reachable when nobody is signed in.
    #[test]
    fn unattended_access_additionally_needs_the_background_service() {
        let summary = PermissionSummary {
            background_service: PermissionState::NeedsAttention,
            ..PermissionSummary::all_ready()
        };

        let constrained = summary.constrain(AgentCapabilities::windows());

        assert!(constrained.remote_control);
        assert!(!constrained.unattended_access);
    }

    /// Windows has no Screen Recording permission to grant. Showing one would
    /// invent a step nobody can take.
    #[test]
    fn not_applicable_counts_as_usable() {
        assert!(PermissionState::NotApplicable.is_usable());
        assert!(PermissionState::Ready.is_usable());
        assert!(!PermissionState::NeedsAttention.is_usable());
        assert!(!PermissionState::Denied.is_usable());
        assert!(!PermissionState::Unsupported.is_usable());
    }

    #[test]
    fn an_unsupported_platform_can_host_nothing() {
        let summary = PermissionSummary::all_unsupported();

        assert!(!summary.can_host_session());
        assert!(!summary.can_be_controlled());
        assert_eq!(
            summary.constrain(AgentCapabilities::windows()),
            AgentCapabilities {
                screen_view: true,
                file_transfer: true,
                ..AgentCapabilities::none()
            }
        );
    }

    /// The declaration serialises with the API's own field names, so it goes
    /// straight into the enrolment body.
    #[test]
    fn the_declaration_uses_the_apis_field_names() {
        let json = serde_json::to_string(&AgentCapabilities::windows()).unwrap();

        for field in [
            "screen_share",
            "screen_view",
            "remote_control",
            "unattended_access",
            "file_transfer",
            "clipboard_sync",
            "reboot",
        ] {
            assert!(json.contains(field), "missing {field}");
        }
    }
}
