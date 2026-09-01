<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Domain\Audit\EventType;
use App\Domain\Policy\CompanyPolicyDefaults;
use App\Domain\Policy\PermissionCatalog;
use App\Domain\Support\ApiException;
use App\Domain\Support\Clock;
use App\Domain\Support\Presenter;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * Company administration: policy, role and user permissions, audit (§40–§42).
 *
 * Every method here resolves the caller's policy **for the company in the URL**
 * and checks the matching permission. Holding `remote.policy.manage` at one
 * company grants nothing at another — which is the property the isolation tests
 * exercise directly (§77).
 */
class AdminController extends BaseApiController
{
    // ---------------------------------------------------------------- policy

    /** `GET /company/{companyId}/policy` */
    public function showPolicy(string $companyId): ResponseInterface
    {
        $id     = $this->companyId($companyId);
        $policy = $this->requirePermission($id, PermissionCatalog::POLICY_VIEW);

        $row = Services::policyResolver()->companyPolicy($id);

        return $this->ok([
            'policy'     => Presenter::companyPolicy($row),
            'presets'    => CompanyPolicyDefaults::PRESETS,
            'entitlement' => $this->entitlementResource($id),
            'companyName' => $policy->companyName,
        ]);
    }

    /**
     * `PUT /company/{companyId}/policy`
     *
     * Accepts either a `preset` — which writes that preset's values wholesale —
     * or individual switches. After the write the stored row is compared back
     * against the presets so the label the administrator sees stays true: a
     * STANDARD policy with one switch flipped is CUSTOM, and says so.
     */
    public function updatePolicy(string $companyId): ResponseInterface
    {
        $id = $this->companyId($companyId);
        $this->requirePermission($id, PermissionCatalog::POLICY_MANAGE);

        $body    = $this->body();
        $current = Services::policyResolver()->companyPolicy($id);

        $update = [];

        if (isset($body['preset']) && is_string($body['preset'])) {
            $preset = strtoupper($body['preset']);
            if (! in_array($preset, CompanyPolicyDefaults::PRESETS, true)) {
                throw ApiException::badRequest('PRESET_INVALID', 'That is not a Remote policy preset.');
            }
            if ($preset !== 'CUSTOM') {
                $update = CompanyPolicyDefaults::forPreset($preset);
                unset($update['policy_preset']);
            }
        }

        foreach (CompanyPolicyDefaults::TOGGLES as $toggle) {
            $key = $this->camel($toggle);
            if (array_key_exists($key, $body)) {
                $update[$toggle] = $this->boolean($body, $key);
            }
        }

        if (array_key_exists('maxSessionDurationMinutes', $body)) {
            $minutes = $this->optionalInt($body, 'maxSessionDurationMinutes') ?? 60;
            $update['max_session_duration_minutes'] = max(5, min(1440, $minutes));
        }

        if (array_key_exists('guestLinkExpiryMinutes', $body)) {
            $minutes = $this->optionalInt($body, 'guestLinkExpiryMinutes') ?? 10;
            $update['guest_link_expiry_minutes'] = max(1, min(1440, $minutes));
        }

        if ($update === []) {
            return $this->ok(['policy' => Presenter::companyPolicy($current)]);
        }

        $merged = array_merge($current, $update);

        // Turning Remote off means off: leaving sharing switches on would
        // violate the table's own CHECK, and would be a confusing thing to
        // store even if it did not.
        if (! (bool) $merged['remote_enabled']) {
            foreach (['allow_safe_share', 'allow_browser_tab', 'allow_application_window', 'allow_entire_monitor'] as $surface) {
                $update[$surface] = false;
                $merged[$surface] = false;
            }
        }

        $update['policy_preset'] = CompanyPolicyDefaults::detectPreset($merged);
        $update['updated_by']    = $this->identity()->id;
        $update['updated_at']    = Clock::now();

        db_connect()->table('remote_company_policies')->where('company_id', $id)->update($update);

        $saved = Services::policyResolver()->companyPolicy($id);

        // What changed, not the whole row: an audit entry should answer "who
        // turned entire-screen sharing on?" without being a diff to read.
        $changed = [];
        foreach ($update as $column => $value) {
            if ($column === 'updated_at' || $column === 'updated_by') {
                continue;
            }
            if (($current[$column] ?? null) !== $value) {
                $changed[$column] = ['from' => $current[$column] ?? null, 'to' => $value];
            }
        }

        Services::auditService()->recordAudit(
            EventType::POLICY_UPDATED,
            $this->identity()->id,
            'USER',
            $id,
            null,
            null,
            null,
            ['changes' => $changed],
        );

        return $this->ok(['policy' => Presenter::companyPolicy($saved)]);
    }

