<?php

declare(strict_types=1);

namespace App\Domain\Directory;

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
 *   GET /api/companyinfo?comp_id=   404 "not found or access denied" unless the
 *        session has an access row; otherwise the company with `ownership` and
 *        `access_type`.
 *   GET /api/companies?filter=all   the companies the session may open, paged.
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
        $result = $this->get('companyinfo?comp_id=' . $companyId, $sesKey);
        $answer = self::classifyCompanyInfo($result['status'], $result['body'], $companyId);

        if ($answer['state'] !== 'unevaluated') {
            return ['state' => $answer['state'], 'name' => $answer['name']];
        }

        // A host whose companyinfo does not say how the session relates to the
        // company: fall back to the list of companies the session may open.
        $list = $this->accessibleCompanies($sesKey);
        if ($list === null) {
            return ['state' => self::UNAVAILABLE, 'name' => null];
        }

        return isset($list[$companyId])
            ? ['state' => self::GRANTED, 'name' => $list[$companyId] !== '' ? $list[$companyId] : $answer['name']]
            : ['state' => self::DENIED, 'name' => null];
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
     * Every company this session may open, as company id => name.
     *
     * @return array<int, string>|null null when Manage could not be asked
     */
    public function accessibleCompanies(string $sesKey): ?array
    {
        $companies = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $result = $this->get(
                'companies?filter=all&page=' . $page . '&per_page=' . self::PAGE_SIZE,
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
                if ($id > 0) {
                    $companies[$id] = trim((string) ($row['company_name'] ?? $row['comp_name'] ?? $row['name'] ?? ''));
                }
            }

            $total = (int) ($body['total'] ?? 0);
            if (count($rows) < self::PAGE_SIZE || ($total > 0 && count($companies) >= $total)) {
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
