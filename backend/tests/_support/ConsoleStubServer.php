<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * A real HTTP stand-in for Console's GET /api/database-details/resolve (tests/support/console_db_details_stub.php run by
 * `php -S`), so the curl request, the headers, the timeouts and the redirect / size rules are exercised for real.
 *
 * One instance per test class. The test steers it through a JSON file (see the stub for the fields) and reads what
 * arrived from a JSON-lines file.
 */
final class ConsoleStubServer
{
    /** @var resource|null */
    private $process = null;

    private string $dir;

    public function __construct(private readonly int $port)
    {
        $this->dir = sys_get_temp_dir() . '/remote-console-stub-' . bin2hex(random_bytes(4));
    }

    /** @throws \RuntimeException when the port is taken or the server does not come up */
    public function start(): void
    {
        mkdir($this->dir, 0700);

        // Whatever already answers on the port is not our stub: say so rather than test against it.
        $busy = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.2);
        if ($busy !== false) {
            fclose($busy);

            throw new \RuntimeException('port ' . $this->port . ' is already in use; set REMOTE_CONSOLE_STUB_PORT to a free port');
        }

        $env = [
            'PATH'                      => (string) getenv('PATH'),
            'REMOTE_CONSOLE_STUB_STATE'  => $this->dir . '/state.json',
            'REMOTE_CONSOLE_STUB_LOG'    => $this->dir . '/requests.jsonl',
            'PHP_CLI_SERVER_WORKERS'    => '4',
        ];
        $cmd  = [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, __DIR__ . '/console_db_details_stub.php'];
        $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->dir . '/server.log', 'w'], 2 => ['file', $this->dir . '/server.log', 'w']], $pipes, null, $env);
        if (! is_resource($proc)) {
            throw new \RuntimeException('could not start the Console stub');
        }
        $this->process = $proc;

        for ($i = 0; $i < 50; $i++) {
            $sock = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.2);
            if ($sock !== false) {
                fclose($sock);

                return;
            }
            usleep(100000);
        }

        throw new \RuntimeException('the Console stub did not come up on port ' . $this->port . ': ' . (string) @file_get_contents($this->dir . '/server.log'));
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    /** The Console API base, as CONSOLE_API_URL holds it. */
    public function url(): string
    {
        return 'http://127.0.0.1:' . $this->port . '/api';
    }

    /** @param array<string, mixed> $state */
    public function control(array $state): void
    {
        file_put_contents($this->dir . '/state.json', json_encode($state));
    }

    public function forgetRequests(): void
    {
        @unlink($this->dir . '/requests.jsonl');
    }

    /** @return list<array{method: string, path: string, query: string, headers: array<string, string>}> */
    public function requests(): array
    {
        $file = $this->dir . '/requests.jsonl';
        if (! is_file($file)) {
            return [];
        }

        return array_map(static fn (string $line): array => json_decode($line, true), array_values(array_filter(explode("\n", (string) file_get_contents($file)))));
    }
}
