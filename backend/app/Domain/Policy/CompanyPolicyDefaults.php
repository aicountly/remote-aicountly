<?php

declare(strict_types=1);

namespace App\Domain\Policy;

/**
 * The five company policy presets (§8) and the personal-scope defaults.
 *
 * A preset is a starting point, not a lock: saving one writes its values into
 * `remote_company_policies`, after which an administrator may change any switch
 * — which moves the record to CUSTOM. Reading a preset back therefore never
 * overrides what is stored.
 */
final class CompanyPolicyDefaults
{
    public const PRESETS = ['RESTRICTED', 'SAFE', 'STANDARD', 'OPEN', 'CUSTOM'];

    /** Every switch a preset sets, in the order the admin UI shows them. */
    public const TOGGLES = [
        'remote_enabled',
        'allow_safe_share',
        'allow_browser_tab',
        'allow_application_window',
        'allow_entire_monitor',
        'allow_microphone',
        'allow_system_audio',
        'allow_text_chat',
        'allow_annotation',
        'allow_file_transfer',
        'allow_external_guest',
        'allow_internal_sessions',
        'allow_aicountly_support',
        'allow_recording',
        'recording_requires_consent',
        // --- Desktop agent switches (docs/DESKTOP_AGENT.md) ---------------
        // OFF in every preset, OPEN included. A preset is a starting point for
        // an organisation that has not thought about it yet, and "has not
        // thought about it" is never a reason to hand out control of a
        // Windows desktop. An administrator turns these on deliberately, which
        // moves the record to CUSTOM and says so.
        'allow_remote_control',
        'allow_unattended_access',
        'allow_clipboard_sync',
        'allow_device_reboot',
    ];

    /**
     * A brand-new company gets STANDARD: assistance works out of the box, but
     * nothing that can expose more than the user intended is on (§8).
     *
     * @return array<string, mixed>
     */
    public static function forPreset(string $preset): array
    {
        return match (strtoupper($preset)) {
            'RESTRICTED' => [
                'policy_preset'                => 'RESTRICTED',
                'remote_enabled'               => false,
                'allow_safe_share'             => false,
                'allow_browser_tab'            => false,
                'allow_application_window'     => false,
                'allow_entire_monitor'         => false,
                'allow_microphone'             => false,
                'allow_system_audio'           => false,
                'allow_text_chat'              => false,
                'allow_annotation'             => false,
                'allow_file_transfer'          => false,
                'allow_external_guest'         => false,
                'allow_internal_sessions'      => false,
                'allow_aicountly_support'      => false,
                'allow_recording'              => false,
                'recording_requires_consent'   => true,
                'allow_remote_control'         => false,
                'allow_unattended_access'      => false,
                'allow_clipboard_sync'         => false,
                'allow_device_reboot'          => false,
                'max_session_duration_minutes' => 30,
                'guest_link_expiry_minutes'    => 5,
            ],
            // AICOUNTLY-assisted support only: staff cannot start sessions
            // between themselves, and only the Safe Share surface is offered.
            'SAFE' => [
                'policy_preset'                => 'SAFE',
                'remote_enabled'               => true,
                'allow_safe_share'             => true,
                'allow_browser_tab'            => false,
                'allow_application_window'     => false,
                'allow_entire_monitor'         => false,
                'allow_microphone'             => false,
                'allow_system_audio'           => false,
                'allow_text_chat'              => true,
                'allow_annotation'             => true,
                'allow_file_transfer'          => false,
                'allow_external_guest'         => false,
                'allow_internal_sessions'      => false,
                'allow_aicountly_support'      => true,
                'allow_recording'              => false,
                'recording_requires_consent'   => true,
                'allow_remote_control'         => false,
                'allow_unattended_access'      => false,
                'allow_clipboard_sync'         => false,
                'allow_device_reboot'          => false,
                'max_session_duration_minutes' => 45,
                'guest_link_expiry_minutes'    => 5,
            ],
            'OPEN' => [
                'policy_preset'                => 'OPEN',
                'remote_enabled'               => true,
                'allow_safe_share'             => true,
                'allow_browser_tab'            => true,
                'allow_application_window'     => true,
                'allow_entire_monitor'         => true,
                'allow_microphone'             => true,
                'allow_system_audio'           => true,
                'allow_text_chat'              => true,
                'allow_annotation'             => true,
                'allow_file_transfer'          => true,
                'allow_external_guest'         => true,
                'allow_internal_sessions'      => true,
                'allow_aicountly_support'      => true,
                'allow_recording'              => false,
                'recording_requires_consent'   => true,
                'allow_remote_control'         => false,
                'allow_unattended_access'      => false,
                'allow_clipboard_sync'         => false,
                'allow_device_reboot'          => false,
                'max_session_duration_minutes' => 240,
                'guest_link_expiry_minutes'    => 30,
            ],
            default => [
                'policy_preset'                => 'STANDARD',
                'remote_enabled'               => true,
                'allow_safe_share'             => true,
                'allow_browser_tab'            => true,
                'allow_application_window'     => true,
                // Off on purpose. An entire monitor exposes every application
                // the person has open, including ones nobody meant to share.
                'allow_entire_monitor'         => false,
                'allow_microphone'             => true,
                'allow_system_audio'           => false,
                'allow_text_chat'              => true,
                'allow_annotation'             => true,
                'allow_file_transfer'          => false,
                'allow_external_guest'         => false,
                'allow_internal_sessions'      => true,
                'allow_aicountly_support'      => true,
                'allow_recording'              => false,
                'recording_requires_consent'   => true,
                'allow_remote_control'         => false,
                'allow_unattended_access'      => false,
                'allow_clipboard_sync'         => false,
                'allow_device_reboot'          => false,
                'max_session_duration_minutes' => 60,
                'guest_link_expiry_minutes'    => 10,
            ],
        };
    }

