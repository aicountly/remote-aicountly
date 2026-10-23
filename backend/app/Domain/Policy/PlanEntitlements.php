<?php

declare(strict_types=1);

namespace App\Domain\Policy;

use App\Domain\Support\ApiException;
use CodeIgniter\Database\BaseConnection;

/**
 * The company's plan for Remote (CONTRACTS §3.5/§3.6). Remote is `remote` in Manage.
 *
 * Manage's companyinfo, asked with app=remote whenever Remote confirms a person's membership
 * (MembershipVerifier, at most every remote.membershipVerifySeconds), carries
 * `plan: {code, label, is_default, status, ends_at, entitlements}`; ManageClient::companyAccess() hands
 * it on and MembershipVerifier keeps it in remote_company_directory.plan. Entitlements arrive as an
 * object {"<code>": value} or a list [{code, value}], both accepted. No plan (an older Manage, or not
 * asked yet), no entitlements, an absent code or a value that is not a whole number >= 0 = no limit.
 *
 *   remote.max_sessions_per_month   sessions the company started this calendar month (UTC), checked
 *                                   before a new session (SessionService)
 *   remote.max_devices              registered computers (pending, active, suspended — not revoked),
 *                                   checked before a new device is enrolled (DeviceService)
 *
 * Over the limit: 402 PLAN_LIMIT_REACHED {code, limit, used} in Remote's usual {"error":{…}} envelope.
 * Remote's own remote_entitlements row (403 SESSION_QUOTA_REACHED) and the hard device ceiling still
 * apply on top, unchanged. Joining, ending, history, audit, revoking a device are never refused; nothing
 * is deleted. Personal sessions (no company) are not held to a Manage plan here.
 */
final class PlanEntitlements
{
    public const APP_KEY = 'remote';
    public const MAX_SESSIONS_PER_MONTH = 'remote.max_sessions_per_month';
    public const MAX_DEVICES = 'remote.max_devices';
    public const ERROR_CODE = 'PLAN_LIMIT_REACHED';

    /**
     * The plan a companyinfo body carried for Remote: [true, plan] when it said something about plans,
     * [false, null] when it did not (an older Manage) or could not be read.
     *
     * @return array{0: bool, 1: mixed}
     */
    public static function fromCompanyInfoBody(string $body): array
    {
        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            return [false, null];
        }
        $data = $decoded['data'] ?? $decoded['company'] ?? $decoded;
        if (! is_array($data) || ! array_key_exists('plan', $data)) {
            return [false, null];
        }

        return [true, $data['plan'] ?? []];
    }

    /**
     * @return array{code: string, label: string, is_default: bool, status: ?string, ends_at: ?string, entitlements: array<string, mixed>}|null
     */
    public static function normalize(mixed $plan): ?array
    {
        if (! is_array($plan) || ($plan !== [] && array_is_list($plan))) {
            return null;
        }
        $entitlements = [];
        $raw = $plan['entitlements'] ?? [];
        if (is_array($raw)) {
            if (array_is_list($raw)) {
                foreach ($raw as $e) {
                    if (is_array($e) && is_string($e['code'] ?? null) && $e['code'] !== '') {
                        $entitlements[$e['code']] = $e['value'] ?? null;
                    }
                }
            } else {
                foreach ($raw as $code => $value) {
                    if (is_string($code) && $code !== '') {
                        $entitlements[$code] = $value;
                    }
                }
            }
        }

        return [
            'code'         => is_scalar($plan['code'] ?? null) ? (string) $plan['code'] : '',
            'label'        => is_scalar($plan['label'] ?? null) ? (string) $plan['label'] : '',
            'is_default'   => ($plan['is_default'] ?? false) === true,
            'status'       => is_string($plan['status'] ?? null) ? $plan['status'] : null,
            'ends_at'      => is_string($plan['ends_at'] ?? null) ? $plan['ends_at'] : null,
            'entitlements' => $entitlements,
        ];
    }

    /** The numeric limit for $code, or null for "no limit". */
    public static function limit(?array $plan, string $code): ?int
    {
        if ($plan === null || ! is_array($plan['entitlements'] ?? null) || ! array_key_exists($code, $plan['entitlements'])) {
            return null;
        }
        $value = $plan['entitlements'][$code];
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (is_float($value) && $value >= 0 && floor($value) === $value) {
            return (int) $value;
        }
        if (is_string($value) && preg_match('/^\d{1,9}$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
    }

    /** Whether a feature flag is on: absent = on. */
    public static function allows(?array $plan, string $featureCode): bool
    {
        if ($plan === null || ! is_array($plan['entitlements'] ?? null) || ! array_key_exists($featureCode, $plan['entitlements'])) {
            return true;
        }
        $value = $plan['entitlements'][$featureCode];

        return ! ($value === false || $value === 0 || $value === '0' || $value === 'false' || $value === null);
    }

    /** The company's plan as Remote last kept it (remote_company_directory.plan), or null. */
    public static function companyPlan(BaseConnection $db, int $companyId): ?array
    {
        $row = $db->table('remote_company_directory')->select('plan')->where('company_id', $companyId)->get()->getRowArray();
        $raw = $row['plan'] ?? null;

        return is_string($raw) && $raw !== '' ? self::normalize(json_decode($raw, true)) : null;
    }

    /** Keep what Manage's companyinfo said about the company's plan (null = it sent none). */
    public static function remember(BaseConnection $db, int $companyId, mixed $plan): void
    {
        $db->query(
            'UPDATE remote_company_directory SET plan = CAST(? AS jsonb), plan_checked_at = NOW() WHERE company_id = ?',
            [$plan === null ? null : json_encode($plan, JSON_UNESCAPED_SLASHES), $companyId],
        );
    }

    /**
     * Refuse with 402 PLAN_LIMIT_REACHED when one more would pass the company plan's $code; $used is
     * the current count (asked only when there is a limit).
     *
     * @param callable(): int $used
     *
     * @throws ApiException
     */
    public static function assertRoom(BaseConnection $db, int $companyId, string $code, callable $used, string $singular, string $plural): void
    {
        $limit = self::limit(self::companyPlan($db, $companyId), $code);
        if ($limit === null) {
            return;
        }
        $count = $used();
        if ($count < $limit) {
            return;
        }

        throw new ApiException(
            self::ERROR_CODE,
            sprintf('Your plan allows %d %s — ask the account owner to upgrade in Manage.', $limit, $limit === 1 ? $singular : $plural),
            402,
            ['code' => $code, 'limit' => $limit, 'used' => $count],
        );
    }
}
