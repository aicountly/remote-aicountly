<?php

declare(strict_types=1);

namespace Aicountly\Ice\Tests;

use Aicountly\Ice\IceRuntime;
use Aicountly\Ice\Tier;

require_once __DIR__ . '/IceKitTestCase.php';

/** `enforce` may run only when who-is-paid is mapped, attested and current; until then it observes. */
final class LicenceGateTest extends IceKitTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/ice-gate-' . bin2hex(random_bytes(4)) . '/';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . 'ice/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir . 'ice');
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function gated(?\Closure $readiness, array $env = []): \Aicountly\Ice\IceIssuer
    {
        $env = $this->activeActiveEnv(['WEBRTC_TURN_NODE_NCR_STATUS' => 'disabled', 'WEBRTC_TURN_MAX_NODES' => '1'] + $env);

        return IceRuntime::issuer(static fn (string $k): ?string => $env[$k] ?? null, $this->dir, 'remote', null, null, static fn (): int => self::NOW, [], $readiness);
    }

    private function fillFree(\Aicountly\Ice\IceIssuer $issuer, int $n): int
    {
        $ok = 0;
        for ($i = 0; $i < $n; $i++) {
            $ok += $issuer->issue("u{$i}", ['tier' => Tier::Free, 'sessionRef' => "s{$i}"])->relayAvailable() ? 1 : 0;
        }

        return $ok;
    }

    public function testEnforceRunsOnlyWhenNothingBlocksIt(): void
    {
        $ready = $this->gated(static fn (): array => []);
        $this->assertSame('enforce', $ready->status()['admission']['mode']);
        $this->assertSame(50, $this->fillFree($ready, 80), 'Ready: the free share is enforced.');
    }

    public function testWhileAnyReasonStandsEnforceObservesAndSaysWhy(): void
    {
        $held = $this->gated(static fn (): array => ['the feed is not confirmed', 'plan X is unmapped']);
        $status = $held->status();

        $this->assertSame('observe', $status['admission']['mode']);
        $this->assertSame(['the feed is not confirmed', 'plan X is unmapped'], $status['enforceBlockedBy']);
        $this->assertSame(80, $this->fillFree($held, 80), 'Observing denies nobody: paying customers are never turned into free ones by a missing feed.');
    }

    public function testAProductThatWiresNoCheckCannotEnforce(): void
    {
        $status = $this->gated(null)->status();

        $this->assertSame('observe', $status['admission']['mode']);
        $this->assertNotEmpty($status['enforceBlockedBy']);
    }

    public function testACheckThatThrowsBlocksToo(): void
    {
        $this->assertSame('observe', $this->gated(static function (): array {
            throw new \RuntimeException('database down');
        })->status()['admission']['mode']);
    }

    public function testTheCheckIsNotConsultedWhenNotEnforcing(): void
    {
        $called = false;
        $this->gated(static function () use (&$called): array {
            $called = true;

            return [];
        }, ['WEBRTC_TURN_ADMISSION' => 'observe']);

        $this->assertFalse($called);
    }
}