    // ----------------------------------------------------------- permissions

    /**
     * `GET /company/{companyId}/permissions`
     *
     * The permission matrix the administration screen renders: every user the
     * projection knows about in this company, with their effective answer for
     * each permission and the explicit overrides behind it.
     */
    public function listPermissions(string $companyId): ResponseInterface
    {
        $id = $this->companyId($companyId);
        $this->requirePermission($id, PermissionCatalog::POLICY_VIEW);

        $db       = db_connect();
        $search   = trim((string) ($this->request->getGet('search') ?? ''));
        $limit    = min(max((int) ($this->request->getGet('limit') ?? 25), 1), 100);
        $offset   = max((int) ($this->request->getGet('offset') ?? 0), 0);

        $builder = $db->table('remote_user_company_access a')
            ->select('a.user_id, a.role_key, a.is_company_admin, i.platform_uuid, i.display_name, i.email')
            ->join('remote_identities i', 'i.id = a.user_id')
            ->where('a.company_id', $id);

        if ($search !== '') {
            $builder->groupStart()
                ->like('i.display_name', $search)
                ->orLike('i.email', $search)
                ->groupEnd();
        }

        $total = (clone $builder)->countAllResults(false);
        $rows  = $builder->orderBy('i.display_name', 'ASC')->limit($limit, $offset)->get()->getResultArray();

        $resolver = Services::policyResolver();
        $identities = Services::identityResolver();

        $users = [];
        foreach ($rows as $row) {
            $identity = $identities->findById((int) $row['user_id']);
            if ($identity === null) {
                continue;
            }

            $effective = $resolver->resolve($identity, 'COMPANY', $id);

            $overrides = $db->table('remote_user_permissions')
                ->select('permission, effect')
                ->where('user_id', (int) $row['user_id'])
                ->groupStart()->where('company_id', $id)->orWhere('company_id', null)->groupEnd()
                ->get()
                ->getResultArray();

            $users[] = [
                'userUuid'       => (string) $row['platform_uuid'],
                'displayName'    => (string) $row['display_name'],
                'email'          => $row['email'],
                'roleKey'        => (string) $row['role_key'],
                'isCompanyAdmin' => Presenter::bool($row['is_company_admin']),
                'permissions'    => $effective->permissions,
                'overrides'      => array_column($overrides, 'effect', 'permission'),
            ];
        }

        return $this->ok([
            'users'   => $users,
            'catalog' => PermissionCatalog::groups(),
        ], ['total' => $total]);
    }

