<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Support\ApiException;
use CodeIgniter\Database\BaseConnection;
use Config\Remote as RemoteConfig;
use Throwable;

/**
 * Verifies the short-lived signed context another AICOUNTLY product issues when
 * it launches Remote (§6C).
 *
 * The rule this class exists to enforce: **company context is never taken from
 * a query parameter.** `?company_id=481` is a request, not a fact. The only way
 * a session acquires a company is a token that survives every check below.
 *
 * Format is a compact JWS (HS256), the same shape AICOUNTLY already uses
 * elsewhere, so an issuing product can produce one with any JWT library:
 *
 *     base64url(header) . '.' . base64url(payload) . '.' . base64url(signature)
 *
 * Checks, in order, all of which must pass:
 *   1. a signing secret is configured at all — an unconfigured deployment
 *      refuses context rather than accepting it unverified;
 *   2. `alg` is exactly HS256 (no `none`, no algorithm confusion);
 *   3. the signature matches, compared in constant time;
 *   4. `iss` and `aud` are the configured issuer and this product;
 *   5. `exp` is in the future and `iat` is not, within a small clock skew;
 *   6. the token is younger than `contextMaxAgeSeconds` whatever it claims;
 *   7. `product` is on the allowlist;
 *   8. `jti` has not been used before — enforced by a unique index, so two
 *      simultaneous redemptions cannot both win.
 */
class SourceContextVerifier
{
    /** Tolerated clock difference between the issuing product and this server. */
    private const CLOCK_SKEW_SECONDS = 30;

    public function __construct(
        private readonly RemoteConfig $config,
        private readonly BaseConnection $db,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->contextSecret !== '';
    }

