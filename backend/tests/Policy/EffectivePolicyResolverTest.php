<?php

declare(strict_types=1);

namespace Tests\Policy;

use App\Domain\Policy\PermissionCatalog;
use App\Domain\Support\ApiException;
use Config\Services;
use Tests\Support\RemoteTestCase;

/**
 * The permission hierarchy (§9, §11).
 *
 * These are the tests that matter most in the product: every other guarantee —
 * tenant isolation, surface enforcement, guest restriction — is expressed as a
 * permission, so a bug here is a bug everywhere.
 *
 * @internal
 */
final class EffectivePolicyResolverTest extends RemoteTestCase
{
    public function testCompanyProhibitionBeatsUserGrant(): void
    {
        // The single most important rule in the product: an administrator can
        // grant a user anything, and it still cannot exceed company policy.
        $identity = $this->makeIdentity('Rahul Gupta');
        $company  = $this->makeCompany(481, 'ABC Private Limited', ['allow_entire_monitor' => false]);
        $this->grantCompanyAccess($identity, $company);

        $this->setUserPermission($identity, $company, PermissionCatalog::MONITOR_SHARE, 'ALLOW');

        $policy = Services::policyResolver()->resolve($identity, 'COMPANY', $company);

        $this->assertFalse(
            $policy->can(PermissionCatalog::MONITOR_SHARE),
            'A user-level ALLOW must never override a company-wide prohibition.',
        );
        $this->assertFalse($policy->allowsShareMode('ENTIRE_MONITOR'));
        $this->assertNotContains('ENTIRE_MONITOR', $policy->allowedShareModes());
    }

    public function testUserDenyNarrowsWhatCompanyPermits(): void
    {
        // The same rule in the other direction: restriction always applies.
        $identity = $this->makeIdentity();
        $company  = $this->makeCompany(481, 'ABC', ['allow_entire_monitor' => true]);
        $this->grantCompanyAccess($identity, $company, 'COMPANY_ADMIN', true);

        $before = Services::policyResolver()->resolve($identity, 'COMPANY', $company);
        $this->assertTrue($before->can(PermissionCatalog::MONITOR_SHARE));

        $this->setUserPermission($identity, $company, PermissionCatalog::MONITOR_SHARE, 'DENY');

        $after = Services::policyResolver()->resolve($identity, 'COMPANY', $company);
        $this->assertFalse($after->can(PermissionCatalog::MONITOR_SHARE));
    }

    public function testUserRuleOverridesRoleRule(): void
    {
        $identity = $this->makeIdentity();
        $company  = $this->makeCompany(481);
        $this->grantCompanyAccess($identity, $company, 'SUPPORT_DESK');

        $this->setRolePermission($company, 'SUPPORT_DESK', PermissionCatalog::CHAT_USE, 'DENY');
        $policy = Services::policyResolver()->resolve($identity, 'COMPANY', $company);
        $this->assertFalse($policy->can(PermissionCatalog::CHAT_USE), 'A role DENY should remove the baseline grant.');

        // More specific wins: the user rule is applied after the role rule.
        $this->setUserPermission($identity, $company, PermissionCatalog::CHAT_USE, 'ALLOW');
        $policy = Services::policyResolver()->resolve($identity, 'COMPANY', $company);
        $this->assertTrue($policy->can(PermissionCatalog::CHAT_USE));
    }

    public function testCompanyRoleRuleOverridesPlatformRoleRule(): void
    {
        $identity = $this->makeIdentity();
        $company  = $this->makeCompany(481);
        $this->grantCompanyAccess($identity, $company, 'FIELD_STAFF');

        $this->setRolePermission(null, 'FIELD_STAFF', PermissionCatalog::ANNOTATION_USE, 'DENY');
        $this->setRolePermission($company, 'FIELD_STAFF', PermissionCatalog::ANNOTATION_USE, 'ALLOW');

        $policy = Services::policyResolver()->resolve($identity, 'COMPANY', $company);

        $this->assertTrue(
            $policy->can(PermissionCatalog::ANNOTATION_USE),
            'The company rule is applied after the platform rule and must win.',
        );
    }

