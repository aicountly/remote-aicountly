<?php

declare(strict_types=1);

namespace Tests\Policy;

use App\Domain\Policy\CompanyPolicyDefaults;
use App\Domain\Policy\PermissionCatalog;
use Config\Remote as RemoteConfig;
use Config\Services;
use Tests\Support\RemoteTestCase;

/**
 * The desktop switches, resolved through the same hierarchy as everything else.
 *
 * The property being asserted throughout is the one the whole policy layer
 * exists for: **a company prohibition beats a user grant**, because the
 * capability mask is applied after every role and user rule. Remote control is
 * the case where getting that ordering wrong would matter most.
 *
 * @internal
 */
final class DesktopPolicyTest extends RemoteTestCase
{
    public function testEveryDesktopCapabilityIsOffByDefault(): void
    {
        $user    = $this->makeIdentity('Fresh Start');
        $company = $this->makeCompany(900, 'Default Company');
        $this->grantCompanyAccess($user, $company, 'MEMBER', true);

        $policy = Services::policyResolver()->resolve($user, 'COMPANY', $company);

        $this->assertFalse($policy->allowRemoteControl);
        $this->assertFalse($policy->allowUnattendedAccess);
        $this->assertFalse($policy->allowClipboardSync);
        $this->assertFalse($policy->allowDeviceReboot);

        $this->assertFalse($policy->can(PermissionCatalog::CONTROL_REQUEST));
        $this->assertFalse($policy->can(PermissionCatalog::CONTROL_ACCEPT));
        $this->assertFalse($policy->can(PermissionCatalog::UNATTENDED_ACCESS));
        $this->assertFalse($policy->can(PermissionCatalog::DEVICE_ENROL));
    }

    /** Every preset, including OPEN. A preset is never a reason to hand out control. */
    public function testNoPresetTurnsOnADesktopCapability(): void
    {
        foreach (CompanyPolicyDefaults::PRESETS as $preset) {
            if ($preset === 'CUSTOM') {
                continue;
            }

            $values = CompanyPolicyDefaults::forPreset($preset);

            $this->assertFalse($values['allow_remote_control'], "{$preset} must not enable remote control");
            $this->assertFalse($values['allow_unattended_access'], "{$preset} must not enable unattended access");
            $this->assertFalse($values['allow_clipboard_sync'], "{$preset} must not enable clipboard sync");
            $this->assertFalse($values['allow_device_reboot'], "{$preset} must not enable reboot");
        }
    }

    /**
     * The load-bearing assertion. An administrator writing an explicit ALLOW
     * against a person cannot outvote the organisation's own switch.
     */
    public function testACompanyProhibitionBeatsAUserLevelAllow(): void
    {
        $user    = $this->makeIdentity('Eager');
        $company = $this->makeDesktopCompany(901, 'Control Off', ['allow_remote_control' => false]);
        $this->grantCompanyAccess($user, $company, 'MEMBER', true);

        $this->setUserPermission($user, $company, PermissionCatalog::CONTROL_REQUEST, 'ALLOW');
        $this->setUserPermission($user, $company, PermissionCatalog::UNATTENDED_ACCESS, 'ALLOW');

        $policy = Services::policyResolver()->resolve($user, 'COMPANY', $company);

        $this->assertFalse($policy->allowRemoteControl);
        $this->assertFalse($policy->can(PermissionCatalog::CONTROL_REQUEST));
        $this->assertFalse($policy->can(PermissionCatalog::UNATTENDED_ACCESS));
    }

    public function testARoleLevelAllowAlsoLosesToTheCompanySwitch(): void
    {
        $user    = $this->makeIdentity('Role Holder');
        $company = $this->makeDesktopCompany(902, 'Control Off', ['allow_remote_control' => false]);
        $this->grantCompanyAccess($user, $company, 'SUPPORT_LEAD');

        $this->setRolePermission($company, 'SUPPORT_LEAD', PermissionCatalog::CONTROL_REQUEST, 'ALLOW');

        $policy = Services::policyResolver()->resolve($user, 'COMPANY', $company);

        $this->assertFalse($policy->can(PermissionCatalog::CONTROL_REQUEST));
    }

