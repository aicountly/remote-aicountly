<?php

declare(strict_types=1);

namespace App\Domain\Policy;

/**
 * What one user may actually do, in one scope, right now.
 *
 * This is the *output* of {@see EffectivePolicyResolver} — already the
 * intersection of the global flags, the entitlement, the company policy, the
 * role grants and the user grants. Nothing downstream re-derives it, and
 * nothing downstream is allowed to widen it.
 */
final class EffectivePolicy
{
    /**
     * @param array<string, bool> $permissions permission name => granted
     * @param list<string>        $restrictions machine reasons a capability is off,
     *                                          for the UI to explain (§39)
     */
    public function __construct(
        public readonly bool $remoteEnabled,
        public readonly string $scopeType,
        public readonly ?int $companyId,
        public readonly ?string $companyName,
        public readonly string $policyPreset,
        public readonly bool $allowSafeShare,
        public readonly bool $allowBrowserTab,
        public readonly bool $allowApplicationWindow,
        public readonly bool $allowEntireMonitor,
        public readonly bool $allowMicrophone,
        public readonly bool $allowSystemAudio,
        public readonly bool $allowTextChat,
        public readonly bool $allowAnnotation,
        public readonly bool $allowFileTransfer,
        public readonly bool $allowExternalGuest,
        public readonly bool $allowInternalSessions,
        public readonly bool $allowAicountlySupport,
        public readonly bool $allowRecording,
        public readonly bool $recordingRequiresConsent,
        public readonly int $maxSessionDurationMinutes,
        public readonly int $guestLinkExpiryMinutes,
        public readonly array $permissions,
        public readonly array $restrictions = [],
    ) {
    }

    public function can(string $permission): bool
    {
        return ($this->permissions[$permission] ?? false) === true;
    }

    /**
     * Is this specific sharing surface permitted?
     *
     * Both halves must agree: the organisation must allow the surface *and* the
     * user must hold the matching permission. This is the check the share-intent
     * endpoint runs before the browser picker is ever opened, and the same
     * mapping the frontend uses to decide which cards to show (§14, §16).
     */
    public function allowsShareMode(string $shareMode): bool
    {
        return match ($shareMode) {
            'SAFE_SHARE'         => $this->allowSafeShare && $this->can(PermissionCatalog::SAFE_SHARE),
            'BROWSER_TAB'        => $this->allowBrowserTab && $this->can(PermissionCatalog::BROWSER_TAB_SHARE),
            'APPLICATION_WINDOW' => $this->allowApplicationWindow && $this->can(PermissionCatalog::WINDOW_SHARE),
            'ENTIRE_MONITOR'     => $this->allowEntireMonitor && $this->can(PermissionCatalog::MONITOR_SHARE),
            default              => false,
        };
    }

    /**
     * Map a browser `displaySurface` value back onto the policy (§16).
     *
     * Safe Share is a browser tab as far as the Screen Capture API is
     * concerned, so a `browser` surface is acceptable when *either* Safe Share
     * or plain tab sharing is permitted.
     */
    public function allowsDisplaySurface(string $surface): bool
    {
        return match ($surface) {
            'browser' => ($this->allowBrowserTab && $this->can(PermissionCatalog::BROWSER_TAB_SHARE))
                || ($this->allowSafeShare && $this->can(PermissionCatalog::SAFE_SHARE)),
            'window'  => $this->allowsShareMode('APPLICATION_WINDOW'),
            'monitor' => $this->allowsShareMode('ENTIRE_MONITOR'),
            default   => false,
        };
    }

    /** @return list<string> */
    public function allowedShareModes(): array
    {
        return array_values(array_filter(
            ['SAFE_SHARE', 'BROWSER_TAB', 'APPLICATION_WINDOW', 'ENTIRE_MONITOR'],
            fn (string $mode) => $this->allowsShareMode($mode),
        ));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'remoteEnabled'            => $this->remoteEnabled,
            'scopeType'                => $this->scopeType,
            'companyId'                => $this->companyId,
            'companyName'              => $this->companyName,
            'policyPreset'             => $this->policyPreset,
            'allowSafeShare'           => $this->allowSafeShare,
            'allowBrowserTab'          => $this->allowBrowserTab,
            'allowApplicationWindow'   => $this->allowApplicationWindow,
            'allowEntireMonitor'       => $this->allowEntireMonitor,
            'allowMicrophone'          => $this->allowMicrophone,
            'allowSystemAudio'         => $this->allowSystemAudio,
            'allowTextChat'            => $this->allowTextChat,
            'allowAnnotation'          => $this->allowAnnotation,
            'allowFileTransfer'        => $this->allowFileTransfer,
            'allowExternalGuest'       => $this->allowExternalGuest,
            'allowInternalSessions'    => $this->allowInternalSessions,
            'allowAicountlySupport'    => $this->allowAicountlySupport,
            'allowRecording'           => $this->allowRecording,
            'recordingRequiresConsent' => $this->recordingRequiresConsent,
            'maxSessionDurationMinutes' => $this->maxSessionDurationMinutes,
            'guestLinkExpiryMinutes'   => $this->guestLinkExpiryMinutes,
            'allowedShareModes'        => $this->allowedShareModes(),
            'permissions'              => $this->permissions,
            'restrictions'             => $this->restrictions,
        ];
    }
}
