<?php

declare(strict_types=1);

namespace Tests\Deploy;

use PHPUnit\Framework\TestCase;

/**
 * scripts/staging/turn-preflight.sh — the read-only readiness check before the first staging deploy. It must stay read-only and
 * secret-free, and it must refuse a production target.
 *
 * @internal
 */
final class TurnPreflightTest extends TestCase
{
    private function preflight(string ...$args): array
    {
        $script = dirname(__DIR__, 3) . '/scripts/staging/turn-preflight.sh';
        exec('bash ' . escapeshellarg($script) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $lines, $code);

        return [$code, implode("\n", $lines)];
    }

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || trim((string) shell_exec('command -v bash')) === '') {
            $this->markTestSkipped('bash is needed.');
        }
    }

    public function testPrintShowsEveryCommandAndRunsNone(): void
    {
        [$code, $out] = $this->preflight('--repo', 'aicountly/x', '--product', 'chat', '--ssh', 'sbx@host.example', '--root', 'public_html', '--print');

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('(--print: nothing was run)', $out);
        foreach (['gh secret list', 'sandbox-target-guard.sh', 'probe.php --bootstrap=config/ice-kit.php --status', 'openssl s_client', '/3478', '/443'] as $step) {
            $this->assertStringContainsString($step, $out);
        }
        $this->assertStringContainsString('SANDBOX_SFTP_HOST', $out, 'Connect\'s secret names');
    }

    public function testItIsReadOnly(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/scripts/staging/turn-preflight.sh');

        foreach (['rsync', 'scp ', 'rm -', 'mv ', 'sed -i', 'systemctl', 'service ', 'gh workflow', 'gh secret set', 'apply-turn-config', 'inject-turn-secrets', '> "$root', 'tee '] as $write) {
            $this->assertStringNotContainsString($write, $src, "a preflight must not '{$write}'");
        }
    }

    public function testItRejectsInputThatIsNotAPlainTargetAndNeverRunsIt(): void
    {
        foreach ([['--ssh', 'a@b; id'], ['--root', 'x; id'], ['--php', 'php; id'], ['--product', 'books']] as [$flag, $bad]) {
            $args = ['--repo', 'a/b', '--product', 'remote', '--ssh', 'sbx@h', '--root', 'public_html', '--print'];
            $i    = array_search($flag, $args, true);
            if ($i === false) {
                $args[] = $flag;
                $args[] = $bad;
            } else {
                $args[$i + 1] = $bad;
            }
            [$code] = $this->preflight(...$args);
            $this->assertSame(2, $code, "{$flag} {$bad}");
        }
    }

    public function testAProductionTargetFailsTheCheck(): void
    {
        [$code, $out] = $this->preflight('--repo', 'a/b', '--product', 'remote', '--ssh', 'remoteaicountly@h.invalid', '--root', 'public_html', '--turn-hosts', 'x.invalid');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('FAIL  the target is a production account or path', $out);
    }
}
