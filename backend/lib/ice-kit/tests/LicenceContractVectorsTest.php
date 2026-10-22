<?php

declare(strict_types=1);

namespace Aicountly\Ice\Tests;

use Aicountly\Ice\Licence\EntitlementVerifier;
use Aicountly\Ice\Licence\LicenceContext;
use Aicountly\Ice\Licence\LicencePolicy;
use Aicountly\Ice\Licence\LicenceResolver;
use Aicountly\Ice\Licence\MemoryEntitlementCache;
use PHPUnit\Framework\TestCase;

/**
 * The central backend's wire contract, as executable vectors (tests/contract/licence-v1-vectors.json). Whoever builds the
 * licensing backend can run their output through the same expectations; the products' adapter must agree with every one.
 */
final class LicenceContractVectorsTest extends TestCase
{
    /** @return array<string, array{0: array<string, mixed>}> */
    public static function vectors(): array
    {
        $file = json_decode((string) file_get_contents(__DIR__ . '/contract/licence-v1-vectors.json'), true);
        $out  = [];
        foreach ($file['cases'] as $case) {
            $out[$case['name']] = [$case + ['_key' => $file['public_key'], '_policy' => $file['policy']]];
        }

        return $out;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('vectors')]
    public function testTheAdapterAgreesWithTheVector(array $case): void
    {
        $key      = $case['_key'];
        $verifier = new EntitlementVerifier([$key['kid'] => base64_decode($key['value'], true)]);
        $cache    = new MemoryEntitlementCache();
        foreach ($case['envelopes'] as $f) {
            $cache->put($f['scope'], $f['ref'], $case['product'], $f['envelope'], $case['now']);
        }
        foreach ($case['revocations'] as $r) {
            $cache->revoke($r['customer'], $case['product'], $r['revision'], $case['now'], $r['subject']);
        }

        $policy   = new LicencePolicy($case['_policy']['max_age_seconds'], $case['_policy']['paid_kinds'], false, true);
        $resolver = new LicenceResolver($cache, $verifier, $policy, static fn (): int => $case['now']);
        $c        = $case['context'];
        $r        = $resolver->forContext(new LicenceContext($c['customer'], $case['product'], $c['subject'], $c['membership_verified']));

        $this->assertSame($case['expect'], ['state' => $r->state->value, 'tier' => $r->tier()->value, 'reason' => $r->reason, 'basis' => $r->basis], $case['why']);
    }

    public function testTheVectorFileIsReproducibleFromItsGenerator(): void
    {
        $generated = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/contract/generate-vectors.php'));

        $this->assertSame((string) file_get_contents(__DIR__ . '/contract/licence-v1-vectors.json'), $generated);
    }
}