    /**
     * `PUT /company/{companyId}/permissions/{userUuid}`
     *
     * Writes explicit user-level overrides. It cannot widen anything: the
     * resolver applies the company mask *after* these rows, so an ALLOW written
     * here for a capability the company has switched off simply has no effect —
     * and the response says so by returning the recomputed effective set (§11).
     */
    public function updateUserPermissions(string $companyId, string $userUuid): ResponseInterface
    {
        $id = $this->companyId($companyId);
        $this->requirePermission($id, PermissionCatalog::POLICY_MANAGE);

        $db       = db_connect();
        $resolver = Services::policyResolver();

        $target = $db->table('remote_identities')->where('platform_uuid', $userUuid)->get()->getRowArray();
        if ($target === null || $resolver->membership((int) $target['id'], $id) === null) {
            throw ApiException::notFound('That person is not part of this organisation in AICOUNTLY.');
        }

        $body    = $this->body();
        $changes = $body['permissions'] ?? [];
        if (! is_array($changes)) {
            throw ApiException::badRequest('VALIDATION_FAILED', 'Send a map of permission names to ALLOW, DENY or INHERIT.');
        }

        $applied = [];

        $db->transException(true)->transStart();

        foreach ($changes as $permission => $effect) {
            if (! is_string($permission) || ! PermissionCatalog::isValid($permission)) {
                throw ApiException::badRequest('PERMISSION_UNKNOWN', 'That is not a Remote permission.', [
                    'permission' => is_string($permission) ? $permission : null,
                ]);
            }

            $effect = strtoupper((string) $effect);

            if ($effect === 'INHERIT' || $effect === '') {
                $db->table('remote_user_permissions')
                    ->where('user_id', (int) $target['id'])
                    ->where('company_id', $id)
                    ->where('permission', $permission)
                    ->delete();
                $applied[$permission] = 'INHERIT';

                continue;
            }

            if (! in_array($effect, ['ALLOW', 'DENY'], true)) {
                throw ApiException::badRequest('EFFECT_INVALID', 'A permission may be ALLOW, DENY or INHERIT.');
            }

            $db->query(
                <<<'SQL'
                    INSERT INTO remote_user_permissions (company_id, user_id, permission, effect, created_by, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                    ON CONFLICT (COALESCE(company_id, 0), user_id, permission) DO UPDATE
                        SET effect = EXCLUDED.effect, updated_at = NOW()
                    SQL,
                [$id, (int) $target['id'], $permission, $effect, $this->identity()->id],
            );

            $applied[$permission] = $effect;
        }

        $db->transComplete();

        Services::auditService()->recordAudit(
            EventType::PERMISSION_UPDATED,
            $this->identity()->id,
            'USER',
            $id,
            null,
            null,
            null,
            ['targetUserUuid' => $userUuid, 'changes' => $applied],
        );

        $identity  = Services::identityResolver()->findById((int) $target['id']);
        $effective = $identity !== null ? $resolver->resolve($identity, 'COMPANY', $id)->permissions : [];

        return $this->ok([
            'userUuid'    => $userUuid,
            'overrides'   => $applied,
            'permissions' => $effective,
        ]);
    }

    /** `GET /company/{companyId}/role-permissions` */
    public function listRolePermissions(string $companyId): ResponseInterface
    {
        $id = $this->companyId($companyId);
        $this->requirePermission($id, PermissionCatalog::POLICY_VIEW);

        $rows = db_connect()->table('remote_role_permissions')
            ->select('role_key, permission, effect, company_id')
            ->groupStart()->where('company_id', $id)->orWhere('company_id', null)->groupEnd()
            ->orderBy('role_key', 'ASC')
            ->get()
            ->getResultArray();

        $roles = [];
        foreach ($rows as $row) {
            $roles[$row['role_key']][$row['permission']] = [
                'effect'      => $row['effect'],
                'inheritedFromPlatform' => $row['company_id'] === null,
            ];
        }

        return $this->ok(['roles' => $roles, 'catalog' => PermissionCatalog::groups()]);
    }

    /** `PUT /company/{companyId}/role-permissions/{roleKey}` */
    public function updateRolePermissions(string $companyId, string $roleKey): ResponseInterface
    {
        $id = $this->companyId($companyId);
        $this->requirePermission($id, PermissionCatalog::POLICY_MANAGE);

        $roleKey = mb_substr(trim($roleKey), 0, 64);
        if ($roleKey === '') {
            throw ApiException::badRequest('ROLE_INVALID', 'A role key is required.');
        }

        $changes = $this->body()['permissions'] ?? [];
        if (! is_array($changes)) {
            throw ApiException::badRequest('VALIDATION_FAILED', 'Send a map of permission names to ALLOW, DENY or INHERIT.');
        }

        $db      = db_connect();
        $applied = [];

        $db->transException(true)->transStart();

        foreach ($changes as $permission => $effect) {
            if (! is_string($permission) || ! PermissionCatalog::isValid($permission)) {
                throw ApiException::badRequest('PERMISSION_UNKNOWN', 'That is not a Remote permission.');
            }

            $effect = strtoupper((string) $effect);

            if ($effect === 'INHERIT' || $effect === '') {
                $db->table('remote_role_permissions')
                    ->where('company_id', $id)
                    ->where('role_key', $roleKey)
                    ->where('permission', $permission)
                    ->delete();
                $applied[$permission] = 'INHERIT';

                continue;
            }

            if (! in_array($effect, ['ALLOW', 'DENY'], true)) {
                throw ApiException::badRequest('EFFECT_INVALID', 'A permission may be ALLOW, DENY or INHERIT.');
            }

            $db->query(
                <<<'SQL'
                    INSERT INTO remote_role_permissions (company_id, role_key, permission, effect, created_by, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                    ON CONFLICT (COALESCE(company_id, 0), role_key, permission) DO UPDATE
                        SET effect = EXCLUDED.effect, updated_at = NOW()
                    SQL,
                [$id, $roleKey, $permission, $effect, $this->identity()->id],
            );

            $applied[$permission] = $effect;
        }

        $db->transComplete();

        Services::auditService()->recordAudit(
            EventType::PERMISSION_UPDATED,
            $this->identity()->id,
            'USER',
            $id,
            null,
            null,
            null,
            ['roleKey' => $roleKey, 'changes' => $applied],
        );

        return $this->ok(['roleKey' => $roleKey, 'overrides' => $applied]);
    }

    // ----------------------------------------------------------------- audit

    /**
     * `GET /company/{companyId}/audit`
     *
     * Gated on `remote.audit.view`, which is also what unlocks IP addresses and
     * user agents elsewhere in the API. An ordinary user never sees either
     * (§42).
     */
    public function audit(string $companyId): ResponseInterface
    {
        $id = $this->companyId($companyId);
        $this->requirePermission($id, PermissionCatalog::AUDIT_VIEW);

        $limit  = min(max((int) ($this->request->getGet('limit') ?? 50), 1), 200);
        $offset = max((int) ($this->request->getGet('offset') ?? 0), 0);

        $builder = db_connect()->table('remote_audit_logs a')
            ->select('a.*, i.display_name AS actor_name')
            ->join('remote_identities i', 'i.id = a.actor_user_id', 'left')
            ->where('a.company_id', $id);

        if (($event = $this->request->getGet('event')) !== null && $event !== '') {
            $builder->where('a.event', strtoupper((string) $event));
        }
        if (($from = $this->request->getGet('from')) !== null && $from !== '') {
            $builder->where('a.created_at >=', $from);
        }
        if (($to = $this->request->getGet('to')) !== null && $to !== '') {
            $builder->where('a.created_at <=', $to);
        }
        if (($sessionUuid = $this->request->getGet('sessionUuid')) !== null && $sessionUuid !== '') {
            $builder->where('a.session_uuid', $sessionUuid);
        }

        $total = (clone $builder)->countAllResults(false);
        $rows  = $builder->orderBy('a.created_at', 'DESC')->limit($limit, $offset)->get()->getResultArray();

        return $this->ok(
            array_map(static fn (array $row) => Presenter::auditEntry($row), $rows),
            ['total' => $total],
        );
    }

    // ------------------------------------------------------------- internals

    private function companyId(string $raw): int
    {
        if (! ctype_digit($raw) || (int) $raw <= 0) {
            throw ApiException::notFound('That organisation could not be found.');
        }

        return (int) $raw;
    }

    /**
     * Resolve the caller's policy for *this* company and require a permission.
     *
     * `resolve()` throws COMPANY_ACCESS_DENIED for a non-member before any
     * permission is even looked at, which is the isolation boundary.
     */
    private function requirePermission(int $companyId, string $permission)
    {
        $policy = Services::policyResolver()->resolve($this->identity(), 'COMPANY', $companyId);

        if (! $policy->can($permission)) {
            throw ApiException::forbidden(
                'ADMIN_PERMISSION_DENIED',
                'You do not have permission to manage AICOUNTLY Remote for this organisation.',
                ['permission' => $permission],
            );
        }

        return $policy;
    }

    /** @return array<string, mixed> */
    private function entitlementResource(int $companyId): array
    {
        $entitlement = Services::policyResolver()->entitlement($companyId);

        return [
            'planCode'                 => (string) $entitlement['plan_code'],
            'maxMonthlySessions'       => $entitlement['max_monthly_sessions'] !== null ? (int) $entitlement['max_monthly_sessions'] : null,
            'maxSessionDurationMinutes' => $entitlement['max_session_duration_minutes'] !== null ? (int) $entitlement['max_session_duration_minutes'] : null,
            'externalGuests'           => (bool) $entitlement['external_guests'],
            'recording'                => (bool) $entitlement['recording'],
            'fileTransfer'             => (bool) $entitlement['file_transfer'],
            'advancedAudit'            => (bool) $entitlement['advanced_audit'],
            'desktopDevices'           => (bool) $entitlement['desktop_devices'],
            'unattendedAccess'         => (bool) $entitlement['unattended_access'],
        ];
    }

    private function camel(string $snake): string
    {
        return lcfirst(str_replace('_', '', ucwords($snake, '_')));
    }
}
