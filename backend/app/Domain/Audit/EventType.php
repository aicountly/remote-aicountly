<?php

declare(strict_types=1);

namespace App\Domain\Audit;

/**
 * Every event Remote records (§27).
 *
 * Two rules govern what may be written alongside one of these:
 *   * never screen content, a password, a token or a TURN secret (§60);
 *   * never the body of a chat message — chat has its own storage with its own
 *     retention, and copying it into the audit trail would quietly turn the
 *     security log into a transcript.
 */
final class EventType
{
    public const SESSION_CREATED = 'SESSION_CREATED';

    public const INVITATION_CREATED = 'INVITATION_CREATED';
    public const INVITATION_REVOKED = 'INVITATION_REVOKED';
    public const INVITATION_REDEEMED = 'INVITATION_REDEEMED';

    public const PARTICIPANT_JOIN_REQUESTED = 'PARTICIPANT_JOIN_REQUESTED';
    public const PARTICIPANT_APPROVED       = 'PARTICIPANT_APPROVED';
    public const PARTICIPANT_DENIED         = 'PARTICIPANT_DENIED';
    public const PARTICIPANT_JOINED         = 'PARTICIPANT_JOINED';
    public const PARTICIPANT_LEFT           = 'PARTICIPANT_LEFT';

    public const SCREEN_SHARE_REQUESTED           = 'SCREEN_SHARE_REQUESTED';
    public const SCREEN_SHARE_PERMISSION_GRANTED  = 'SCREEN_SHARE_PERMISSION_GRANTED';
    public const SCREEN_SHARE_PERMISSION_DENIED   = 'SCREEN_SHARE_PERMISSION_DENIED';
    public const SCREEN_SHARE_STARTED             = 'SCREEN_SHARE_STARTED';
    public const SCREEN_SHARE_STOPPED             = 'SCREEN_SHARE_STOPPED';

    public const SURFACE_BROWSER_SELECTED = 'SURFACE_BROWSER_SELECTED';
    public const SURFACE_WINDOW_SELECTED  = 'SURFACE_WINDOW_SELECTED';
    public const SURFACE_MONITOR_SELECTED = 'SURFACE_MONITOR_SELECTED';

    public const POLICY_REJECTED = 'POLICY_REJECTED';

    public const MICROPHONE_STARTED = 'MICROPHONE_STARTED';
    public const MICROPHONE_STOPPED = 'MICROPHONE_STOPPED';

    public const CHAT_STARTED = 'CHAT_STARTED';

    // §27 names STARTED, COMPLETED and FAILED. OFFERED and DECLINED are added
    // because a declined transfer is not a failure, and an administrator asking
    // "what was offered from this machine?" needs the offer itself recorded —
    // a transfer nobody accepted would otherwise leave no trace at all.
    public const FILE_TRANSFER_OFFERED   = 'FILE_TRANSFER_OFFERED';
    public const FILE_TRANSFER_DECLINED  = 'FILE_TRANSFER_DECLINED';
    public const FILE_TRANSFER_STARTED   = 'FILE_TRANSFER_STARTED';
    public const FILE_TRANSFER_COMPLETED = 'FILE_TRANSFER_COMPLETED';
    public const FILE_TRANSFER_FAILED    = 'FILE_TRANSFER_FAILED';

    public const CONNECTION_INTERRUPTED = 'CONNECTION_INTERRUPTED';
    public const CONNECTION_RESTORED    = 'CONNECTION_RESTORED';

    public const SESSION_PAUSED  = 'SESSION_PAUSED';
    public const SESSION_RESUMED = 'SESSION_RESUMED';
    public const SESSION_ENDED   = 'SESSION_ENDED';
    public const SESSION_EXPIRED = 'SESSION_EXPIRED';

    public const RECORDING_STARTED = 'RECORDING_STARTED';
    public const RECORDING_STOPPED = 'RECORDING_STOPPED';

    public const COMPANY_CONTEXT_MISMATCH = 'COMPANY_CONTEXT_MISMATCH';

    public const SUPPORT_REQUESTED = 'SUPPORT_REQUESTED';
    public const SUPPORT_ACCEPTED  = 'SUPPORT_ACCEPTED';
    public const SUPPORT_DECLINED  = 'SUPPORT_DECLINED';
    public const SUPPORT_CANCELLED = 'SUPPORT_CANCELLED';

    public const POLICY_UPDATED     = 'POLICY_UPDATED';
    public const PERMISSION_UPDATED = 'PERMISSION_UPDATED';

    public const SIGNALLING_TOKEN_ISSUED = 'SIGNALLING_TOKEN_ISSUED';

    // --- Desktop agents (docs/DESKTOP_AGENT.md) ----------------------------
    //
    // The same two rules apply as everywhere else, and they bite harder here:
    // a clipboard event records *that* the clipboard was synchronised and its
    // byte count, never its contents; an input event is not recorded at all,
    // because a keystroke log is a password log.
    public const CONTROL_REQUESTED = 'CONTROL_REQUESTED';
    public const CONTROL_GRANTED   = 'CONTROL_GRANTED';
    public const CONTROL_DENIED    = 'CONTROL_DENIED';
    public const CONTROL_REVOKED   = 'CONTROL_REVOKED';

    public const CLIPBOARD_SYNCED = 'CLIPBOARD_SYNCED';

    public const DEVICE_ENROLLED = 'DEVICE_ENROLLED';
    public const DEVICE_REVOKED  = 'DEVICE_REVOKED';
    public const DEVICE_UPDATED  = 'DEVICE_UPDATED';

    /** A signature, a nonce or a revoked device that did not check out (§60). */
    public const DEVICE_AUTH_FAILED     = 'DEVICE_AUTH_FAILED';
    public const DEVICE_AUTHENTICATED   = 'DEVICE_AUTHENTICATED';

    public const UNATTENDED_ACCESS_ENABLED  = 'UNATTENDED_ACCESS_ENABLED';
    public const UNATTENDED_ACCESS_DISABLED = 'UNATTENDED_ACCESS_DISABLED';
    public const UNATTENDED_SESSION_STARTED = 'UNATTENDED_SESSION_STARTED';

    public const DEVICE_REBOOT_REQUESTED = 'DEVICE_REBOOT_REQUESTED';

    /**
     * Which sharing surface the browser reported, as an event name (§27).
     */
    public static function forSurface(string $displaySurface): string
    {
        return match ($displaySurface) {
            'browser' => self::SURFACE_BROWSER_SELECTED,
            'window'  => self::SURFACE_WINDOW_SELECTED,
            'monitor' => self::SURFACE_MONITOR_SELECTED,
            default   => self::SCREEN_SHARE_STARTED,
        };
    }
}
