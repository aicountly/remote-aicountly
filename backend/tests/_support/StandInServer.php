<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Starts tests/_support/standin as a real HTTP server for one test class, so
 * Remote's own clients (PortalClient, ManageClient) are exercised end to end
 * rather than against a mocked method.
 */
final class StandInServer
{
    /** @var resource|null */
    private static $process = null;
    private static string $log = '';

    public static function start(): string
    {
        $port = (int) (getenv('STANDIN_PORT') ?: 20871);
        $dir  = __DIR__ . '/standin';

        self::$log = tempnam(sys_get_temp_dir(), 'standin');
        $env = array_merge(getenv(), ['STANDIN_LOG' => self::$log]);

        self::$process = proc_open(
            ['php', '-S', '127.0.0.1:' . $port, '-t', $dir, $dir . '/index.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            $env,
        );

        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($socket !== false) {
                fclose($socket);

                return 'http://127.0.0.1:' . $port;
            }
            usleep(100000);
        }

        self::stop();

        throw new RuntimeException('The contract stand-in did not start on port ' . $port . '.');
    }

    public static function stop(): void
    {
        if (self::$process !== null) {
            proc_terminate(self::$process);
            proc_close(self::$process);
            self::$process = null;
        }
        if (self::$log !== '' && is_file(self::$log)) {
            unlink(self::$log);
        }
    }

    /** @return list<string> the requests the stand-in has seen, "METHOD path comp_id bearer" */
    public static function requests(): array
    {
        return self::$log !== '' && is_file(self::$log)
            ? array_values(array_filter(explode("\n", (string) file_get_contents(self::$log))))
            : [];
    }

    public static function forget(): void
    {
        if (self::$log !== '' && is_file(self::$log)) {
            file_put_contents(self::$log, '');
        }
    }
}