    /**
     * The plan gate sits above the company switch: an organisation whose plan
     * does not include desktop devices gets nothing, however its policy reads.
     */
    public function testTheEntitlementCapsTheCompanySwitch(): void
    {
        $user    = $this->makeIdentity('Unentitled');
        $company = $this->makeCompany(903, 'No Plan', [
            'allow_remote_control'    => true,
            'allow_unattended_access' => true,
            'allow_clipboard_sync'    => true,
            'allow_device_reboot'     => true,
        ]);
        $this->setEntitlement($company, ['desktop_devices' => false, 'unattended_access' => false]);
        $this->grantCompanyAccess($user, $company, 'MEMBER', true);

        $policy = Services::policyResolver()->resolve($user, 'COMPANY', $company);

        $this->assertFalse($policy->allowRemoteControl);
        $this->assertFalse($policy->allowUnattendedAccess);
        $this->assertContains('REMOTE_CONTROL_NOT_ENTITLED', $policy->restrictions);
    }

    /**
     * Unattended access has its own entitlement on top of the desktop one. A
     * plan that includes devices but not unattended access gets attended
     * control and nothing more.
     */
    public function testUnattendedAccessNeedsItsOwnEntitlement(): void
    {
        $user    = $this->makeIdentity('Attended Only');
        $company = $this->makeDesktopCompany(904, 'Attended Plan', [], false);
        $this->grantCompanyAccess($user, $company, 'MEMBER', true);
        $this->setUserPermission($user, $company, PermissionCatalog::UNATTENDED_ACCESS, 'ALLOW');

        $policy = Services::policyResolver()->resolve($user, 'COMPANY', $company);

        $this->assertTrue($policy->allowRemoteControl);
        $this->assertFalse($policy->allowUnattendedAccess);
        $this->assertFalse($policy->can(PermissionCatalog::UNATTENDED_ACCESS));
        $this->assertContains('UNATTENDED_ACCESS_NOT_ENTITLED', $policy->restrictions);
    }

    /**
     * Clipboard, unattended access and reboot are all things you do *while
     * controlling a machine*, so none of them survives remote control being
     * off — whatever their own switches say.
     */
    public function testTheDependentSwitchesFallWithRemoteControl(): void
    {
        $user    = $this->makeIdentity('Dependent');
        $company = $this->makeDesktopCompany(905);
        $this->grantCompanyAccess($user, $company, 'MEMBER', true);

        $before = Services::policyResolver()->resolve($user, 'COMPANY', $company);
        $this->assertTrue($before->allowClipboardSync);
        $this->assertTrue($before->allowDeviceReboot);

        // Turning control off has to take the three with it, and the database
        // refuses to store the inconsistent shape either way.
        $this->db->table('remote_company_policies')->where('company_id', $company)->update([
            'allow_remote_control'    => false,
            'allow_unattended_access' => false,
            'allow_device_reboot'     => false,
        ]);
        Services::reset(true);
        $this->configureRemote(static function (RemoteConfig $config): void {
            $config->signallingSecret = 'test-signalling-secret';
        });

        $after = Services::policyResolver()->resolve($user, 'COMPANY', $company);

        $this->assertFalse($after->allowRemoteControl);
        $this->assertFalse($after->allowClipboardSync);
        $this->assertFalse($after->allowUnattendedAccess);
        $this->assertFalse($after->allowDeviceReboot);
    }

    /** A device belongs to a company; there is no personal-scope desktop agent. */
    public function testDesktopCapabilitiesAreNeverAvailableInPersonalScope(): void
    {
        $user = $this->makeIdentity('Personal');

        $policy = Services::policyResolver()->resolve($user, 'PERSONAL', null);

        $this->assertFalse($policy->allowRemoteControl);
        $this->assertFalse($policy->allowUnattendedAccess);
        $this->assertFalse($policy->can(PermissionCatalog::DEVICE_ENROL));
        $this->assertFalse($policy->can(PermissionCatalog::DEVICE_MANAGE));
        $this->assertFalse($policy->can(PermissionCatalog::CONTROL_REQUEST));
    }

