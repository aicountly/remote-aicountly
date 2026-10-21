<?php

declare(strict_types=1);

namespace Tests\Deploy;

use PHPUnit\Framework\TestCase;

/**
 * bin/ops/inject-turn-secrets.sh — how coturn's secrets reach an API host.
 *
 * The properties that matter: secrets arrive on STDIN only, only TURN secret keys are writable, a
 * value that would break the .env parser (or is plainly a placeholder) is refused, two nodes cannot be
 * given the same secret, an unset repository secret never blanks a working .env, and no value ever
 * appears in the script's output.
 *
 * @internal
 */
final class InjectTurnSecretsTest extends TestCase
{
    private const MUM = 'mumbai-coturn-secret-0123456789abcdef';
    private const NCR = 'noida-coturn-secret-fedcba9876543210';

    private string $dir = '';
    private string $env = '';

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || trim((string) shell_exec('command -v bash')) === '') {
            $this->markTestSkipped('bash is needed to run the deploy script.');
        }

        $this->dir = sys_get_temp_dir() . '/inject-turn-' . bin2hex(random_bytes(5));
        mkdir($this->dir, 0700);
        $this->env = $this->dir . '/.env';
        file_put_contents($this->env, "CI_ENVIRONMENT = production\nWEBRTC_TURN_NODES = mum,ncr\n# comment kept\ndatabase.default.password = \"keep me\"\n");
        chmod($this->env, 0640);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->dir);
    }

    /** @return array{0: int, 1: string} exit code and everything the script printed */
    private function inject(string $stdin, string ...$options): array
    {
        $script = dirname(__DIR__, 2) . '/bin/ops/inject-turn-secrets.sh';
        $cmd    = array_merge(['bash', $script, '--env=' . $this->env], $options);
        $proc   = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $out];
    }

    public function testNodeSecretsAreWrittenAndNothingElseInTheFileChanges(): void
    {
        [$code, $out] = $this->inject('WEBRTC_TURN_NODE_MUM_SECRET=' . self::MUM . "\nWEBRTC_TURN_NODE_NCR_SECRET=" . self::NCR . "\n");

        $this->assertSame(0, $code, $out);
        $file = (string) file_get_contents($this->env);
        $this->assertStringContainsString('WEBRTC_TURN_NODE_MUM_SECRET=' . self::MUM, $file);
        $this->assertStringContainsString('WEBRTC_TURN_NODE_NCR_SECRET=' . self::NCR, $file);
        $this->assertStringContainsString("CI_ENVIRONMENT = production\nWEBRTC_TURN_NODES = mum,ncr\n# comment kept\ndatabase.default.password = \"keep me\"\n", $file);
    }

    public function testNoSecretValueEverAppearsInTheOutput(): void
    {
        [, $out] = $this->inject('WEBRTC_TURN_NODE_MUM_SECRET=' . self::MUM . "\n");
        $this->assertStringNotContainsString(self::MUM, $out);
        $this->assertStringContainsString('WEBRTC_TURN_NODE_MUM_SECRET: written.', $out);

        [, $again] = $this->inject('WEBRTC_TURN_NODE_MUM_SECRET=' . self::MUM . "\n");
        $this->assertStringContainsString('already current', $again);
        $this->assertStringNotContainsString(self::MUM, $again);

        // Even a refusal does not echo what it refused.
        [, $refused] = $this->inject("WEBRTC_TURN_NODE_MUM_SECRET=has a space in it and is long enough\n");
        $this->assertStringNotContainsString('has a space', $refused);
    }

    public function testRunningItAgainReplacesTheValueInPlaceWithoutDuplicatingTheLine(): void
    {
        $this->inject('WEBRTC_TURN_NODE_MUM_SECRET=' . self::MUM . "\n");
        $this->inject('WEBRTC_TURN_NODE_MUM_SECRET=rotated-secret-0123456789abcdef' . "\n");

        $file = (string) file_get_contents($this->env);
        $this->assertSame(1, substr_count($file, 'WEBRTC_TURN_NODE_MUM_SECRET='));
        $this->assertStringContainsString('WEBRTC_TURN_NODE_MUM_SECRET=rotated-secret-0123456789abcdef', $file);
        $this->assertStringNotContainsString(self::MUM, $file);
    }

    public function testTheFilesModeSurvivesAndIsNeverWorldReadable(): void
    {
        $this->inject('WEBRTC_TURN_NODE_MUM_SECRET=' . self::MUM . "\n");
        clearstatcache();

        $this->assertSame(0, fileperms($this->env) & 0007, 'No world access.');
        $this->assertSame(0640, fileperms($this->env) & 0777, 'Owner and group access are as they were.');
    }

    public function testOnlyTurnSecretKeysCanBeWritten(): void
    {
        foreach (['DB_PASSWORD', 'database.default.password', 'WEBRTC_TURN_NODES', 'WEBRTC_TURN_TTL', 'remote.signallingSecret', 'WEBRTC_TURN_NODE_MUM_HOST'] as $key) {
            [$code] = $this->inject($key . '=' . self::MUM . "\n");
            $this->assertSame(2, $code, "{$key} must be refused");
        }
        $this->assertStringNotContainsString(self::MUM, (string) file_get_contents($this->env));
    }

    public function testValuesThatWouldBreakTheEnvParserOrArePlaceholdersAreRefused(): void
    {
        foreach (['changeme', '<coturn-secret>', 'has space in the middle 0123456789', 'quote"inside0123456789abcdef', 'hash#inside0123456789abcdef', str_repeat('a', 300)] as $value) {
            [$code] = $this->inject('WEBRTC_TURN_NODE_MUM_SECRET=' . $value . "\n");
            $this->assertSame(2, $code, "'{$value}' must be refused");
        }
        $this->assertStringNotContainsString('WEBRTC_TURN_NODE_MUM_SECRET', (string) file_get_contents($this->env));
    }

    public function testTwoNodesCannotBeGivenTheSameSecret(): void
    {
        [$code, $out] = $this->inject('WEBRTC_TURN_NODE_MUM_SECRET=' . self::MUM . "\nWEBRTC_TURN_NODE_NCR_SECRET=" . self::MUM . "\n");

        $this->assertSame(2, $code);
        $this->assertStringContainsString('same secret', $out);
        $this->assertStringNotContainsString('WEBRTC_TURN_NODE_', (string) file_get_contents($this->env), 'Nothing was written.');

        [$allowed] = $this->inject('WEBRTC_TURN_NODE_MUM_SECRET=' . self::MUM . "\nWEBRTC_TURN_NODE_NCR_SECRET=" . self::MUM . "\n", '--allow-shared');
        $this->assertSame(0, $allowed);
    }

    public function testAnUnsetRepositorySecretNeverBlanksAWorkingEnv(): void
    {
        $this->inject('WEBRTC_TURN_NODE_MUM_SECRET=' . self::MUM . "\n");
        [$code, $out] = $this->inject("WEBRTC_TURN_NODE_MUM_SECRET=\n");

        $this->assertSame(0, $code);
        $this->assertStringContainsString('empty, ignored', $out);
        $this->assertStringContainsString('WEBRTC_TURN_NODE_MUM_SECRET=' . self::MUM, (string) file_get_contents($this->env));
    }

    public function testItRefusesToRunWithoutAnEnvToWriteInto(): void
    {
        unlink($this->env);
        [$code] = $this->inject('WEBRTC_TURN_NODE_MUM_SECRET=' . self::MUM . "\n");

        $this->assertSame(3, $code);
        $this->assertFileDoesNotExist($this->env);
    }
}
