<?php

declare(strict_types=1);

namespace Tests\Deploy;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * No response of the API names the PHP version.
 *
 * PHP adds `X-Powered-By: PHP/8.x.y` while php.ini's expose_php is on, and php.ini is the host's. A header only
 * exists outside the CLI, so this serves the real front controller with `php -S` (expose_php forced on) and reads
 * what comes back: a redirect from a filter that short-circuits, a success and a refusal. backend/.htaccess unsets
 * the header as well, for Apache.
 *
 * @internal
 */
final class ApiPoweredByTest extends TestCase
{
    private const API = __DIR__ . '/../..';

    /** @var list<resource> */
    private array $servers = [];

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            proc_terminate($server);
            proc_close($server);
        }
        $this->servers = [];

        parent::tearDown();
    }

    public function testTheHtaccessUnsetsTheHeaderInBothTables(): void
    {
        $htaccess = (string) file_get_contents(self::API . '/.htaccess');

        $this->assertMatchesRegularExpression(
            '/<IfModule mod_headers\.c>(?:(?!<\/IfModule>).)*Header always unset X-Powered-By(?:(?!<\/IfModule>).)*<\/IfModule>/s',
            $htaccess,
        );
        $this->assertMatchesRegularExpression(
            '/<IfModule mod_headers\.c>(?:(?!<\/IfModule>).)*Header unset X-Powered-By(?:(?!<\/IfModule>).)*<\/IfModule>/s',
            $htaccess,
        );
    }

    public function testNoResponseNamesThePhpVersion(): void
    {
        // As deployed: plain http is redirected by a filter, before any controller or after-filter runs.
        $redirecting = $this->serve([]);
        [$status, $headers] = $this->get($redirecting . '/health');
        $this->assertSame(307, $status);
        $this->assertArrayNotHasKey('x-powered-by', $headers);

        // Served over plain http for the test: a success, and a refusal from a filter.
        $plain = $this->serve(['app.forceGlobalSecureRequests' => 'false']);
        foreach (['/health', '/v1/remote/bootstrap'] as $path) {
            [$status, $headers] = $this->get($plain . $path);
            $this->assertContains($status, [200, 401, 503], "{$path} was answered by the API");
            $this->assertArrayHasKey('x-content-type-options', $headers, "{$path} came through the API's own headers");
            $this->assertArrayNotHasKey('x-powered-by', $headers, "{$path} names the PHP version");
        }
    }

    /**
     * The API under `php -S`, with expose_php on whatever the CLI's php.ini says.
     *
     * @param array<string, string> $env
     */
    private function serve(array $env): string
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        if ($probe === false) {
            throw new RuntimeException('No free port for the API under test.');
        }
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $server = proc_open(
            [PHP_BINARY, '-d', 'expose_php=On', '-S', '127.0.0.1:' . $port, '-t', 'public', 'vendor/codeigniter4/framework/system/rewrite.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            self::API,
            [
                'PATH'             => (string) getenv('PATH'),
                'CI_ENVIRONMENT'   => 'production',
                'app.baseURL'      => 'http://127.0.0.1:' . $port . '/',
                'logger.threshold' => '0',
            ] + $env,
        );
        if ($server === false) {
            throw new RuntimeException('php -S did not start.');
        }
        $this->servers[] = $server;

        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($socket !== false) {
                fclose($socket);

                return 'http://127.0.0.1:' . $port;
            }
            usleep(100000);
        }

        throw new RuntimeException('The API did not start on port ' . $port . '.');
    }

    /** @return array{0: int, 1: array<string, string>} the status and the headers, by lower-cased name */
    private function get(string $url): array
    {
        $context = stream_context_create(['http' => ['follow_location' => 0, 'ignore_errors' => true, 'timeout' => 10]]);
        @file_get_contents($url, false, $context);

        $lines   = $http_response_header ?? [];
        $status  = (int) (explode(' ', (string) ($lines[0] ?? ''))[1] ?? 0);
        $headers = [];
        foreach (array_slice($lines, 1) as $line) {
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $headers[strtolower(trim($name))] = trim($value);
        }

        return [$status, $headers];
    }
}
