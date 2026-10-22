<?php

declare(strict_types=1);

namespace Tests\Deploy;

use PHPUnit\Framework\TestCase;

/**
 * scripts/ci/sandbox-target-guard.sh — the sandbox deploy shares the WHM server with production, so a target
 * that is (or lives inside) a production cPanel account must be refused before anything is sent.
 *
 * @internal
 */
final class SandboxTargetGuardTest extends TestCase
{
    /** @return array{0: int, 1: string} */
    private function guard(string $user, string $root, string $extra = ''): array
    {
        $script = dirname(__DIR__, 3) . '/scripts/ci/sandbox-target-guard.sh';
        $cmd    = ($extra !== '' ? 'PRODUCTION_ACCOUNTS=' . escapeshellarg($extra) . ' ' : '')
            . 'bash ' . escapeshellarg($script) . ' ' . escapeshellarg($user) . ' ' . escapeshellarg($root) . ' 2>&1';
        exec($cmd, $lines, $code);

        return [$code, implode("\n", $lines)];
    }

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || trim((string) shell_exec('command -v bash')) === '') {
            $this->markTestSkipped('bash is needed to run the guard.');
        }
    }

    public function testAProductionAccountIsRefusedAsTheSshUser(): void
    {
        foreach (['remoteaicountly', 'connectaicountly', 'RemoteAicountly'] as $user) {
            [$code, $out] = $this->guard($user, 'public_html');
            $this->assertSame(1, $code, $user);
            $this->assertStringContainsString('production account', $out);
        }
    }

    public function testARootInsideAProductionHomeIsRefusedWhoeverTheUserIs(): void
    {
        foreach (['/home/remoteaicountly/public_html', '/home/connectaicountly/public_html/api', '/home/connectaicountly', '~remoteaicountly/public_html'] as $root) {
            [$code] = $this->guard('someoneelse', $root);
            $this->assertSame(1, $code, $root);
        }
    }

    public function testASandboxAccountPasses(): void
    {
        foreach ([['remotegh', 'public_html'], ['connectgh', '/home/connectgh/public_html'], ['sandbox', '~/public_html']] as [$user, $root]) {
            [$code] = $this->guard($user, $root);
            $this->assertSame(0, $code, "$user $root");
        }
    }

    public function testLookalikePrefixesAreNotMistakenForProduction(): void
    {
        [$code] = $this->guard('sbx', '/home/remoteaicountly-gh/public_html');
        $this->assertSame(0, $code);
    }

    public function testMoreAccountsCanBeAddedButNotRemoved(): void
    {
        [$code] = $this->guard('billingprod', 'public_html', 'billingprod');
        $this->assertSame(1, $code);
        [$code] = $this->guard('remoteaicountly', 'public_html', 'x');
        $this->assertSame(1, $code, 'the defaults stay');
    }

    public function testMissingArgumentsAreAUsageError(): void
    {
        [$code] = $this->guard('', 'public_html');
        $this->assertSame(2, $code);
    }
}
