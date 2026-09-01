<?php

declare(strict_types=1);

namespace App\Domain\Policy;

/**
 * The `remote.*` permission namespace (§10).
 *
 * Kept in one place so a typo in a controller is a fatal error rather than a
 * silently-ungated endpoint: every permission check goes through a constant
 * here, and {@see self::all()} is what the administration UI is built from.
 */
final class PermissionCatalog
{
    public const ACCESS = 'remote.access';

    public const SESSION_CREATE = 'remote.session.create';
    public const SESSION_JOIN   = 'remote.session.join';
    public const SESSION_END    = 'remote.session.end';

    public const SCREEN_SHARE = 'remote.screen.share';
    public const SCREEN_VIEW  = 'remote.screen.view';

    public const SAFE_SHARE = 'remote.safe_share';

    public const BROWSER_TAB_SHARE = 'remote.browser_tab.share';
    public const WINDOW_SHARE      = 'remote.window.share';
    public const MONITOR_SHARE     = 'remote.monitor.share';

    public const MICROPHONE_SHARE   = 'remote.microphone.share';
    public const SYSTEM_AUDIO_SHARE = 'remote.system_audio.share';

    public const CHAT_USE       = 'remote.chat.use';
    public const ANNOTATION_USE = 'remote.annotation.use';
    public const FILE_SEND      = 'remote.file.send';
    public const FILE_RECEIVE   = 'remote.file.receive';

    public const SUPPORT_REQUEST = 'remote.support.request';
    public const SUPPORT_ACCEPT  = 'remote.support.accept';

    public const EXTERNAL_INVITE = 'remote.external.invite';

    public const RECORDING_START = 'remote.recording.start';
    public const RECORDING_VIEW  = 'remote.recording.view';

    public const SESSION_HISTORY_OWN     = 'remote.session.history.own';
    public const SESSION_HISTORY_COMPANY = 'remote.session.history.company';

    public const AUDIT_VIEW = 'remote.audit.view';

    public const POLICY_VIEW   = 'remote.policy.view';
    public const POLICY_MANAGE = 'remote.policy.manage';

    /**
     * Grouped for the administration UI, and the source of truth for what a
     * permission name may be. Anything not listed here is rejected on write.
     *
     * @return array<string, list<string>>
     */
    public static function groups(): array
    {
        return [
            'Access' => [
                self::ACCESS,
                self::SESSION_CREATE,
                self::SESSION_JOIN,
                self::SESSION_END,
            ],
            'Sharing' => [
                self::SCREEN_SHARE,
                self::SCREEN_VIEW,
                self::SAFE_SHARE,
                self::BROWSER_TAB_SHARE,
                self::WINDOW_SHARE,
                self::MONITOR_SHARE,
                self::MICROPHONE_SHARE,
                self::SYSTEM_AUDIO_SHARE,
            ],
            'Collaboration' => [
                self::CHAT_USE,
                self::ANNOTATION_USE,
                self::FILE_SEND,
                self::FILE_RECEIVE,
            ],
            'Support' => [
                self::SUPPORT_REQUEST,
                self::SUPPORT_ACCEPT,
            ],
            'Participants' => [
                self::EXTERNAL_INVITE,
            ],
            'Recording' => [
                self::RECORDING_START,
                self::RECORDING_VIEW,
            ],
            'Administration' => [
                self::SESSION_HISTORY_OWN,
                self::SESSION_HISTORY_COMPANY,
                self::AUDIT_VIEW,
                self::POLICY_VIEW,
                self::POLICY_MANAGE,
            ],
        ];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_merge(...array_values(self::groups()));
    }

    public static function isValid(string $permission): bool
    {
        return in_array($permission, self::all(), true);
    }

    /**
     * What an ordinary AICOUNTLY employee gets before any role or user rule is
     * applied. Deliberately modest: share and be helped, nothing structural.
     * Company policy is still evaluated on top and can remove any of it.
     *
     * @return list<string>
     */
    public static function baselineMember(): array
    {
        return [
            self::ACCESS,
            self::SESSION_CREATE,
            self::SESSION_JOIN,
            self::SESSION_END,
            self::SCREEN_SHARE,
            self::SCREEN_VIEW,
            self::SAFE_SHARE,
            self::BROWSER_TAB_SHARE,
            self::WINDOW_SHARE,
            self::MICROPHONE_SHARE,
            self::CHAT_USE,
            self::ANNOTATION_USE,
            // File transfer is in the baseline because the company switch that
            // enables it defaults OFF and is a deliberate act. Leaving these
            // out would mean an organisation turning file transfer on had
            // turned it on for administrators only — a switch that does not do
            // what it says. The capability mask still removes them wherever
            // the policy, the plan or the feature flag says no.
            self::FILE_SEND,
            self::FILE_RECEIVE,
            self::SUPPORT_REQUEST,
            self::SESSION_HISTORY_OWN,
        ];
    }

    /**
     * A company administrator additionally owns policy, permissions and the
     * organisation-wide record.
     *
     * @return list<string>
     */
    public static function baselineCompanyAdmin(): array
    {
        return array_values(array_unique(array_merge(self::baselineMember(), [
            self::MONITOR_SHARE,
            self::SYSTEM_AUDIO_SHARE,
            self::EXTERNAL_INVITE,
            self::SESSION_HISTORY_COMPANY,
            self::AUDIT_VIEW,
            self::POLICY_VIEW,
            self::POLICY_MANAGE,
            self::RECORDING_START,
            self::RECORDING_VIEW,
        ])));
    }

    /**
     * An AICOUNTLY support technician can take a request and assist, but has no
     * authority over a customer's policy or organisation-wide history.
     *
     * @return list<string>
     */
    public static function baselineSupportAgent(): array
    {
        return array_values(array_unique(array_merge(self::baselineMember(), [
            self::SUPPORT_ACCEPT,
            self::FILE_RECEIVE,
        ])));
    }
}
