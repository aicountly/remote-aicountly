<?php

declare(strict_types=1);

namespace Tests\Deploy;

use PHPUnit\Framework\TestCase;

/**
 * The S01 host has Remi's PHP (/opt/remi/phpNN/root/usr/bin/php), not cPanel EasyApache, and a deploy user may have
 * no php on its non-interactive PATH (production run 44878249158 stopped with "No PHP 8.2+ with pgsql ... found").
 * scripts/ci/remote-migrate.sh and scripts/ci/turn-staging-check.sh fall back to /opt/remi/php84 ... php81, still
 * taking only a PHP that meets their requirements.
 *
 * Each case runs a copy of the script in which /opt/remi/phpNN points into a temporary folder, the cPanel and
 * /usr/local/bin candidates point nowhere, and PATH holds no php.
 *
 * @internal
 */
final class RemiPhpDiscoveryTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || trim((string) shell_exec('command -v bash')) === '') {
            $this->markTestSkipped('bash is needed to run the CI scripts.');
        }

        $this->dir = sys_get_temp_dir() . '/remi-php-' . bin2hex(random_bytes(5));
        mkdir($this->dir . '/bin', 0700, true);
        mkdir($this->dir . '/site/api/lib/ice-kit/bin', 0700, true);
        // Only the tools the scripts use; deliberately no php.
        foreach (['bash', 'grep', 'sed', 'awk', 'head', 'tail', 'cat', 'hostname', 'mktemp', 'rm'] as $tool) {
            $path = trim((string) shell_exec('command -v ' . escapeshellarg($tool)));
            if ($path !== '' && $path[0] === '/') {
                symlink($path, $this->dir . '/bin/' . $tool);
            }
        }
    }

    protected function tearDown(): void
    {
        shell_exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** A copy of scripts/ci/<name> whose PHP candidates point into the temporary folder. */
    private function script(string $name): string
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/scripts/ci/' . $name);
        $out = preg_replace('#/opt/remi/(php8[1-4])/root/usr/bin/php#', $this->dir . '/remi/$1/root/usr/bin/php', $src, -1, $remi);
        $this->assertSame(4, $remi, $name . ' must try /opt/remi/php84, php83, php82 and php81');
        $out = str_replace(['/opt/cpanel/', '/usr/local/bin/php'], [$this->dir . '/absent/cpanel/', $this->dir . '/absent/php'], (string) $out);
        file_put_contents($this->dir . '/' . $name, $out);

        return $this->dir . '/' . $name;
    }

    private function remi(string $version, string $body): string
    {
        $bin = $this->dir . '/remi/' . $version . '/root/usr/bin';
        if (! is_dir($bin)) {
            mkdir($bin, 0700, true);
        }
        file_put_contents($bin . '/php', "#!/bin/bash\n" . $body . "\n");
        chmod($bin . '/php', 0755);

        return $bin . '/php';
    }

    /** A PHP for remote-migrate.sh: answers its requirement check with $usable, and spark remote:db-check with OK. */
    private function migratePhp(string $version, bool $usable): string
    {
        return $this->remi($version, 'case "$1" in'
            . ' -r) case "$2" in *PHP_VERSION_ID*) exit ' . ($usable ? 0 : 1) . ' ;; *) echo 8.4.27; exit 0 ;; esac ;;'
            . ' spark) echo "db-check by ' . $version . '"; exit 0 ;;'
            . ' esac');
    }

    /** @return array{0: int, 1: string} */
    private function shell(array $cmd): array
    {
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['PATH' => $this->dir . '/bin', 'HOME' => $this->dir]);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $out];
    }

    /** @return array{0: int, 1: string} */
    private function migrate(string $php = ''): array
    {
        touch($this->dir . '/site/api/spark');
        touch($this->dir . '/site/api/.env');

        return $this->shell([$this->dir . '/bin/bash', $this->script('remote-migrate.sh'), $this->dir . '/site', $php, 'status']);
    }

    public function testMigrationsFindRemiPhpWhenNothingElseIsThere(): void
    {
        $this->migratePhp('php84', false); // lacks pgsql / intl / mbstring
        $php83 = $this->migratePhp('php83', true);
        $this->migratePhp('php82', true);

        [$code, $out] = $this->migrate();

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Using ' . $php83 . ' (PHP 8.4.27)', $out);
        $this->assertStringContainsString('db-check by php83', $out);
    }

    public function testMigrationsStillRefuseARemiPhpThatFailsTheRequirements(): void
    {
        $this->migratePhp('php81', false); // PHP 8.1 is below 8.2

        [$code, $out] = $this->migrate();

        $this->assertSame(3, $code, $out);
        $this->assertStringContainsString('No PHP 8.2+ with pgsql, intl and mbstring found', $out);
        $this->assertStringContainsString('/opt/remi/php84/83/82/81', $out);
    }

    public function testTheExplicitPhpCliStillComesFirst(): void
    {
        $this->migratePhp('php84', true);
        $named = $this->migratePhp('php82', true);

        [$code, $out] = $this->migrate($named);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Using ' . $named, $out);
    }

    public function testTheTurnCheckFindsRemiPhp(): void
    {
        file_put_contents($this->dir . '/site/api/.env', "WEBRTC_TURN_NODES=mum,ncr\n");
        file_put_contents($this->dir . '/site/api/lib/ice-kit/bin/probe.php', '<?php echo str_contains(implode(" ", $argv), "--status") ? "relay configured: yes\n" : "PASS\n";');
        $this->remi('php84', 'exit 1'); // not usable
        $php83 = $this->remi('php83', 'exec ' . escapeshellarg(PHP_BINARY) . ' "$@"');

        [, $out] = $this->shell([$this->dir . '/bin/bash', $this->script('turn-staging-check.sh'), $this->dir . '/site', '', 'remote']);

        $this->assertStringNotContainsString('no PHP 8.2+ with openssl found', $out);
        $this->assertStringContainsString('php ' . PHP_VERSION, $out);
        $this->assertStringContainsString('relay configured: yes', $out);
        $this->assertFileExists($php83);
    }

    public function testTheTurnCheckNamesRemiWhenNoPhpIsFound(): void
    {
        [$code, $out] = $this->shell([$this->dir . '/bin/bash', $this->script('turn-staging-check.sh'), $this->dir . '/site', '', 'remote', 'strict']);

        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('no PHP 8.2+ with openssl found', $out);
        $this->assertStringContainsString('/opt/remi/php84/83/82/81', $out);
        $this->assertStringContainsString('FAIL: no usable PHP', $out);
    }
}
