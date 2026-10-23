<?php

declare(strict_types=1);

namespace App\Domain\Directory;

use App\Domain\Policy\PlanEntitlements;
use App\Libraries\CompanyLifecycle;
use Config\Remote as RemoteConfig;

/**
 * Asks Aicountly Manage who belongs to a company, with the caller's own session.
 *
 * Manage owns company membership. Remote keeps a projection of it
 * (`remote_user_company_access`) and, until G28#4, only ever added to it, so a
 * person removed from a company stayed inside its Remote sessions for ever.
 * Everything here is a *question put to Manage on a person's behalf*: the
 * `ses_key` is the caller's own, never a service credential, so Manage answers
 * exactly as it would in its own UI.
 *
 * Contract (manage-aicountly `server-php/public/index.php`):
 *
 *   GET /api/companyinfo?comp_id=&app=remote   404 "not found or access denied"
 *        unless the session has an access row; 403 COMPANY_ACCESS_REQUIRED when
 *        the person may see the company but not open it in Remote (company
 *        registry, per-application access); otherwise the company with
 *        `ownership` and `access_type`.
 *   GET /api/companies?filter=all&app=remote   the companies the session may
 *        open, paged; each row carries `can_open_in_current_app` for Remote.
 *
 * Both calls name Remote (`app=remote`, its Manage catalog key) so that Manage
 * answers for Remote and not merely for company membership.
 *   GET /api/companies/{id}/share   the member directory (uuid, display_name,
 *        email, is_owner) — answered to any member, 403 to anyone else.
 *
 * Three outcomes, kept apart on purpose: GRANTED, DENIED, and UNAVAILABLE. A
 * refusal removes access; an outage removes nothing and grants nothing.
 */
class ManageClient
{
    public const GRANTED     = 'granted';
    public const DENIED      = 'denied';
    public const UNAVAILABLE = 'unavailable';

    private const CONNECT_TIMEOUT_SECONDS = 3;
    private const REQUEST_TIMEOUT_SECONDS = 8;
    private const PAGE_SIZE               = 100;
    private const MAX_PAGES               = 10;

    /** Remote's Manage catalog key (company registry, per-application access). */
    public const APP_KEY = 'remote';

    public function __construct(private readonly RemoteConfig $config)
    {
    }

    /**
     * Does Manage still say this session belongs to this company?
     *
     * @return array{state: string, name: ?string}
     */
    public function companyAccess(string $sesKey, int $companyId): array
    {
        $result = $this->get('companyinfo?comp_id=' . $companyId . '&app=' . self::APP_KEY, $sesKey);
        $answer = self::classifyCompanyInfo($result['status'], $result['body'], $companyId);

        // companyinfo carries Manage's company lifecycle (`lifecycle`, `restricted_until`), the source of
        // truth: written through, so a lifecycle event Remote missed is corrected. Never throws.
        CompanyLifecycle::syncFromCompanyInfo($companyId, (int) $result['status'], (string) $result['body']);

        // The company's plan for Remote (CONTRACTS §3.5): [said something about plans, the plan].
        [$planKnown, $plan] = PlanEntitlements::fromCompanyInfoBody((string) $result['body']);

        if ($answer['state'] !== 'unevaluated') {
            return ['state' => $answer['state'], 'name' => $answer['name'], 'planKnown' => $planKnown, 'plan' => $plan];
        }

        // A host whose companyinfo does not say how the session relates to the
        // company: fall back to the list of companies the session may open.
        $list = $this->accessibleCompanies($sesKey);
        if ($list === null) {
            return ['state' => self::UNAVAILABLE, 'name' => null];
        }

        return isset($list[$companyId])
            ? ['state' => self::GRANTED, 'name' => $list[$companyId] !== '' ? $list[$companyId] : $answer['name'], 'planKnown' => $planKnown, 'plan' => $plan]
            : ['state' => self::DENIED, 'name' => null, 'planKnown' => false, 'plan' => null];
    }

    /**
     * The decision behind {@see companyAccess()}, on a raw Manage answer.
     *
     * 403 and 404 are Manage saying no (404 is how it says "not found or access
     * denied"). A 401 is not: the portal has just vouched for this session, so a
     * Manage that does not know it says nothing about the company, and removing
     * someone's access on it would turn a session hiccup into a membership
     * change. A 200 counts only when it says how the session relates to the
     * company; a timeout, 5xx, 429 or an unreadable body is an outage.
     *
     * @return array{state: string, name: ?string}  state is also 'unevaluated'
     */
    public static function classifyCompanyInfo(int $status, string $body, int $companyId): array
    {
        if ($status === 403 || $status === 404) {
            return ['state' => self::DENIED, 'name' => null];
        }
        if ($status !== 200 || trim($body) === '') {
            return ['state' => self::UNAVAILABLE, 'name' => null];
        }

        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            return ['state' => self::UNAVAILABLE, 'name' => null];
        }

        $data = $decoded['data'] ?? $decoded['company'] ?? $decoded;
        if (! is_array($data)) {
            return ['state' => self::UNAVAILABLE, 'name' => null];
        }

        $reported = (int) ($data['comp_id'] ?? $data['cmp_id'] ?? $companyId);
        if ($reported !== $companyId) {
            return ['state' => self::UNAVAILABLE, 'name' => null];
        }

