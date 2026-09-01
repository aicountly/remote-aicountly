<?php

declare(strict_types=1);

namespace App\Domain\Session;

/**
 * Capability negotiation between Remote and whatever kind of client connected
 * (§51).
 *
 * This is the seam that keeps the future desktop agent from becoming a second
 * product. A participant declares what it can do; the session UI is built from
 * the answer rather than from a hardcoded "browser means these buttons". When
 * `AICOUNTLY Remote for Windows` arrives it registers as a DESKTOP_AGENT with
 * `remote_control: true` and the same session, participant, invitation, policy
 * and audit machinery carries it — no schema change, no parallel API.
 *
 * Browser V1 reports `remote_control: false` and `unattended_access: false`,
 * and the UI must never offer either. Saying otherwise in the interface would
 * be a lie about what the product can do (§2).
 */
final class ClientCapabilities
{
    public const CLIENT_BROWSER      = 'BROWSER';
    public const CLIENT_DESKTOP_AGENT = 'DESKTOP_AGENT';
    public const CLIENT_MOBILE       = 'MOBILE';

    /**
     * What a browser participant can do. Screen *sharing* and *viewing*, yes;
     * operating-system control, no — the Screen Capture API cannot do it, and
     * pretending otherwise is not a UI decision to make.
     *
     * @return array<string, bool>
     */
    public static function browser(): array
    {
        return [
            'screen_share'      => true,
            'screen_view'       => true,
            'remote_control'    => false,
            'unattended_access' => false,
            'file_transfer'     => true,
            'clipboard_sync'    => false,
            'reboot'            => false,
        ];
    }

    /**
     * A mobile browser can watch and talk, but typically cannot capture (§64).
     *
     * @return array<string, bool>
     */
    public static function mobileBrowser(): array
    {
        return array_merge(self::browser(), [
            'screen_share'  => false,
            'file_transfer' => false,
        ]);
    }

    /**
     * The shape a future desktop agent will report. Nothing in V1 produces
     * this; it is here so the contract is written down in one place rather than
     * invented twice.
     *
     * @return array<string, bool>
     */
    public static function desktopAgent(): array
    {
        return [
            'screen_share'      => true,
            'screen_view'       => true,
            'remote_control'    => true,
            'unattended_access' => true,
            'file_transfer'     => true,
            'clipboard_sync'    => true,
            'reboot'            => true,
        ];
    }

    /**
     * Normalise whatever a client claimed against the known keys, defaulting
     * anything unrecognised to false.
     *
     * A client cannot grant itself a capability by asserting one: this is the
     * declaration, and policy is still evaluated on top of it.
     *
     * @param  array<string, mixed> $claimed
     * @return array<string, bool>
     */
    public static function normalise(string $clientType, array $claimed): array
    {
        $ceiling = match ($clientType) {
            self::CLIENT_DESKTOP_AGENT => self::desktopAgent(),
            self::CLIENT_MOBILE        => self::mobileBrowser(),
            default                    => self::browser(),
        };

        $result = [];
        foreach ($ceiling as $key => $possible) {
            $result[$key] = $possible && (($claimed[$key] ?? false) === true);
        }

        return $result;
    }
}
