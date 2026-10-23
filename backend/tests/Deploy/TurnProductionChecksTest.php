<?php

declare(strict_types=1);

namespace Tests\Deploy;

use PHPUnit\Framework\TestCase;

/**
 * Production TURN authentication must not pass silently (docs/production/TURN_PRODUCTION_SECRETS.md).
 *
 * scripts/ci/turn-secret-presence.sh fails when an active node has no secret and never prints a value;
 * scripts/ci/turn-staging-check.sh in strict mode fails unless the relay is configured and both nodes pass;
 * the production workflow runs it strictly, without continue-on-error, and fails a deploy that left a secret missing.
 *
 * @internal
 */
final class TurnProductionChecksTest extends TestCase
{
    private const SECRET = 'mumbai-coturn-secret-0123456789abcdef';

    private string $dir = '';

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || trim((string) shell_exec('command -v bash')) === '') {
            $this->markTestSkipped('bash is needed to run the CI scripts.');
        }

        $this->dir = sys_get_temp_dir() . '/turn-prod-' . bin2hex(random_bytes(5));
        mkdir($this->dir . '/api/lib/ice-kit/bin', 0700, true);
    }

    protected function tearDown(): void
    {
        shell_exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** @return array{0: int, 1: string} */
    private function shell(array $cmd, array $env = []): array
    {
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + ['PATH' => (string) getenv('PATH'), 'HOME' => $this->dir]);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $out];
    }

    private function script(string $name): string
    {
        return dirname(__DIR__, 3) . '/scripts/' . $name;
    }

    /** @return array{0: int, 1: string} */
    private function presence(string $env): array
    {
        file_put_contents($this->dir . '/api/.env', $env);

        return $this->shell(['bash', $this->script('ci/turn-secret-presence.sh'), $this->dir]);
    }

    public function testAnActiveNodeWithoutASecretFailsAndNoValueIsPrinted(): void
    {
        [$code, $out] = $this->presence("WEBRTC_TURN_NODES=mum,ncr\nWEBRTC_TURN_NODE_MUM_SECRET=" . self::SECRET . "\n");

        $this->assertSame(1, $code);
        $this->assertStringContainsString('WEBRTC_TURN_NODE_MUM_SECRET: set', $out);
        $this->assertStringContainsString('WEBRTC_TURN_NODE_NCR_SECRET: MISSING', $out);
        $this->assertStringNotContainsString(self::SECRET, $out);
    }

    public function testEveryActiveNodeCoveredPassesAndADisabledNodeNeedsNoSecret(): void
    {
        $this->assertSame(0, $this->presence("WEBRTC_TURN_NODES=mum,ncr\nWEBRTC_TURN_NODE_MUM_SECRET=" . self::SECRET . "\nWEBRTC_TURN_NODE_NCR_SECRET = \"noida-coturn-secret-fedcba9876543210\"\n")[0]);
        $this->assertSame(0, $this->presence("WEBRTC_TURN_NODES=mum,ncr\nWEBRTC_TURN_NODE_NCR_STATUS=disabled\nWEBRTC_TURN_NODE_MUM_SECRET=" . self::SECRET . "\n")[0]);
        $this->assertSame(0, $this->presence("WEBRTC_TURN_NODES=mum,ncr\nWEBRTC_TURN_STATIC_AUTH_SECRET=" . self::SECRET . "\n")[0]);
        $this->assertSame(1, $this->presence("WEBRTC_TURN_NODES=mum,ncr\nWEBRTC_TURN_NODE_MUM_SECRET=\nWEBRTC_TURN_NODE_NCR_SECRET=\n")[0]);
        $this->assertSame(1, $this->presence("APP=1\n")[0], 'no relay node configured is not a pass');
    }

    /** @return array{0: int, 1: string} */
    private function check(string $mode, bool $relay, bool $ncrFails): array
    {
        file_put_contents($this->dir . '/api/.env', "WEBRTC_TURN_NODES=mum,ncr\n");
        file_put_contents($this->dir . '/api/lib/ice-kit/bin/probe.php', '<?php $s = implode(" ", $argv);'
            . ' if (str_contains($s, "--status")) { echo getenv("T_RELAY") === "1" ? "relay configured: yes\n" : "relay configured: NO\n"; exit(0); }'
            . ' if (str_contains($s, "--node=ncr") && getenv("T_NCR_FAIL") === "1") { echo "FAIL ncr\n"; exit(1); } echo "PASS\n"; exit(0);');
        file_put_contents($this->dir . '/api/lib/ice-kit/bin/entitlement-fixture.php', '<?php echo "state unknown\n";');

        return $this->shell(['bash', $this->script('ci/turn-staging-check.sh'), $this->dir, PHP_BINARY, 'remote', $mode], ['T_RELAY' => $relay ? '1' : '0', 'T_NCR_FAIL' => $ncrFails ? '1' : '0']);
    }

    public function testStrictModeFailsUnlessTheRelayIsConfiguredAndBothNodesPass(): void
    {
        [$ok, $out] = $this->check('strict', true, false);
        $this->assertSame(0, $ok, $out);
        $this->assertStringContainsString("=== verdict", $out);

        $this->assertSame(1, $this->check('strict', true, true)[0], 'a failing node fails the step');
        $this->assertSame(1, $this->check('strict', false, false)[0], 'relay configured: NO fails the step');
        $this->assertSame(0, $this->check('', false, true)[0], 'without strict (sandbox) a failure stays a reported result');
    }

    public function testTheProductionWorkflowRunsTheCheckStrictlyAndFailsOnAMissingSecret(): void
    {
        $wf   = (string) file_get_contents(dirname(__DIR__, 3) . '/.github/workflows/deploy-production.yml');
        $step = substr($wf, (int) strpos($wf, '- name: TURN check (read-only)'));
        $step = substr($step, 0, (int) strpos($step, '- name: Fail when production TURN credentials are missing'));

        $this->assertStringNotContainsString('continue-on-error', $step);
        $this->assertStringContainsString('remote strict', $step);
        $this->assertStringContainsString('scripts/ci/turn-secret-presence.sh', $wf);
        $this->assertStringContainsString("env.TURN_SECRETS_MISSING == '1'", $wf);
        $this->assertFileExists(dirname(__DIR__, 3) . '/.github/workflows/turn-check-production.yml');
    }

    public function testThePromotionScriptChangesNothingOffTheHost(): void
    {
        $missing = $this->dir . '/nope/.env';
        [$code, $out] = $this->shell(['bash', $this->script('ops/promote-turn-secrets.sh'), 'check',
            '--remote-staging=' . $missing, '--connect-staging=' . $missing, '--remote-prod=' . $missing, '--connect-prod=' . $missing]);

        $this->assertSame(2, $code, $out); // not root, or not the host that holds the four .env files
        $this->assertFileDoesNotExist($missing);
    }
}