    /**
     * PERSONAL scope has no organisation behind it, so there is no company
     * policy to consult — but "no policy" must not mean "no limits". These are
     * the platform's own defaults for a personal session, and entire-monitor
     * sharing is still gated on the user holding `remote.monitor.share`.
     *
     * @return array<string, mixed>
     */
    public static function personal(): array
    {
        return [
            'policy_preset'                => 'STANDARD',
            'remote_enabled'               => true,
            'allow_safe_share'             => true,
            'allow_browser_tab'            => true,
            'allow_application_window'     => true,
            'allow_entire_monitor'         => true,
            'allow_microphone'             => true,
            'allow_system_audio'           => false,
            'allow_text_chat'              => true,
            'allow_annotation'             => true,
            'allow_file_transfer'          => true,
            'allow_external_guest'         => true,
            'allow_internal_sessions'      => true,
            'allow_aicountly_support'      => true,
            'allow_recording'              => false,
            'recording_requires_consent'   => true,
            // Personal scope has no organisation to govern a desktop agent, so
            // it governs none: a device is always enrolled into a company.
            'allow_remote_control'         => false,
            'allow_unattended_access'      => false,
            'allow_clipboard_sync'         => false,
            'allow_device_reboot'          => false,
            'max_session_duration_minutes' => 60,
            'guest_link_expiry_minutes'    => 10,
        ];
    }

    /**
     * Which preset a stored row corresponds to, or CUSTOM when it matches none.
     * Called after every policy save so the label the admin sees stays true.
     *
     * @param array<string, mixed> $stored
     */
    public static function detectPreset(array $stored): string
    {
        foreach (['RESTRICTED', 'SAFE', 'STANDARD', 'OPEN'] as $preset) {
            $candidate = self::forPreset($preset);
            $matches   = true;

            foreach (self::TOGGLES as $toggle) {
                if ((bool) ($stored[$toggle] ?? false) !== (bool) $candidate[$toggle]) {
                    $matches = false;
                    break;
                }
            }

            if ($matches
                && (int) ($stored['max_session_duration_minutes'] ?? 0) === $candidate['max_session_duration_minutes']
                && (int) ($stored['guest_link_expiry_minutes'] ?? 0) === $candidate['guest_link_expiry_minutes']
            ) {
                return $preset;
            }
        }

        return 'CUSTOM';
    }
}