    /**
     * The global flag can only ever remove capability (§67) — with desktop
     * agents switched off platform-wide, no policy and no plan restores them.
     */
    public function testTheGlobalFeatureFlagRemovesEverything(): void
    {
        $user    = $this->makeIdentity('Flagged Off');
        $company = $this->makeDesktopCompany(906);
        $this->grantCompanyAccess($user, $company, 'MEMBER', true);

        $this->configureRemote(static function (RemoteConfig $config): void {
            $config->signallingSecret   = 'test-signalling-secret';
            $config->featureDesktopAgent = false;
        });

        $policy = Services::policyResolver()->resolve($user, 'COMPANY', $company);

        $this->assertFalse($policy->allowRemoteControl);
        $this->assertFalse($policy->allowUnattendedAccess);
        $this->assertFalse($policy->can(PermissionCatalog::DEVICE_ENROL));
    }

    public function testTurningRemoteOffRemovesEveryDesktopPermission(): void
    {
        $user    = $this->makeIdentity('Disabled Org');
        $company = $this->makeDesktopCompany(907);
        $this->grantCompanyAccess($user, $company, 'MEMBER', true);

        // `remote_enabled = false` requires every sharing switch off too — the
        // table's own CHECK, and the same rule the admin controller applies.
        $this->db->table('remote_company_policies')->where('company_id', $company)->update([
            'remote_enabled'           => false,
            'allow_safe_share'         => false,
            'allow_browser_tab'        => false,
            'allow_application_window' => false,
            'allow_entire_monitor'     => false,
            'allow_remote_control'     => false,
            'allow_unattended_access'  => false,
            'allow_clipboard_sync'     => false,
            'allow_device_reboot'      => false,
        ]);

        $policy = Services::policyResolver()->resolve($user, 'COMPANY', $company);

        $this->assertFalse($policy->remoteEnabled);
        foreach ([
            PermissionCatalog::CONTROL_REQUEST,
            PermissionCatalog::CONTROL_ACCEPT,
            PermissionCatalog::DEVICE_ENROL,
            PermissionCatalog::DEVICE_MANAGE,
            PermissionCatalog::UNATTENDED_ACCESS,
        ] as $permission) {
            $this->assertFalse($policy->can($permission), "{$permission} must be off when Remote is disabled");
        }
    }

    /**
     * The ceiling handed to a device is the *policy's*, not the agent's claim.
     */
    public function testTheDesktopCapabilityCeilingFollowsPolicy(): void
    {
        $user    = $this->makeIdentity('Ceiling');
        $company = $this->makeDesktopCompany(908, 'Ceiling Co', ['allow_clipboard_sync' => false]);
        $this->grantCompanyAccess($user, $company, 'MEMBER', true);
        $this->setUserPermission($user, $company, PermissionCatalog::UNATTENDED_ACCESS, 'ALLOW');

        $ceiling = Services::policyResolver()
            ->resolve($user, 'COMPANY', $company)
            ->desktopCapabilityCeiling();

        $this->assertTrue($ceiling['remote_control']);
        $this->assertTrue($ceiling['unattended_access']);
        $this->assertFalse($ceiling['clipboard_sync']);
        $this->assertTrue($ceiling['reboot']);
    }

    public function testTheNewPermissionsAreInTheCatalogAndValidated(): void
    {
        foreach ([
            PermissionCatalog::CONTROL_REQUEST,
            PermissionCatalog::CONTROL_ACCEPT,
            PermissionCatalog::DEVICE_ENROL,
            PermissionCatalog::DEVICE_MANAGE,
            PermissionCatalog::UNATTENDED_ACCESS,
        ] as $permission) {
            $this->assertTrue(PermissionCatalog::isValid($permission), $permission);
            $this->assertContains($permission, PermissionCatalog::all());
        }

        $this->assertArrayHasKey('Desktop', PermissionCatalog::groups());
    }
}
