<?php

declare(strict_types=1);

namespace Tests\Deploy;

use PHPUnit\Framework\TestCase;

/**
 * bin/ops/apply-turn-config.sh — the non-secret half of a TURN rollout. Only WEBRTC_TURN_/STUN_/GEO_ keys can
 * be written, a secret is refused (it has its own stdin-only path), enforcement and a node kept out of the
 * environment need an explicit decision, and re-running it changes nothing.
 *
 * @internal
 */
final class ApplyTurnConfigTest extends TestCase
{
    private string $dir = '';
    private string $env = '';

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || trim((string) shell_exec('command -v bash')) === '') {
            $this->markTestSkipped('bash is needed to run the deploy script.');
        }
        $this->dir = sys_get_temp_dir() . '/apply-turn-' . bin2hex(random_bytes(5));
        mkdir($this->dir, 0700);
        $this->env = $this->dir . '/.env';
        file_put_contents($this->env, "CI_ENVIRONMENT = production\n# kept\ndatabase.default.password = \"keep me\"\n");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            is_file($file) && @unlink($file);
        }
        @rmdir($this->dir);
    }

    /** @return array{0: int, 1: string} */
    private function apply(string $stdin, string ...$options): array
    {
        $script = dirname(__DIR__, 2) . '/bin/ops/apply-turn-config.sh';
        $proc   = proc_open(array_merge(['bash', $script, '--env=' . $this->env], $options), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $out];
    }

    public function testSettingsAreWrittenAndRewrittenInPlace(): void
    {
        $dsn = 'WEBRTC_TURN_LEDGER_DSN=pgsql:host=127.0.0.1;port=5432;dbname=aicountly_turn';
        [$code, $out] = $this->apply("WEBRTC_TURN_ADMISSION=observe\n" . $dsn . "\n");

        $this->assertSame(0, $code, $out);
        $file = (string) file_get_contents($this->env);
        $this->assertStringContainsString("CI_ENVIRONMENT = production\n# kept\ndatabase.default.password = \"keep me\"\n", $file);
        $this->assertStringContainsString('WEBRTC_TURN_ADMISSION = observe', $file);

        [, $again] = $this->apply("WEBRTC_TURN_ADMISSION=observe\n");
        $this->assertStringContainsString('already current', $again);

        $this->apply("WEBRTC_TURN_ADMISSION=off\n");
        $this->assertSame(1, substr_count((string) file_get_contents($this->env), 'WEBRTC_TURN_ADMISSION'));
    }

    public function testOnlyTurnStunAndGeoKeysCanBeWritten(): void
    {
        foreach (['DB_PASSWORD', 'database.default.hostname', 'CI_ENVIRONMENT', 'WEBRTC_OTHER_X', 'remote.signallingSecret'] as $key) {
            [$code] = $this->apply($key . "=value\n");
            $this->assertSame(2, $code, $key);
        }
    }

    public function testSecretsAreRefusedAndPointedAtTheStdinInjector(): void
    {
        foreach (['WEBRTC_TURN_NODE_MUM_SECRET', 'WEBRTC_TURN_STATIC_AUTH_SECRET', 'WEBRTC_TURN_LEDGER_PASSWORD', 'WEBRTC_TURN_CREDENTIAL', 'WEBRTC_LICENCE_S2S_SECRET'] as $key) {
            [$code, $out] = $this->apply($key . "=abcdefghijklmnopqrstuvwxyz\n");
            $this->assertSame(2, $code, $key);
            $this->assertStringContainsString('inject-turn-secrets.sh', $out);
            $this->assertStringNotContainsString('abcdefghijklmnopqrstuvwxyz', $out);
        }
        $this->assertStringNotContainsString('WEBRTC_TURN', (string) file_get_contents($this->env));
    }

    public function testValuesThatBreakTheEnvParserAreRefused(): void
    {
        foreach (['has space', 'quo"te', 'hash#tag', 'dollar$HOME'] as $value) {
            [$code] = $this->apply('WEBRTC_TURN_NODE_MUM_LABEL=' . $value . "\n");
            $this->assertSame(2, $code, $value);
        }
    }

    public function testEnforcementNeedsAnExplicitFlag(): void
    {
        [$code, $out] = $this->apply("WEBRTC_TURN_ADMISSION=enforce\n");
        $this->assertSame(2, $code);
        $this->assertStringContainsString('--allow-enforce', $out);

        [$code] = $this->apply("WEBRTC_TURN_ADMISSION=enforce\n", '--allow-enforce');
        $this->assertSame(0, $code);

        [$code] = $this->apply("WEBRTC_TURN_ADMISSION=maybe\n");
        $this->assertSame(2, $code);
    }

    public function testAKeptOutNodeCannotBeMadeActive(): void
    {
        [$code, $out] = $this->apply("WEBRTC_TURN_NODE_NCR_STATUS=active\n", '--keep-out=ncr');
        $this->assertSame(2, $code);
        $this->assertStringContainsString('kept out', $out);

        [$code] = $this->apply("WEBRTC_TURN_NODE_NCR_STATUS=disabled\nWEBRTC_TURN_NODE_MUM_STATUS=active\n", '--keep-out=ncr');
        $this->assertSame(0, $code);

        [$code] = $this->apply("WEBRTC_TURN_NODE_NCR_STATUS=active\n");
        $this->assertSame(0, $code, 'only an environment that asks to keep it out refuses');
    }

    public function testNothingIsWrittenWhenAnyLineIsRefused(): void
    {
        [$code] = $this->apply("WEBRTC_TURN_ADMISSION=observe\nDB_PASSWORD=x\n");
        $this->assertSame(2, $code);
        $this->assertStringNotContainsString('WEBRTC_TURN_ADMISSION', (string) file_get_contents($this->env));
    }

    public function testItRefusesToRunWithoutAnEnv(): void
    {
        unlink($this->env);
        [$code] = $this->apply("WEBRTC_TURN_ADMISSION=observe\n");
        $this->assertSame(3, $code);
    }

    public function testTheCentralLicenceSettingsAreConfigurationNotSecrets(): void
    {
        [$code, $out] = $this->apply("WEBRTC_LICENCE_API_URL=https://licence.example.test/api\nWEBRTC_LICENCE_ENVIRONMENT=sandbox\nWEBRTC_LICENCE_COMPAT=allow\nWEBRTC_LICENCE_PUBLIC_KEYS=stg-1=AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=\n");

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('WEBRTC_LICENCE_COMPAT = allow', (string) file_get_contents($this->env));
    }
}
