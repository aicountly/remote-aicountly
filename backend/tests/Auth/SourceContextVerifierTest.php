<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Domain\Support\ApiException;
use Config\Services;
use Tests\Support\RemoteTestCase;

/**
 * Source-context tokens (§6C).
 *
 * The rule under test throughout: **company context is never taken from a
 * query parameter.** A session acquires a company only from a token that
 * survives signature, issuer, audience, expiry, product allowlist and a
 * one-time `jti`.
 *
 * @internal
 */
final class SourceContextVerifierTest extends RemoteTestCase
{
    private const SECRET = 'test-context-secret';

    public function testAValidTokenIsAccepted(): void
    {
        $token = $this->token();

        $context = Services::sourceContextVerifier()->verify($token);

        $this->assertSame(481, $context->companyId);
        $this->assertSame(12, $context->branchId);
        $this->assertSame(2026, $context->financialYearId);
        $this->assertSame('BOOKS', $context->product);
        $this->assertSame('/gst/gstr2b-reconciliation', $context->route);
    }

    public function testAReplayedTokenIsRejected(): void
    {
        $token = $this->token();

        Services::sourceContextVerifier()->verify($token);

        try {
            Services::sourceContextVerifier()->verify($token);
            $this->fail('A jti may be spent exactly once.');
        } catch (ApiException $e) {
            $this->assertSame('CONTEXT_INVALID', $e->errorCode());
            $this->assertSame('CONTEXT_REPLAYED', $e->details()['reason']);
        }
    }

    public function testATamperedPayloadIsRejected(): void
    {
        // The exact attack the signature exists to stop: change the company id
        // in a token that is otherwise perfectly valid.
        $token = $this->token();
        [$header, $payload, $signature] = explode('.', $token);

        $claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);
        $claims['company_id'] = 902;
        $forged = $header . '.' . $this->encode($claims) . '.' . $signature;

        $this->expectException(ApiException::class);

        try {
            Services::sourceContextVerifier()->verify($forged);
        } catch (ApiException $e) {
            $this->assertSame('CONTEXT_SIGNATURE_INVALID', $e->details()['reason']);

            throw $e;
        }
    }

    public function testAlgNoneIsRejected(): void
    {
        $claims = $this->claims();
        $token  = $this->encode(['alg' => 'none', 'typ' => 'JWT']) . '.' . $this->encode($claims) . '.';

        try {
            Services::sourceContextVerifier()->verify($token);
            $this->fail('`alg: none` must never be accepted.');
        } catch (ApiException $e) {
            $this->assertSame('CONTEXT_ALG_UNSUPPORTED', $e->details()['reason']);
        }
    }

    public function testAnExpiredTokenIsRejected(): void
    {
        $token = $this->token(['iat' => time() - 3600, 'exp' => time() - 1800]);

        try {
            Services::sourceContextVerifier()->verify($token);
            $this->fail('An expired token must be refused.');
        } catch (ApiException $e) {
            $this->assertSame('CONTEXT_EXPIRED', $e->details()['reason']);
        }
    }

    public function testAnOldTokenIsRejectedEvenWithAGenerousExpiry(): void
    {
        // The issuer does not get to decide how long this deployment will
        // accept a token for.
        $token = $this->token(['iat' => time() - 3600, 'exp' => time() + 86400]);

        try {
            Services::sourceContextVerifier()->verify($token);
            $this->fail('A token older than contextMaxAgeSeconds must be refused.');
        } catch (ApiException $e) {
            $this->assertSame('CONTEXT_EXPIRED', $e->details()['reason']);
        }
    }

    public function testAWrongAudienceIsRejected(): void
    {
        $token = $this->token(['aud' => 'aicountly-books']);

        try {
            Services::sourceContextVerifier()->verify($token);
            $this->fail('A token minted for another product must be refused.');
        } catch (ApiException $e) {
            $this->assertSame('CONTEXT_AUDIENCE_INVALID', $e->details()['reason']);
        }
    }

    public function testAWrongIssuerIsRejected(): void
    {
        $token = $this->token(['iss' => 'https://not-aicountly.example']);

        try {
            Services::sourceContextVerifier()->verify($token);
            $this->fail('An unknown issuer must be refused.');
        } catch (ApiException $e) {
            $this->assertSame('CONTEXT_ISSUER_INVALID', $e->details()['reason']);
        }
    }

    public function testAProductOutsideTheAllowlistIsRejected(): void
    {
        $token = $this->token(['product' => 'SOME_OTHER_APP']);

        try {
            Services::sourceContextVerifier()->verify($token);
            $this->fail('A product that is not on the allowlist must be refused.');
        } catch (ApiException $e) {
            $this->assertSame('CONTEXT_PRODUCT_NOT_ALLOWED', $e->details()['reason']);
        }
    }

    public function testContextIsRefusedEntirelyWhenNoSecretIsConfigured(): void
    {
        // An unconfigured deployment must refuse company context, not accept
        // it unverified.
        $this->configureRemote(static function ($config): void {
            $config->contextSecret = '';
        });

        $this->assertFalse(Services::sourceContextVerifier()->isEnabled());

        $this->expectException(ApiException::class);
        Services::sourceContextVerifier()->verify('anything');
    }

    public function testTheRejectionMessageDoesNotLeakTheReason(): void
    {
        // The caller learns that the link is unusable; only the audit log and
        // the server log learn why.
        try {
            Services::sourceContextVerifier()->verify($this->token(['iss' => 'https://elsewhere.example']));
            $this->fail('Expected a rejection.');
        } catch (ApiException $e) {
            $this->assertSame(
                'This link is no longer valid. Please start Remote again from your AICOUNTLY product.',
                $e->getMessage(),
            );
            $this->assertStringNotContainsString('issuer', $e->getMessage());
        }
    }

    // ------------------------------------------------------------- helpers

    /** @param array<string, mixed> $overrides */
    private function token(array $overrides = []): string
    {
        $claims = array_merge($this->claims(), $overrides);

        $header  = $this->encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $payload = $this->encode($claims);

        $signature = hash_hmac('sha256', $header . '.' . $payload, self::SECRET, true);

        return $header . '.' . $payload . '.' . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }

    /** @return array<string, mixed> */
    private function claims(): array
    {
        return [
            'iss'               => 'https://my.aicountly.com',
            'aud'               => 'aicountly-remote',
            'sub'               => 'uuid-rahul',
            'company_id'        => 481,
            'branch_id'         => 12,
            'financial_year_id' => 2026,
            'product'           => 'BOOKS',
            'route'             => '/gst/gstr2b-reconciliation',
            'iat'               => time(),
            'exp'               => time() + 120,
            'jti'               => bin2hex(random_bytes(12)),
        ];
    }

    /** @param array<string, mixed> $data */
    private function encode(array $data): string
    {
        return rtrim(strtr(base64_encode((string) json_encode($data, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    }
}
