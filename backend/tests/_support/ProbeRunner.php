<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Runs tests/support/database_probe.php: one piece of Remote, in a child process with a real CodeIgniter boot, given exactly
 * the environment the test passes (the developer's own .env is never read).
 */
final class ProbeRunner
{
    /**
     * @param array<string, string> $env
     *
     * @return array<string, mixed>
     */
    public static function run(string $mode, array $env): array
    {
        $env = $env + ['PATH' => (string) getenv('PATH'), 'HOME' => sys_get_temp_dir(), 'PROBE_MODE' => $mode];
        $proc = proc_open([PHP_BINARY, __DIR__ . '/database_probe.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (! is_resource($proc)) {
            throw new \RuntimeException('could not start the probe');
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        proc_close($proc);

        $decoded = json_decode($out, true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('probe output: ' . $out . $err);
        }

        return $decoded;
    }
}