    /**
     * Verify a token and consume its `jti`.
     *
     * @throws ApiException when the token is missing, malformed, expired,
     *                      replayed, or issued for something other than Remote.
     */
    public function verify(string $token): SourceContext
    {
        if (! $this->isEnabled()) {
            throw ApiException::forbidden(
                'CONTEXT_UNAVAILABLE',
                'This deployment is not configured to accept launch context from other AICOUNTLY products.',
            );
        }

        $claims = $this->decodeAndCheckSignature($token);

        $now = time();

        if ((string) ($claims['iss'] ?? '') !== $this->config->contextIssuer) {
            throw $this->rejected('CONTEXT_ISSUER_INVALID');
        }

        $audience = $claims['aud'] ?? '';
        $audiences = is_array($audience) ? $audience : [$audience];
        if (! in_array($this->config->contextAudience, array_map('strval', $audiences), true)) {
            throw $this->rejected('CONTEXT_AUDIENCE_INVALID');
        }

        $exp = isset($claims['exp']) ? (int) $claims['exp'] : 0;
        if ($exp <= 0 || $exp < $now - self::CLOCK_SKEW_SECONDS) {
            throw $this->rejected('CONTEXT_EXPIRED');
        }

        $iat = isset($claims['iat']) ? (int) $claims['iat'] : 0;
        if ($iat <= 0 || $iat > $now + self::CLOCK_SKEW_SECONDS) {
            throw $this->rejected('CONTEXT_NOT_YET_VALID');
        }

        // A generous `exp` from the issuer must not extend the window past what
        // this deployment is willing to accept.
        if ($now - $iat > $this->config->contextMaxAgeSeconds) {
            throw $this->rejected('CONTEXT_EXPIRED');
        }

        $product = strtoupper((string) ($claims['product'] ?? ''));
        if ($product === '' || ! in_array($product, $this->config->sourceProductAllowlist, true)) {
            throw $this->rejected('CONTEXT_PRODUCT_NOT_ALLOWED');
        }

        $subject = (string) ($claims['sub'] ?? $claims['uuid'] ?? '');
        if ($subject === '') {
            throw $this->rejected('CONTEXT_SUBJECT_MISSING');
        }

        $jti = (string) ($claims['jti'] ?? '');
        if ($jti === '' || strlen($jti) > 64) {
            throw $this->rejected('CONTEXT_JTI_MISSING');
        }

        $this->consumeJti($jti, $subject, $claims, $product, $exp);

        return new SourceContext(
            $subject,
            $this->nullableInt($claims['company_id'] ?? null),
            $this->nullableInt($claims['branch_id'] ?? null),
            $this->nullableInt($claims['financial_year_id'] ?? null),
            $product,
            $this->nullableString($claims['route'] ?? null, 255),
            $this->nullableString($claims['support_ticket_id'] ?? null, 64),
            $this->nullableString($claims['source_agent'] ?? null, 40),
            $this->nullableString($claims['source_conversation_id'] ?? null, 120),
            $this->nullableString($claims['issue_summary'] ?? null, 2000),
            $this->nullableString($claims['source_reference'] ?? null, 120),
            $jti,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeAndCheckSignature(string $token): array
    {
        $parts = explode('.', trim($token));
        if (count($parts) !== 3) {
            throw $this->rejected('CONTEXT_MALFORMED');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $header = json_decode((string) $this->base64UrlDecode($encodedHeader), true);
        if (! is_array($header) || ($header['alg'] ?? '') !== 'HS256') {
            // Rejecting anything but HS256 by name is what closes the `alg: none`
            // and algorithm-confusion families outright.
            throw $this->rejected('CONTEXT_ALG_UNSUPPORTED');
        }

        $expected = hash_hmac(
            'sha256',
            $encodedHeader . '.' . $encodedPayload,
            $this->config->contextSecret,
            true,
        );

        $provided = $this->base64UrlDecode($encodedSignature);
        if ($provided === null || ! hash_equals($expected, $provided)) {
            throw $this->rejected('CONTEXT_SIGNATURE_INVALID');
        }

        $claims = json_decode((string) $this->base64UrlDecode($encodedPayload), true);
        if (! is_array($claims)) {
            throw $this->rejected('CONTEXT_MALFORMED');
        }

        return $claims;
    }

    /**
     * Burn the `jti`, once, for everyone.
     *
     * The unique index on `jti` is the actual guard: two requests presenting
     * the same token at the same instant both attempt the insert and exactly
     * one succeeds. Checking with a SELECT first would leave the race open.
     *
     * @param array<string, mixed> $claims
     */
    private function consumeJti(string $jti, string $subject, array $claims, string $product, int $exp): void
    {
        try {
            $this->db->query(
                'INSERT INTO remote_context_tokens (jti, issuer, audience, company_id, source_product, expires_at, consumed_at, created_at)
                 VALUES (?, ?, ?, ?, ?, TO_TIMESTAMP(?), NOW(), NOW())',
                [
                    $jti,
                    (string) ($claims['iss'] ?? ''),
                    $this->config->contextAudience,
                    $this->nullableInt($claims['company_id'] ?? null),
                    $product,
                    $exp,
                ],
            );
        } catch (Throwable $e) {
            // Any insert failure here is treated as a replay. A genuine
            // database outage would already have failed the request earlier.
            log_message('warning', 'Remote: rejected context token replay for jti {jti} (subject {sub})', [
                'jti' => $jti,
                'sub' => $subject,
            ]);

            throw $this->rejected('CONTEXT_REPLAYED');
        }
    }

    /**
     * One message for every rejection reason.
     *
     * The specific code goes to the audit log and the server log; the caller is
     * told only that the launch context could not be used, so a token cannot be
     * probed for *why* it failed.
     */
    private function rejected(string $code): ApiException
    {
        log_message('warning', 'Remote: source context token rejected ({code})', ['code' => $code]);

        return new ApiException(
            'CONTEXT_INVALID',
            'This link is no longer valid. Please start Remote again from your AICOUNTLY product.',
            400,
            ['reason' => $code],
        );
    }

    private function base64UrlDecode(string $value): ?string
    {
        $padded  = strtr($value, '-_', '+/');
        $decoded = base64_decode($padded . str_repeat('=', (4 - strlen($padded) % 4) % 4), true);

        return $decoded === false ? null : $decoded;
    }

    private function nullableInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    private function nullableString(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, $maxLength);
    }
}
