<?php

declare(strict_types=1);

namespace Aicountly\Ice\Licence;

/**
 * Reads entitlements from the central licensing backend (docs/LICENCE_ENTITLEMENT_CONTRACT.md §5).
 *
 * Read-only GETs, signed with AIC-S2S v1 as the product's own client id (`remote`, `chat`); the backend selects that
 * product's secret from the client id, so a product can only ever ask for — and the backend will only ever answer
 * with — its own product's entitlements. Everything returned is an envelope the caller verifies; this class trusts
 * nothing in a response beyond its shape. No redirects, bounded time and size, TLS verified.
 *
 * The transport is a seam: `callable(string $url, array $headers, int $timeoutSeconds): array{status: int, body: string}`.
 */
final class CentralClient
{
    private const MAX_BODY = 4_194_304;

    private readonly \Closure $transport;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $client,
        private readonly string $keyId,
        private readonly string $secret,
        private readonly string $environment,
        ?\Closure $transport = null,
        private readonly int $timeoutSeconds = 6,
    ) {
        $this->transport = $transport ?? self::curl(...);
    }

    /** @param callable(string): (string|null) $read */
    public static function fromEnvironment(callable $read, string $client, ?\Closure $transport = null): ?self
    {
        $url    = rtrim(trim((string) ($read('WEBRTC_LICENCE_API_URL') ?? '')), '/');
        $secret = (string) ($read('WEBRTC_LICENCE_S2S_SECRET') ?? '');
        $keyId  = trim((string) ($read('WEBRTC_LICENCE_KEY_ID') ?? ''));
        $env    = trim((string) ($read('WEBRTC_LICENCE_ENVIRONMENT') ?: ''));

        $secure = str_starts_with($url, 'https://') || str_starts_with($url, 'http://127.0.0.1') || str_starts_with($url, 'http://localhost');
        if ($url === '' || ! $secure
            || strlen($secret) < 32 || $keyId === '' || ! in_array($env, ['production', 'sandbox', 'local'], true)) {
            return null;
        }

        return new self($url, $client, $keyId, $secret, $env, $transport);
    }

    /** @return array<string, mixed> the company-level envelope */
    public function customer(string $customerId, string $product): array
    {
        return $this->get('/v1/entitlements/' . rawurlencode($customerId), ['product_id' => $product])['data'];
    }

    /** @return array<string, mixed> one user's eligibility INSIDE one company: membership and, for a seat licence, the seat */
    public function member(string $customerId, string $subject, string $product): array
    {
        return $this->get('/v1/entitlements/' . rawurlencode($customerId), ['product_id' => $product, 'subject' => $subject])['data'];
    }

    /** @return array<string, mixed> a user's personal (user-scoped) envelope */
    public function personal(string $subject, string $product): array
    {
        return $this->get('/v1/entitlements/personal/' . rawurlencode($subject), ['product_id' => $product])['data'];
    }

    /** @return array{items: list<array<string, mixed>>, nextCursor: string|null, seq: int} one page of the product's entitlements */
    public function snapshot(string $product, ?string $cursor = null, int $limit = 200): array
    {
        $r = $this->get('/v1/entitlements', ['product_id' => $product, 'cursor' => $cursor, 'limit' => $limit]);

        return [
            'items'      => is_array($r['data']) ? array_values($r['data']) : [],
            'nextCursor' => isset($r['page']['next_cursor']) && is_string($r['page']['next_cursor']) ? $r['page']['next_cursor'] : null,
            'seq'        => (int) ($r['page']['as_of_seq'] ?? 0),
        ];
    }

    /** @return array{changes: list<array<string, mixed>>, nextSeq: int} changes after `since` (the revocation feed) */
    public function changes(string $product, int $since, int $limit = 500): array
    {
        $r = $this->get('/v1/entitlements/changes', ['product_id' => $product, 'since' => $since, 'limit' => $limit]);

        return ['changes' => is_array($r['data']) ? array_values($r['data']) : [], 'nextSeq' => (int) ($r['page']['next_seq'] ?? $since)];
    }

    /**
     * @param array<string, scalar|null> $params
     * @return array{data: mixed, page: array<string, mixed>}
     * @throws CentralUnavailable
     */
    private function get(string $path, array $params): array
    {
        $query   = AicS2s::buildQuery($params);
        $headers = AicS2s::headers($this->client, $this->secret, $this->keyId, $this->environment, 'GET', $path, $query);

        try {
            $res = ($this->transport)($this->baseUrl . $path . ($query !== '' ? '?' . $query : ''), $headers, $this->timeoutSeconds);
        } catch (\Throwable $e) {
            throw new CentralUnavailable('the licence backend could not be reached', 0, $e);
        }

        $status = (int) ($res['status'] ?? 0);
        $body   = (string) ($res['body'] ?? '');
        if ($status < 200 || $status >= 300) {
            throw new CentralUnavailable("the licence backend answered HTTP {$status}");
        }
        $json = strlen($body) <= self::MAX_BODY ? json_decode($body, true) : null;
        if (! is_array($json) || ($json['success'] ?? null) !== true || ! array_key_exists('data', $json)) {
            throw new CentralUnavailable('the licence backend answered something that is not the contract');
        }

        return ['data' => $json['data'], 'page' => is_array($json['page'] ?? null) ? $json['page'] : []];
    }

    /** @return array{status: int, body: string} */
    private static function curl(string $url, array $headers, int $timeout): array
    {
        $h = [];
        foreach ($headers as $k => $v) {
            $h[] = "{$k}: {$v}";
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => $timeout, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_MAXFILESIZE => self::MAX_BODY,
        ]);
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => is_string($body) ? $body : ''];
    }
}