    public function testDisablingRemoteRemovesEverything(): void
    {
        $identity = $this->makeIdentity();
        $company  = $this->makeCompany(481, 'ABC', [
            'remote_enabled'           => false,
            'allow_safe_share'         => false,
            'allow_browser_tab'        => false,
            'allow_application_window' => false,
            'allow_entire_monitor'     => false,
        ]);
        $this->grantCompanyAccess($identity, $company, 'COMPANY_ADMIN', true);
        $this->setUserPermission($identity, $company, PermissionCatalog::SCREEN_SHARE, 'ALLOW');

        $policy = Services::policyResolver()->resolve($identity, 'COMPANY', $company);

        $this->assertFalse($policy->remoteEnabled);
        $this->assertSame([], $policy->allowedShareModes());
        $this->assertContains('COMPANY_REMOTE_DISABLED', $policy->restrictions);

        foreach (PermissionCatalog::all() as $permission) {
            $this->assertFalse($policy->can($permission), "{$permission} must be off when Remote is disabled.");
        }
    }

    public function testEntitlementCapsCompanyPolicy(): void
    {
        // A company may permit guests; without the entitlement it still cannot
        // have them. The entitlement is evaluated above the company (§9).
        $identity = $this->makeIdentity();
        $company  = $this->makeCompany(481, 'ABC', ['allow_external_guest' => true, 'allow_file_transfer' => true]);
        $this->grantCompanyAccess($identity, $company, 'COMPANY_ADMIN', true);

        $this->setEntitlement($company, ['external_guests' => false, 'file_transfer' => false]);

        $policy = Services::policyResolver()->resolve($identity, 'COMPANY', $company);

        $this->assertFalse($policy->allowExternalGuest);
        $this->assertFalse($policy->can(PermissionCatalog::EXTERNAL_INVITE));
        $this->assertFalse($policy->allowFileTransfer);
        $this->assertContains('EXTERNAL_GUEST_NOT_ENTITLED', $policy->restrictions);
    }

    public function testEntitlementDurationCapIsTheShorterOfTheTwo(): void
    {
        $identity = $this->makeIdentity();
        $company  = $this->makeCompany(481, 'ABC', ['max_session_duration_minutes' => 240]);
        $this->grantCompanyAccess($identity, $company);
        $this->setEntitlement($company, ['max_session_duration_minutes' => 30]);

        $policy = Services::policyResolver()->resolve($identity, 'COMPANY', $company);

        $this->assertSame(30, $policy->maxSessionDurationMinutes);
    }

    public function testGlobalFeatureFlagRemovesCapabilityRegardlessOfPolicy(): void
    {
        $identity = $this->makeIdentity();
        $company  = $this->makeCompany(481, 'ABC', ['allow_file_transfer' => true]);
        $this->grantCompanyAccess($identity, $company, 'COMPANY_ADMIN', true);

        $this->configureRemote(static function ($config): void {
            $config->contextSecret      = 'test-context-secret';
            $config->signallingSecret   = 'test-signalling-secret';
            $config->featureFileTransfer = false;
        });

        $policy = Services::policyResolver()->resolve($identity, 'COMPANY', $company);

        $this->assertFalse($policy->allowFileTransfer, 'A disabled feature flag removes the capability outright.');
        $this->assertFalse($policy->can(PermissionCatalog::FILE_SEND));
    }

    public function testNewCompanyGetsTheConservativeDefault(): void
    {
        // §8: a company nobody has configured must not start out permissive.
        $identity = $this->makeIdentity();
        $this->db->query('INSERT INTO remote_company_directory (company_id, name) VALUES (777, \'Fresh Co\')');
        $this->grantCompanyAccess($identity, 777);

        $policy = Services::policyResolver()->resolve($identity, 'COMPANY', 777);

        $this->assertTrue($policy->remoteEnabled);
        $this->assertTrue($policy->allowSafeShare);
        $this->assertTrue($policy->allowBrowserTab);
        $this->assertTrue($policy->allowApplicationWindow);
        $this->assertFalse($policy->allowEntireMonitor, 'Entire-screen sharing must be off by default.');
        $this->assertFalse($policy->allowExternalGuest, 'External guests must be off by default.');
        $this->assertFalse($policy->allowRecording, 'Recording must be off by default.');
        $this->assertSame('STANDARD', $policy->policyPreset);
    }

