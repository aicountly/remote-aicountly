<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use Config\Remote as RemoteConfig;

/**
 * Server-to-server calls to the AICOUNTLY auth portal.
 *
 * The portal owns every token. Remote never mints, signs or stores one — it
 * relays the browser's session-bootstrap calls and asks the portal whether a
 * `ses_key` is still good.
 *
 * Ported from `server-php/src/Portal.php`, which this replaces; the semantics
 * are unchanged so sign-in keeps behaving exactly as documented in
 * docs/auth/AICOUNTLY_AUTH_WORKFLOW.md.
 */
class PortalClient
{
    private const CONNECT_TIMEOUT_SECONDS = 8;
    private const REQUEST_TIMEOUT_SECONDS = 15;

    public function __construct(private readonly RemoteConfig $config)
    {
    }

    public function base(): string
    {
        return rtrim($this->config->portalAuthBase, '/');
    }

    /**
     * Forward one request to the portal and return its raw answer.
     *
     * Only the headers given are sent — never an Origin of this host's own, which
     * the portal's sso/exchange would refuse (and burn the code over).
     *
     * @param  array<int, string> $headers
     * @return array{status: int, body: string, contentType: string, retryAfter: string}
     */
    public function forward(string $method, string $path, array $headers, string $body): array
    {
        $url = $this->base() . '/api/' . ltrim($path, '/');

        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 504, 'body' => '', 'contentType' => 'application/json', 'retryAfter' => ''];
        }

        // The portal's Retry-After (sso/exchange 503 auth_unavailable), passed through.
        $retryAfter = '';
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT_SECONDS,
            CURLOPT_HEADER         => false,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$retryAfter): int {
                if (preg_match('/^Retry-After:\s*(\d{1,5})\s*$/i', $line, $m) === 1) {
                    $retryAfter = $m[1];
                }

                return strlen($line);
            },
        ];

        // Set the body even when it is empty: a bodiless CURLOPT_CUSTOMREQUEST
        // POST goes out with no Content-Length, and the seskey call has no body.
        if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $options);

        $response    = curl_exec($ch);
        $status      = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $failed      = $response === false;
        curl_close($ch);

        if ($failed || $status === 0) {
            return ['status' => 504, 'body' => '', 'contentType' => 'application/json', 'retryAfter' => ''];
        }

        return [
            'status'      => $status,
            'body'        => (string) $response,
            'contentType' => $contentType !== '' ? $contentType : 'application/json',
            'retryAfter'  => $retryAfter,
        ];
    }

    public const SESSION_VALID       = 'valid';
    public const SESSION_INVALID     = 'invalid';
    public const SESSION_UNAVAILABLE = 'unavailable';

    /**
     * Validate a Bearer `ses_key`: the identity when the portal confirmed it,
     * null otherwise. A caller that answers a request uses {@see checkSesKey()},
     * so an outage is reported as such rather than as a dead session.
     *
     * @return array<string, mixed>|null
     */
    public function validateSesKey(string $sesKey): ?array
    {
        $check = $this->checkSesKey($sesKey);

        return $check['state'] === self::SESSION_VALID ? $check['payload'] : null;
    }

    /**
     * Ask the portal about a `ses_key`, keeping "no" apart from "no answer"
     * (spec 3.8, I-16). my.aicountly's validatesession answers 200
     * `{status: 1, uuid_aictly, ses_key}` for a live key and 401 `{status: 0}`
     * for a dead one. Only that refusal (or a 403, or `status: 0`) makes the key
     * invalid; a timeout, network error, 5xx, 404/429 or a body that is not the
     * portal's JSON is an outage. Access is denied either way — an unreachable
     * portal is never "signed in" — but only a refusal tells the browser its
     * sign-in is gone.
     *
     * @return array{state: string, payload: array<string, mixed>|null}
     */
    public function checkSesKey(string $sesKey): array
    {
        $result = $this->forward('POST', 'validatesession', [
            'Authorization: Bearer ' . $sesKey,
            'Content-Type: application/json',
        ], '');

        return self::classifySessionAnswer($result['status'], $result['body']);
    }

    /**
     * The decision behind {@see checkSesKey()}, on a raw portal answer.
     *
     * @return array{state: string, payload: array<string, mixed>|null}
     */
    public static function classifySessionAnswer(int $status, string $body): array
    {
        if ($status === 401 || $status === 403) {
            return ['state' => self::SESSION_INVALID, 'payload' => null];
        }
        if ($status !== 200 || trim($body) === '') {
            return ['state' => self::SESSION_UNAVAILABLE, 'payload' => null];
        }

        $data = json_decode($body, true);
        if (! is_array($data) || ! array_key_exists('status', $data)) {
            return ['state' => self::SESSION_UNAVAILABLE, 'payload' => null];
        }
        if ((int) $data['status'] !== 1) {
            return ['state' => self::SESSION_INVALID, 'payload' => null];
        }

        return ['state' => self::SESSION_VALID, 'payload' => $data];
    }

    /**
     * The signed-in person's own name and e-mail, from `GET /api/userprofile`.
     *
     * validatesession proves who the caller is but carries neither (G28#1), so
     * this is where the name shown to a host deciding who may watch a screen
     * comes from. Null when the portal cannot answer: the caller keeps whatever
     * snapshot it has, and a missing name never blocks the work.
     *
     * @return array{name: string, email: ?string}|null
     */
    public function userProfile(string $sesKey): ?array
    {
        $result = $this->forward('GET', 'userprofile', [
            'Authorization: Bearer ' . $sesKey,
            'Accept: application/json',
        ], '');

        if ($result['status'] !== 200 || $result['body'] === '') {
            return null;
        }

        $data = json_decode($result['body'], true);
        $row  = is_array($data) ? ($data['data'] ?? $data) : null;
        if (! is_array($row)) {
            return null;
        }

        $name = trim((string) ($row['display_name'] ?? $row['full_name'] ?? $row['user_name'] ?? $row['name'] ?? ''));
        if ($name === '') {
            $name = trim(((string) ($row['user_firstname'] ?? '')) . ' ' . ((string) ($row['user_lastname'] ?? '')));
        }
        $email = trim((string) ($row['email'] ?? $row['user_regdemail'] ?? $row['reg_email'] ?? ''));

        if ($name === '' && $email === '') {
            return null;
        }

        return ['name' => $name, 'email' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null];
    }
}
