<?php

declare(strict_types=1);

namespace App\Domain\Policy;

use App\Domain\Auth\RemoteIdentity;
use App\Domain\Support\ApiException;
use CodeIgniter\Database\BaseConnection;
use Config\Remote as RemoteConfig;

/**
 * The single place where "what may this person do?" is answered (§9).
 *
 * Evaluation order, top to bottom:
 *
 *     AICOUNTLY global policy   (Config\Remote feature flags)
 *            ↓
 *     product entitlement       (remote_entitlements)
 *            ↓
 *     company policy            (remote_company_policies)
 *            ↓
 *     role permission           (remote_role_permissions)
 *            ↓
 *     user permission           (remote_user_permissions)
 *            ↓
 *     session policy            (applied later, per session)
 *            ↓
 *     browser user consent      (the operating system's picker; never ours)
 *
 * **The most restrictive applicable rule wins.** The mechanism that guarantees
 * it is the ordering inside {@see resolve()}: role and user grants are applied
 * first and the capability mask is applied *last*, so an ALLOW written against
 * a user can never survive a company-level prohibition (§11). Reversing those
 * two steps would silently turn every user grant into an override, which is
 * exactly the bug this class is shaped to prevent.
 *
 * Nothing here trusts the client. `companyId` arrives from a route or a
 * verified context token, and membership is checked against the platform
 * projection before a single capability is read.
 */