        $name = trim((string) ($data['comp_name'] ?? $data['print_name'] ?? $data['name'] ?? ''));
        $name = $name !== '' ? $name : null;

        if (isset($data['ownership']) || isset($data['access_type']) || isset($data['acs_type'])) {
            return ['state' => self::GRANTED, 'name' => $name];
        }

        return ['state' => 'unevaluated', 'name' => $name];
    }

    /**
     * Every company this session may open in Remote, as company id => name.
     *
     * A row Manage marks `can_open_in_current_app: false` (the person may see
     * the company but not open it in Remote) is left out — denied, fail closed.
     * A row without the field (a Manage that predates per-application access)
     * counts as before.
     *
     * @return array<int, string>|null null when Manage could not be asked
     */
    public function accessibleCompanies(string $sesKey): ?array
    {
        $companies = [];
        $listed    = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $result = $this->get(
                'companies?filter=all&app=' . self::APP_KEY . '&page=' . $page . '&per_page=' . self::PAGE_SIZE,
                $sesKey,
            );
            if ($result['status'] !== 200) {
                return null;
            }

            $body = json_decode($result['body'], true);
            if (! is_array($body)) {
                return null;
            }
            $rows = $body['data'] ?? $body['companies'] ?? [];
            if (! is_array($rows)) {
                return null;
            }

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $id = (int) ($row['comp_id'] ?? $row['cmp_id'] ?? $row['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $listed[$id] = true;
                if (($row['can_open_in_current_app'] ?? null) === false) {
                    continue; // visible to the person, not openable in Remote
                }
                $companies[$id] = trim((string) ($row['company_name'] ?? $row['comp_name'] ?? $row['name'] ?? ''));
            }

            $total = (int) ($body['total'] ?? 0);
            if (count($rows) < self::PAGE_SIZE || ($total > 0 && count($listed) >= $total)) {
                break;
            }
        }

        return $companies;
    }

    /**
     * Every company Manage lets this session open in Remote — a share, an approved request, a creator's
     * access and a workspace owner's "every company" access (finding F-2): Manage's company directory,
     * `GET /api/v1/company-directory?app=remote&access=granted`, paged. A row Manage marks not openable in
     * Remote is left out. An older Manage without the directory (404 on the first page) is asked the
     * company list instead ({@see accessibleCompanies}), which knows shares only. Null when Manage could not
     * answer, so the caller changes nothing.
     *
     * @return array<int, string>|null company id => name
     */
    public function openableCompanies(string $sesKey): ?array
    {
        $companies = [];
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $result = $this->get(
                'v1/company-directory?app=' . self::APP_KEY . '&access=granted&page=' . $page . '&per_page=' . self::PAGE_SIZE,
                $sesKey,
            );
            if ($result['status'] === 404 && $page === 1) {
                return $this->accessibleCompanies($sesKey);
            }
            if ($result['status'] !== 200) {
                return null;
            }
            $body = json_decode($result['body'], true);
            $rows = is_array($body) ? ($body['data'] ?? null) : null;
            if (! is_array($rows) || ! array_is_list($rows)) {
                return null;
            }
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $id = (int) ($row['cmp_id'] ?? $row['canonical_company_id'] ?? 0);
                if ($id <= 0 || ($row['can_open_in_current_app'] ?? true) === false) {
                    continue;
                }
                $companies[$id] = trim((string) ($row['display_name'] ?? $row['legal_name'] ?? ''));
            }
            $pages = (int) ($body['meta']['total_pages'] ?? 1);
            if (count($rows) < self::PAGE_SIZE || $page >= $pages) {
                break;
            }
        }

        return $companies;
    }

    /**
     * The company's member directory, per Manage, now.
     *
     * @return array{state: string, members: list<array{uuid: string, name: string, email: ?string}>}
     */
    public function companyMembers(string $sesKey, int $companyId): array
    {
        $result = $this->get('companies/' . $companyId . '/share', $sesKey);
        if ($result['status'] === 403 || $result['status'] === 404) {
            return ['state' => self::DENIED, 'members' => []];
        }
        if ($result['status'] !== 200) {
            return ['state' => self::UNAVAILABLE, 'members' => []];
        }

        $body = json_decode($result['body'], true);
        $rows = is_array($body) ? ($body['data'] ?? null) : null;
        if (! is_array($rows) || ! array_is_list($rows)) {
            return ['state' => self::UNAVAILABLE, 'members' => []];
        }

        $members = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $uuid = trim((string) ($row['uuid'] ?? $row['platform_user_uuid'] ?? ''));
            if ($uuid === '') {
                continue;
            }
            $email = trim((string) ($row['email'] ?? ''));
            $members[] = [
                'uuid'  => $uuid,
                'name'  => trim((string) ($row['display_name'] ?? $row['name'] ?? '')),
                'email' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null,
            ];
        }

        return ['state' => self::GRANTED, 'members' => $members];
    }

    /** @return array{status: int, body: string} status 0 when nothing answered */
    protected function get(string $path, string $sesKey): array
    {
        $ch = curl_init(rtrim($this->config->manageBase, '/') . '/api/' . ltrim($path, '/'));
        if ($ch === false) {
            return ['status' => 0, 'body' => ''];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Authorization: Bearer ' . $sesKey],
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT_SECONDS,
        ]);

        $response = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return $response === false ? ['status' => 0, 'body' => ''] : ['status' => $status, 'body' => (string) $response];
    }
}
