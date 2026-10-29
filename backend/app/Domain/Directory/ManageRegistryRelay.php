<?php

declare(strict_types=1);

namespace App\Domain\Directory;

use Config\Remote as RemoteConfig;

/**
 * Relays /api/manage/v1/* to Aicountly Manage's company registry, so the browser never calls
 * manage.aicountly.com itself.
 *
 * WHY. Production hosting (cPanel with Imunify360 WebShield, splash screen on) answers a browser
 * request from an address it distrusts — common Indian CGNAT broadband — with a challenge page that
 * carries no CORS headers. A browser call from remote.aicountly.com to manage.aicountly.com
 * therefore failed with no response at all, and Users & access, My workspaces and the company picker
 * went blank. A call to the page's own origin is not affected, so the browser now asks this API and
 * this API asks Manage, server to server, with the person's own ses_key.
 *
 * WHAT IS RELAYED. Exactly the calls the shared client (web/src/company-access/companyRegistryClient.ts)
 * makes, each under its own method; anything else is a 404. Only the person's Authorization and the
 * request headers Manage reads on these calls are forwarded — never a cookie, a service key or an
 * actor header the browser made up — so Manage decides every call exactly as it would have from the
 * browser. This relay grants nothing. Manage's address is the configured `remote.manageBase`, the
 * same one ManageClient asks.
 */
class ManageRegistryRelay
{
    /** A grant reference or a person id (`<uuid>:<app>`): never `.` or `..`, never a separator. */
    private const SEGMENT = '/^[A-Za-z0-9_:@-][A-Za-z0-9_:.@-]*$/';

    private const REF = '[A-Za-z0-9_:@-][A-Za-z0-9_:.@-]*';

    /** @var array<string, list<string>> method => registry path patterns (companyRegistryClient.ts) */
    public const ROUTES = [
        'GET' => [
            '#^v1/applications$#',
            '#^v1/company-directory$#',
            '#^v1/companies/\d+/(summary|access|permissions|access-audit|access-requests)$#',
            '#^v1/access-requests$#',
            '#^v1/workspaces$#',
            '#^v1/workspaces/\d+/(members|member-suggestions)$#',
            // company-access 1.2.0 (CONTRACTS §1, §2): role catalogue, creation targets, pending counts.
            '#^v1/(app-roles|company-creation-targets|me/pending)$#',
            // company-access 1.3.0: the owner's Company users.
            '#^v1/workspaces/\d+/company-users$#',
            // Plans (CONTRACTS §3.5): the company's plan and the person's own.
            '#^v1/companies/\d+/plan$#',
            '#^v1/me/plan$#',
        ],
        'POST' => [
            '#^v1/companies/duplicate-check$#',
            '#^v1/companies/\d+/(permissions|access-requests)$#',
            '#^v1/companies/\d+/creator-access/retry$#',
            '#^v1/access-requests/\d+/(approve|reject|cancel)$#',
            '#^v1/workspaces/\d+/(members|accept|decline|exit)$#',
            // company-access 1.3.0: move one person between the Workspace team and Company users.
            '#^v1/workspaces/\d+/members/' . self::REF . '/move$#',
        ],
        'PUT' => [
            '#^v1/companies/\d+/visibility$#',
        ],
        'PATCH' => [
            '#^v1/companies/\d+/permissions/' . self::REF . '$#',
            // Workspace rename (owner) and a member's sharing settings (company-access 1.1.1 / 1.2.0).
            '#^v1/workspaces/\d+$#',
            '#^v1/workspaces/\d+/members/' . self::REF . '$#',
        ],
        'DELETE' => [
            '#^v1/companies/\d+/permissions/' . self::REF . '$#',
            '#^v1/workspaces/\d+/members/' . self::REF . '$#',
            // company-access 1.3.0: end one person's share of one company.
            '#^v1/companies/\d+/members/' . self::REF . '$#',
        ],
    ];

    /**
     * Relayed by contract (CONTRACTS §3.5 plan reads) although the vendored client does not call them
     * itself; tests/Directory/ManageRegistryRelayTest.php allows exactly these beyond the client's calls.
     */
    public const CONTRACT_ONLY = ['GET v1/companies/74/plan', 'GET v1/me/plan'];

    /** Request headers Manage reads on a registry call, forwarded as the browser sent them. */
    public const FORWARDED_HEADERS = ['Authorization', 'Content-Type', 'Accept', 'Idempotency-Key', 'X-Correlation-Id'];

    private const CONNECT_TIMEOUT = 8;
    private const READ_TIMEOUT    = 20;
    /** A grant can seat the person in a product on Manage's side before it answers: writes get longer. */
    private const WRITE_TIMEOUT = 45;

    public function __construct(private readonly RemoteConfig $config)
    {
    }