class EffectivePolicyResolver
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly RemoteConfig $config,
    ) {
    }

    /**
     * @param  'PERSONAL'|'COMPANY'|'AICOUNTLY_SUPPORT' $scopeType
     * @throws ApiException when the user has no relationship with the company
     */
    public function resolve(RemoteIdentity $identity, string $scopeType, ?int $companyId): EffectivePolicy
    {
        if ($scopeType === 'PERSONAL') {
            $companyId = null;
        }

        if ($scopeType !== 'PERSONAL' && $companyId === null) {
            throw ApiException::badRequest('COMPANY_REQUIRED', 'This session needs a company context.');
        }

        $membership = null;
        if ($companyId !== null) {
            $membership = $this->membership($identity->id, $companyId);

            // Tenant isolation starts here: no membership, no policy, no
            // sessions, no history — regardless of what the caller asked for.
            if ($membership === null && ! $identity->isPlatformAdmin) {
                throw ApiException::forbidden(
                    'COMPANY_ACCESS_DENIED',
                    'You do not have access to this organisation in AICOUNTLY.',
                );
            }
        }

        $policyRow   = $companyId !== null ? $this->companyPolicy($companyId) : CompanyPolicyDefaults::personal();
        $entitlement = $this->entitlement($companyId);
        $restrictions = [];

        // --- 1..3: global flags ∧ entitlement ∧ company policy --------------
        $remoteEnabled = (bool) $policyRow['remote_enabled'];
        if (! $remoteEnabled) {
            $restrictions[] = 'COMPANY_REMOTE_DISABLED';
        }

        $allowSafeShare = $remoteEnabled
            && (bool) $policyRow['allow_safe_share']
            && $this->config->featureSafeShare;
        $allowBrowserTab       = $remoteEnabled && (bool) $policyRow['allow_browser_tab'];
        $allowApplicationWindow = $remoteEnabled && (bool) $policyRow['allow_application_window'];
        $allowEntireMonitor    = $remoteEnabled && (bool) $policyRow['allow_entire_monitor'];

        if ($remoteEnabled && ! $allowEntireMonitor) {
            $restrictions[] = 'ENTIRE_MONITOR_RESTRICTED';
        }

        $allowMicrophone = $remoteEnabled
            && (bool) $policyRow['allow_microphone']
            && $this->config->featureMicrophone;
        $allowSystemAudio = $remoteEnabled && (bool) $policyRow['allow_system_audio'];
        $allowTextChat    = $remoteEnabled && (bool) $policyRow['allow_text_chat'];
        $allowAnnotation  = $remoteEnabled && (bool) $policyRow['allow_annotation'];

        $allowFileTransfer = $remoteEnabled
            && (bool) $policyRow['allow_file_transfer']
            && $this->config->featureFileTransfer
            && (bool) $entitlement['file_transfer'];
        if ($remoteEnabled && (bool) $policyRow['allow_file_transfer'] && ! $allowFileTransfer) {
            $restrictions[] = 'FILE_TRANSFER_UNAVAILABLE';
        }

        $allowExternalGuest = $remoteEnabled
            && (bool) $policyRow['allow_external_guest']
            && $this->config->featureExternalGuest
            && (bool) $entitlement['external_guests'];
        if ($remoteEnabled && (bool) $policyRow['allow_external_guest'] && ! $allowExternalGuest) {
            $restrictions[] = 'EXTERNAL_GUEST_NOT_ENTITLED';
        }

        $allowRecording = $remoteEnabled
            && (bool) $policyRow['allow_recording']
            && $this->config->featureRecording
            && (bool) $entitlement['recording'];

        $allowInternalSessions = $remoteEnabled && (bool) $policyRow['allow_internal_sessions'];
        $allowAicountlySupport = $remoteEnabled && (bool) $policyRow['allow_aicountly_support'];

        // The entitlement's duration cap is a ceiling on the company's own, not
        // a replacement: whichever is shorter is what applies.
        $maxDuration = (int) $policyRow['max_session_duration_minutes'];
        if ($entitlement['max_session_duration_minutes'] !== null) {
            $maxDuration = min($maxDuration, (int) $entitlement['max_session_duration_minutes']);
        }

        // --- 4..5: role grants, then user grants ---------------------------
        $granted = $this->baselinePermissions($identity, $membership, $scopeType);
        $granted = $this->applyRules($granted, $this->roleRules($membership['role_key'] ?? 'MEMBER', $companyId));
        $granted = $this->applyRules($granted, $this->userRules($identity->id, $companyId));

        // --- The mask. Applied LAST, on purpose. ---------------------------
        $capabilityMask = [
            PermissionCatalog::SAFE_SHARE         => $allowSafeShare,
            PermissionCatalog::BROWSER_TAB_SHARE  => $allowBrowserTab,
            PermissionCatalog::WINDOW_SHARE       => $allowApplicationWindow,
            PermissionCatalog::MONITOR_SHARE      => $allowEntireMonitor,
            PermissionCatalog::MICROPHONE_SHARE   => $allowMicrophone,
            PermissionCatalog::SYSTEM_AUDIO_SHARE => $allowSystemAudio,
            PermissionCatalog::CHAT_USE           => $allowTextChat,
            PermissionCatalog::ANNOTATION_USE     => $allowAnnotation,
            PermissionCatalog::FILE_SEND          => $allowFileTransfer,
            PermissionCatalog::FILE_RECEIVE       => $allowFileTransfer,
            PermissionCatalog::EXTERNAL_INVITE    => $allowExternalGuest,
            PermissionCatalog::SUPPORT_REQUEST    => $allowAicountlySupport,
            PermissionCatalog::RECORDING_START    => $allowRecording,
            PermissionCatalog::AUDIT_VIEW         => (bool) $entitlement['advanced_audit'],
        ];

        foreach ($capabilityMask as $permission => $capable) {
            if (! $capable) {
                $granted[$permission] = false;
            }
        }

        // A user who may share no surface at all cannot share a screen.
        if (! $allowSafeShare && ! $allowBrowserTab && ! $allowApplicationWindow && ! $allowEntireMonitor) {
            $granted[PermissionCatalog::SCREEN_SHARE] = false;
        }

        // Company administration is meaningless outside a company.
        if ($companyId === null) {
            foreach ([
                PermissionCatalog::POLICY_VIEW,
                PermissionCatalog::POLICY_MANAGE,
                PermissionCatalog::SESSION_HISTORY_COMPANY,
                PermissionCatalog::AUDIT_VIEW,
            ] as $companyOnly) {
                $granted[$companyOnly] = false;
            }
        }

        // Remote switched off removes everything, including plain access.
        if (! $remoteEnabled) {
            $granted = array_fill_keys(PermissionCatalog::all(), false);
        }

        return new EffectivePolicy(
            $remoteEnabled,
            $scopeType,
            $companyId,
            $companyId !== null ? $this->companyName($companyId) : null,
            (string) $policyRow['policy_preset'],
            $allowSafeShare,
            $allowBrowserTab,
            $allowApplicationWindow,
            $allowEntireMonitor,
            $allowMicrophone,
            $allowSystemAudio,
            $allowTextChat,
            $allowAnnotation,
            $allowFileTransfer,
            $allowExternalGuest,
            $allowInternalSessions,
            $allowAicountlySupport,
            $allowRecording,
            (bool) $policyRow['recording_requires_consent'],
            $maxDuration,
            (int) $policyRow['guest_link_expiry_minutes'],
            $granted,
            array_values(array_unique($restrictions)),
        );
    }

    /**
     * Every permission, defaulted false, then the baseline for this person's
     * standing in this scope switched on.
     *
     * @param  array<string, mixed>|null $membership
     * @return array<string, bool>
     */
    private function baselinePermissions(RemoteIdentity $identity, ?array $membership, string $scopeType): array
    {
        $granted = array_fill_keys(PermissionCatalog::all(), false);

        $baseline = PermissionCatalog::baselineMember();

        if ($membership !== null && (bool) $membership['is_company_admin']) {
            $baseline = PermissionCatalog::baselineCompanyAdmin();
        }

        if ($identity->isSupportAgent) {
            $baseline = array_values(array_unique(array_merge($baseline, PermissionCatalog::baselineSupportAgent())));
        }

        // A platform administrator can read policy and audit anywhere, but
        // gets no additional *sharing* rights: those still come from policy.
        if ($identity->isPlatformAdmin && $scopeType !== 'PERSONAL') {
            $baseline = array_values(array_unique(array_merge($baseline, [
                PermissionCatalog::POLICY_VIEW,
                PermissionCatalog::SESSION_HISTORY_COMPANY,
                PermissionCatalog::AUDIT_VIEW,
            ])));
        }

        foreach ($baseline as $permission) {
            $granted[$permission] = true;
        }

        return $granted;
    }

    /**
     * @param  array<string, bool>                              $granted
     * @param  list<array{permission: string, effect: string}>  $rules
     * @return array<string, bool>
     */
    private function applyRules(array $granted, array $rules): array
    {
        foreach ($rules as $rule) {
            $permission = $rule['permission'];
            if (! array_key_exists($permission, $granted)) {
                continue; // A permission name that is no longer in the catalog.
            }
            $granted[$permission] = $rule['effect'] === 'ALLOW';
        }

        return $granted;
    }

    /**
     * Platform-wide rules for the role first, then the company's own overrides,
     * so a company can tighten (or loosen, within its policy) the default.
     *
     * @return list<array{permission: string, effect: string}>
     */
    private function roleRules(string $roleKey, ?int $companyId): array
    {
        $builder = $this->db->table('remote_role_permissions')
            ->select('permission, effect')
            ->where('role_key', $roleKey);

        if ($companyId === null) {
            $builder->where('company_id', null);
        } else {
            $builder->groupStart()->where('company_id', null)->orWhere('company_id', $companyId)->groupEnd();
        }

        // NULLS FIRST is the point: the platform-wide rule is applied first and
        // the company's own row is applied after it, so the company wins.
        // Postgres sorts NULLs last in ASC by default, which would invert that.
        return $builder->orderBy('company_id ASC NULLS FIRST', '', false)->get()->getResultArray();
    }

    /** @return list<array{permission: string, effect: string}> */
    private function userRules(int $userId, ?int $companyId): array
    {
        $builder = $this->db->table('remote_user_permissions')
            ->select('permission, effect')
            ->where('user_id', $userId);

        if ($companyId === null) {
            $builder->where('company_id', null);
        } else {
            $builder->groupStart()->where('company_id', null)->orWhere('company_id', $companyId)->groupEnd();
        }

        return $builder->orderBy('company_id ASC NULLS FIRST', '', false)->get()->getResultArray();
    }

    /** @return array<string, mixed>|null */
    public function membership(int $userId, int $companyId): ?array
    {
        $row = $this->db->table('remote_user_company_access')
            ->select('user_id, company_id, branch_id, financial_year_id, role_key, is_company_admin')
            ->where('user_id', $userId)
            ->where('company_id', $companyId)
            ->get()
            ->getRowArray();

        if ($row === null) {
            return null;
        }

        $row['is_company_admin'] = $this->truthy($row['is_company_admin']);

        return $row;
    }

    /**
     * The company's stored policy, provisioning the conservative default the
     * first time a company is seen.
     *
     * Lazy provisioning is deliberate: Remote has no company onboarding hook,
     * so the alternative is either "no row means no policy" (unsafe) or a
     * nightly sync of every AICOUNTLY company (wasteful).
     *
     * @return array<string, mixed>
     */
    public function companyPolicy(int $companyId): array
    {
        $row = $this->db->table('remote_company_policies')->where('company_id', $companyId)->get()->getRowArray();

        if ($row === null) {
            $defaults = CompanyPolicyDefaults::forPreset('STANDARD');
            $this->db->query(
                'INSERT INTO remote_company_policies (company_id, policy_preset) VALUES (?, ?) ON CONFLICT (company_id) DO NOTHING',
                [$companyId, $defaults['policy_preset']],
            );
            $row = $this->db->table('remote_company_policies')->where('company_id', $companyId)->get()->getRowArray();
        }

        return $this->castPolicyRow($row ?? CompanyPolicyDefaults::forPreset('STANDARD'));
    }

    /**
     * Postgres hands booleans back as 't'/'f' strings through PDO in some
     * configurations; normalising once here keeps every caller honest.
     *
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function castPolicyRow(array $row): array
    {
        foreach (CompanyPolicyDefaults::TOGGLES as $toggle) {
            $row[$toggle] = $this->truthy($row[$toggle] ?? false);
        }
        $row['max_session_duration_minutes'] = (int) ($row['max_session_duration_minutes'] ?? 60);
        $row['guest_link_expiry_minutes']    = (int) ($row['guest_link_expiry_minutes'] ?? 10);
        $row['policy_preset']                = (string) ($row['policy_preset'] ?? 'STANDARD');

        return $row;
    }

    /**
     * The company's entitlement, or the platform default row, or — if neither
     * exists — a conservative built-in.
     *
     * @return array<string, mixed>
     */
    public function entitlement(?int $companyId): array
    {
        $row = null;

        if ($companyId !== null) {
            $row = $this->db->table('remote_entitlements')
                ->where('company_id', $companyId)
                ->groupStart()->where('valid_until', null)->orWhere('valid_until >', 'NOW()', false)->groupEnd()
                ->get()
                ->getRowArray();
        }

        $row ??= $this->db->table('remote_entitlements')->where('company_id', null)->get()->getRowArray();

        $row ??= [
            'plan_code'                    => 'REMOTE_FREE',
            'max_monthly_sessions'         => null,
            'max_session_duration_minutes' => null,
            'external_guests'              => false,
            'recording'                    => false,
            'file_transfer'                => true,
            'advanced_audit'               => true,
            'desktop_devices'              => false,
            'unattended_access'            => false,
        ];

        foreach (['external_guests', 'recording', 'file_transfer', 'advanced_audit', 'desktop_devices', 'unattended_access'] as $flag) {
            $row[$flag] = $this->truthy($row[$flag] ?? false);
        }

        return $row;
    }

    public function companyName(int $companyId): ?string
    {
        $row = $this->db->table('remote_company_directory')
            ->select('name')
            ->where('company_id', $companyId)
            ->get()
            ->getRowArray();

        return $row === null ? null : (string) $row['name'];
    }

    private function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }
}
