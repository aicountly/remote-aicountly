<?php

declare(strict_types=1);

namespace Tests\Deploy;

use PHPUnit\Framework\TestCase;

/**
 * The cPanel deploys rsync backend/ onto <document root>/api/ with --delete. writable/ there holds
 * server-only state — writable/ice/ (TURN node health, the admission books, learned client latency,
 * the licence cache), logs, cache, sessions — and the old excludes covered only cache, logs and
 * session, so every deploy wiped writable/ice/. This pins 'P /writable/***' in both workflows and,
 * where rsync is installed, replays the workflow's own filter list against a scratch "server" to
 * prove what survives and what is still cleaned. Connect has the same rule and test.
 *
 * @internal
 */
final class DeployRsyncProtectsWritableTest extends TestCase
{
    private const API_STEP = 'Deploy backend to the api folder';

    /** @var array<string, string> */
    private const WORKFLOWS = [
        'production' => __DIR__ . '/../../../.github/workflows/deploy-production.yml',
        'sandbox'    => __DIR__ . '/../../../.github/workflows/deploy-sandbox.yml',
    ];

    /** @var list<string> */
    private array $scratch = [];

    protected function tearDown(): void
    {
        foreach ($this->scratch as $dir) {
            shell_exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function workflows(): iterable
    {
        foreach (array_keys(self::WORKFLOWS) as $name) {
            yield $name => [$name];
        }
    }

    /**
     * @dataProvider workflows
     */
    public function testApiStepProtectsWritableFirst(string $workflow): void
    {
        [$command, $filters] = self::apiStep($workflow);

        $this->assertStringContainsString('--delete', $command, 'the API deploy must still remove stale code');
        $this->assertSame('--filter=P /writable/***', $filters[0], 'writable/ protected with "/***", and first (rsync filters are first-match)');
        foreach (['--exclude=.env', '--exclude=.env.*', '--exclude=tests/', '--exclude=phpunit*'] as $expected) {
            $this->assertContains($expected, $filters, $workflow . ': ' . $expected);
        }
        $this->assertStringNotContainsString('--delete-excluded', $command);
    }

    public function testProductionAndSandboxShipTheSameFilters(): void
    {
        $this->assertSame(self::apiStep('production')[1], self::apiStep('sandbox')[1]);
    }

    /**
     * @dataProvider workflows
     */
    public function testReplayingTheWorkflowFiltersKeepsServerStateAndCleansCode(string $workflow): void
    {
        $rsync = trim((string) shell_exec('command -v rsync'));
        if ($rsync === '') {
            $this->markTestSkipped('rsync is not installed; the static filter assertions above still ran.');
        }

        [, $filters] = self::apiStep($workflow);

        $root = sys_get_temp_dir() . '/remote-rsync-' . bin2hex(random_bytes(6));
        $this->scratch[] = $root;
        $src = $root . '/backend';
        $dst = $root . '/api';

        // The checkout: code plus the tracked writable/ skeleton.
        self::put($src, [
            'public/index.php'         => 'front controller v2',
            'app/Controllers/Kept.php' => 'v2',
            'vendor/pkg/new.php'       => 'v2',
            'writable/index.html'      => 'skeleton v2',
            'writable/.htaccess'       => 'deny from all',
            'writable/cache/index.html' => '',
            'writable/logs/index.html'  => '',
            'writable/uploads/index.html' => '',
        ]);

        // The server after an earlier deploy plus real use.
        self::put($dst, [
            'public/index.php'               => 'front controller v1',
            '.env'                           => 'SECRET=1',
            'app/Controllers/Kept.php'       => 'v1',
            'app/Controllers/Removed.php'    => 'stale code',
            'vendor/pkg/removed.php'         => 'stale vendor',
            'writable/index.html'            => 'skeleton v1',
            'writable/ice/turn-health.json'  => '{"mum":"up"}',
            'writable/ice/turn-leases.json'  => '{"leases":[]}',
            'writable/ice/licence-cache.json' => '{}',
            'writable/uploads/x.bin'         => 'bytes',
            'writable/debugbar/debug.json'   => '{}',
            'writable/logs/log-2026-10-22.log' => 'the server\'s own log',
            'writable/cache/ratelimit_abc'   => 'bucket',
            'writable/session/ci_session1'   => 'session',
        ], time() - 86400);

        $argv = array_merge([$rsync, '-a', '--delete'], $filters, [$src . '/', $dst . '/']);
        $proc = proc_open($argv, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out  = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        $this->assertSame(0, proc_close($proc), 'rsync failed: ' . $out);

        foreach ([
            'writable/ice/turn-health.json', 'writable/ice/turn-leases.json', 'writable/ice/licence-cache.json',
            'writable/uploads/x.bin', 'writable/debugbar/debug.json', 'writable/logs/log-2026-10-22.log',
            'writable/cache/ratelimit_abc', 'writable/session/ci_session1', '.env',
        ] as $kept) {
            $this->assertFileExists($dst . '/' . $kept, $workflow . ' deleted server state: ' . $kept);
        }
        foreach (['app/Controllers/Removed.php', 'vendor/pkg/removed.php'] as $gone) {
            $this->assertFileDoesNotExist($dst . '/' . $gone, $workflow . ' no longer cleans: ' . $gone);
        }
        $this->assertSame('v2', file_get_contents($dst . '/app/Controllers/Kept.php'));
        $this->assertSame('skeleton v2', file_get_contents($dst . '/writable/index.html'), 'the tracked skeleton is still updated');
        $this->assertFileExists($dst . '/writable/.htaccess');
    }

    /**
     * The API rsync step: its full command text and its filter arguments in order, as rsync receives them.
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function apiStep(string $workflow): array
    {
        $yaml = (string) file_get_contents(self::WORKFLOWS[$workflow]);
        $start = strpos($yaml, '- name: ' . self::API_STEP);
        self::assertNotFalse($start, $workflow . ': step "' . self::API_STEP . '" not found');
        $next = strpos($yaml, "\n      - name:", $start + 1);
        $step = substr($yaml, $start, $next === false ? null : $next - $start);

        self::assertMatchesRegularExpression('/rsync -avz --delete\b/', $step);
        preg_match_all("/(--(?:include|exclude|filter))='([^']*)'/", $step, $m, PREG_SET_ORDER);
        $filters = array_map(static fn (array $hit): string => $hit[1] . '=' . $hit[2], $m);
        self::assertNotSame([], $filters);

        return [$step, $filters];
    }

    /** @param array<string, string> $files */
    private static function put(string $base, array $files, ?int $mtime = null): void
    {
        foreach ($files as $path => $content) {
            $full = $base . '/' . $path;
            if (! is_dir(dirname($full))) {
                mkdir(dirname($full), 0777, true);
            }
            file_put_contents($full, $content);
            if ($mtime !== null) {
                touch($full, $mtime);
            }
        }
    }
}