    /** Whether `$method $path` (decoded, e.g. `v1/workspaces/3/members`) is a call the shared screens make. */
    public static function isRegistryRoute(string $method, string $path): bool
    {
        foreach (self::ROUTES[strtoupper($method)] ?? [] as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The registry path's segments, each decoded and checked, or null when one is empty, `.`/`..`,
     * or carries a character no registry path uses — so nothing in a segment can add or climb a
     * level once it is encoded again.
     *
     * @param list<string> $segments
     *
     * @return list<string>|null
     */
    public static function checkedSegments(array $segments): ?array
    {
        $out = [];
        foreach ($segments as $segment) {
            $decoded = rawurldecode((string) $segment);
            if (preg_match(self::SEGMENT, $decoded) !== 1) {
                return null;
            }
            $out[] = $decoded;
        }

        return $out === [] ? null : $out;
    }

    /**
     * What would be sent to Manage, or null when this request is not relayed.
     *
     * @param list<string>          $segments       the path below /api/manage, e.g. ['v1', 'workspaces']
     * @param array<string, string> $requestHeaders header name => value, as the browser sent them
     *
     * @return array{method: string, url: string, headers: list<string>, body: ?string, timeout: int}|null
     */
    public function plan(string $method, array $segments, string $query, array $requestHeaders, string $body): ?array
    {
        $method  = strtoupper($method);
        $checked = self::checkedSegments($segments);
        if ($checked === null || ! self::isRegistryRoute($method, implode('/', $checked))) {
            return null;
        }

        $url = rtrim($this->config->manageBase, '/') . '/api/' . implode('/', array_map('rawurlencode', $checked))
            . ($query !== '' ? '?' . $query : '');

        $wire = [];
        foreach (self::FORWARDED_HEADERS as $name) {
            foreach ($requestHeaders as $given => $value) {
                if (strcasecmp((string) $given, $name) === 0 && trim((string) $value) !== '') {
                    $wire[] = $name . ': ' . str_replace(["\r", "\n"], '', (string) $value);
                    break;
                }
            }
        }
        $wire[] = 'X-Saas-Origin: ' . ManageClient::APP_KEY;

        return [
            'method'  => $method,
            'url'     => $url,
            'headers' => $wire,
            'body'    => $method === 'GET' ? null : $body,
            'timeout' => $method === 'GET' ? self::READ_TIMEOUT : self::WRITE_TIMEOUT,
        ];
    }

    /**
     * Relay one request and return what the browser should get.
     *
     * @param list<string>          $segments
     * @param array<string, string> $requestHeaders
     *
     * @return array{status: int, contentType: string, body: string, correlationId: string}
     */
    public function relay(string $method, array $segments, string $query, array $requestHeaders, string $body): array
    {
        $plan = $this->plan($method, $segments, $query, $requestHeaders, $body);
        if ($plan === null) {
            return self::json(404, ['error' => ['code' => 'not_relayed', 'message' => 'This path is not relayed.']]);
        }

        $answer = $this->send($plan['method'], $plan['url'], $plan['headers'], $plan['body'], $plan['timeout']);
        if ($answer['status'] === 0) {
            log_message('warning', '[manage-relay] no answer: {method} /api/{path}', ['method' => $plan['method'], 'path' => implode('/', $segments)]);

            return self::json(504, self::unavailable($plan['method']));
        }

        return $answer;
    }

    /** The 504 body when Manage did not answer. A write may or may not have happened. */
    public static function unavailable(string $method): array
    {
        return [
            'error' => [
                'code'    => 'manage_unavailable',
                'message' => strtoupper($method) === 'GET'
                    ? 'AICOUNTLY Manage did not answer. Try again in a moment.'
                    : 'AICOUNTLY Manage did not answer, so it is not known whether the change was made. Reload to check before trying again.',
            ],
        ];
    }

    /**
     * One call to Manage. Status 0 when no answer arrived.
     *
     * @param list<string> $headers
     *
     * @return array{status: int, contentType: string, body: string, correlationId: string}
     */
    protected function send(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'contentType' => '', 'body' => '', 'correlationId' => ''];
        }

        $correlationId = '';
        $options       = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HEADER         => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$correlationId): int {
                if (preg_match('/^X-Correlation-Id:\s*(\S{1,128})\s*$/i', $line, $m) === 1) {
                    $correlationId = $m[1];
                }

                return strlen($line);
            },
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);

        $response    = curl_exec($ch);
        $status      = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($response === false || $status === 0) {
            return ['status' => 0, 'contentType' => '', 'body' => '', 'correlationId' => ''];
        }

        return [
            'status'        => $status,
            'contentType'   => $contentType !== '' ? $contentType : 'application/json',
            'body'          => (string) $response,
            'correlationId' => $correlationId,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{status: int, contentType: string, body: string, correlationId: string}
     */
    private static function json(int $status, array $payload): array
    {
        return ['status' => $status, 'contentType' => 'application/json; charset=UTF-8', 'body' => (string) json_encode($payload, JSON_UNESCAPED_SLASHES), 'correlationId' => ''];
    }
}