    public function testPersonalScopeHasNoCompanyAdministration(): void
    {
        $identity = $this->makeIdentity();
        $company  = $this->makeCompany(481);
        $this->grantCompanyAccess($identity, $company, 'COMPANY_ADMIN', true);

        $personal = Services::policyResolver()->resolve($identity, 'PERSONAL', null);

        $this->assertNull($personal->companyId);
        $this->assertFalse($personal->can(PermissionCatalog::POLICY_MANAGE));
        $this->assertFalse($personal->can(PermissionCatalog::SESSION_HISTORY_COMPANY));
        $this->assertFalse($personal->can(PermissionCatalog::AUDIT_VIEW));
        $this->assertTrue($personal->can(PermissionCatalog::SESSION_CREATE));
    }

    public function testPersonalScopeIgnoresACompanyIdEvenWhenOneIsPassed(): void
    {
        $identity = $this->makeIdentity();
        $company  = $this->makeCompany(481, 'ABC', ['remote_enabled' => false, 'allow_safe_share' => false, 'allow_browser_tab' => false, 'allow_application_window' => false, 'allow_entire_monitor' => false]);
        $this->grantCompanyAccess($identity, $company);

        // A caller cannot smuggle a company in through a PERSONAL request, in
        // either direction — the scope decides, not the parameter.
        $policy = Services::policyResolver()->resolve($identity, 'PERSONAL', $company);

        $this->assertNull($policy->companyId);
        $this->assertTrue($policy->remoteEnabled);
    }

    public function testMonitorSharingIsOffByDefaultInPersonalScope(): void
    {
        // Personal policy permits the surface, but the baseline permission set
        // does not include remote.monitor.share — so it is off until granted.
        $identity = $this->makeIdentity();

        $policy = Services::policyResolver()->resolve($identity, 'PERSONAL', null);

        $this->assertTrue($policy->allowEntireMonitor);
        $this->assertFalse($policy->can(PermissionCatalog::MONITOR_SHARE));
        $this->assertFalse($policy->allowsShareMode('ENTIRE_MONITOR'));

        $this->setUserPermission($identity, null, PermissionCatalog::MONITOR_SHARE, 'ALLOW');
        $granted = Services::policyResolver()->resolve($identity, 'PERSONAL', null);
        $this->assertTrue($granted->allowsShareMode('ENTIRE_MONITOR'));
    }

    public function testCompanyScopeWithoutMembershipIsRefused(): void
    {
        $identity = $this->makeIdentity();
        $this->makeCompany(481);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('You do not have access to this organisation in AICOUNTLY.');

        Services::policyResolver()->resolve($identity, 'COMPANY', 481);
    }

    public function testCompanyScopeWithoutACompanyIsRejected(): void
    {
        $identity = $this->makeIdentity();

        $this->expectException(ApiException::class);

        Services::policyResolver()->resolve($identity, 'COMPANY', null);
    }

    public function testSafeShareSurfaceIsAcceptedWhenOnlySafeShareIsPermitted(): void
    {
        // Safe Share is a browser tab as far as getDisplayMedia is concerned,
        // so a 'browser' surface must be accepted under the SAFE preset even
        // though plain tab sharing is off (§14, §16).
        $identity = $this->makeIdentity();
        $company  = $this->makeCompany(481, 'ABC', [
            'allow_safe_share'         => true,
            'allow_browser_tab'        => false,
            'allow_application_window' => false,
            'allow_entire_monitor'     => false,
        ]);
        $this->grantCompanyAccess($identity, $company);

        $policy = Services::policyResolver()->resolve($identity, 'COMPANY', $company);

        $this->assertTrue($policy->allowsDisplaySurface('browser'));
        $this->assertFalse($policy->allowsDisplaySurface('window'));
        $this->assertFalse($policy->allowsDisplaySurface('monitor'));
        $this->assertSame(['SAFE_SHARE'], $policy->allowedShareModes());
    }
}
