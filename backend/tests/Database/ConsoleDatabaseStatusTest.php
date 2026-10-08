<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Support\ConsoleDatabaseDetails;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\ConsoleStubServer;
use Tests\Support\ProbeRunner;

/**
 * What Remote SAYS when the database name and username cannot be had from Console (or Console is not being asked
 * although it was meant to be): GET /api/health, which is unauthenticated, so nothing from the exception may reach it.
 * Each case runs in a child process with a real CodeIgniter boot against a real HTTP stand-in for Console. The failure
 * cases need no PostgreSQL: they fail before, or at, the connection. The two that end in a working connection use the
 * suite's own test database (the `tests` connection group) read-only, and skip when it is not there.
 */
final class ConsoleDatabaseStatusTest extends TestCase
{
    private static ConsoleStubServer $console;

    private string $key = '';

    public static function setUpBeforeClass(): void
    {
        self::$console = new ConsoleStubServer((int) (getenv('REMOTE_CONSOLE_STUB_PORT_STATUS') ?: 8838));
        self::$console->start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$console->stop();
    }

    protected function setUp(): void
    {
        $this->key = 'key-' . bin2hex(random_bytes(8));
        self::$console->forgetRequests();
        self::$console->control([]);
    }

    /** The environment of a deployment that has Console configured. */
    private function consoleEnv(array $more = []): array
    {
        return $more + [
            'CONSOLE_API_URL'           => self::$console->url(),
            'CONSOLE_DB_DETAILS_KEY'    => $this->key,
            'database.default.database' => 'definitely_not_the_database',
            'database.default.username' => 'definitely_not_the_user',
            // Whatever Console says, nothing may be listening there: these cases end at (or before) the connection.
            'database.default.port'     => '1',
        ];
    }

    // -----------------------------------------------------------------------
    // Console refuses: /api/health names the reason, with the fixed sentence
    // -----------------------------------------------------------------------

    public function testARefusedKeyIsNamedOnTheHealthEndpoint(): void
    {
        self::$console->control(['expect_key' => 'a-different-key']); // 401
        $hint = ConsoleDatabaseDetails::hint('console_key_rejected');
        $this->assertNotSame('', $hint);

        $health = ProbeRunner::run('health', $this->consoleEnv());

        $this->assertSame(503, $health['code']);
        $body = $health['body'];
        $this->assertSame('degraded', $body['status']);
        $this->assertSame('unavailable', $body['database']);
        $this->assertSame('console', $body['databaseSource']);
        $this->assertSame('console_key_rejected', $body['databaseReason']);
        $this->assertSame($hint, $body['databaseHint'], 'the sentence is the fixed one, not the exception');
        $this->assertSame('AICOUNTLY Remote', $body['app']);

        $all = (string) json_encode($health);
        $this->assertStringNotContainsString($this->key, $all, 'no part of the answer carries the key');
        $this->assertStringNotContainsString('a-different-key', $all);
        $this->assertStringNotContainsString('definitely_not_the_', $all, 'nor a name or user from .env');
        $this->assertStringNotContainsString('127.0.0.1', $all, 'nor an address');
    }

    /** @param array<string, mixed> $control */
    #[DataProvider('failures')]
    public function testEachConsoleFailureIsNamedOnTheHealthEndpoint(array $control, string $reason): void
    {
        self::$console->control(array_merge(['expect_key' => $this->key], $control));

        $body = ProbeRunner::run('health', $this->consoleEnv())['body'];

        $this->assertSame('unavailable', $body['database']);
        $this->assertSame($reason, $body['databaseReason']);
        $this->assertSame(ConsoleDatabaseDetails::hint($reason), $body['databaseHint'], 'the sentence for that category and nothing else');
        $this->assertSame('console', $body['databaseSource']);
        $this->assertStringNotContainsString($this->key, (string) json_encode($body));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function failures(): array
    {
        return [
            'a revoked key'                => [['expect_key' => 'another-key'], 'console_key_rejected'],
            'an inactive row'              => [['status' => 403], 'console_row_inactive'],
            'Console down'                 => [['status' => 503], 'console_unreachable'],
            'an answer that is not JSON'   => [['raw' => '<html>maintenance</html>'], 'console_unexpected_answer'],
            'nothing recorded'             => [['data' => ['database_name' => '', 'database_username' => '']], 'console_no_database_recorded'],
        ];
    }

    public function testTheOtherEnvironmentsKeyIsNamedWhenRemoteEnvironmentSaysProduction(): void
    {
        self::$console->control(['expect_key' => $this->key, 'data' => ['environment' => 'sandbox']]);

        $body = ProbeRunner::run('health', $this->consoleEnv(['REMOTE_ENVIRONMENT' => 'production']))['body'];

        $this->assertSame('console_environment_mismatch', $body['databaseReason']);
    }

    public function testTheOtherEnvironmentsKeyIsNamedFromTheSitesOwnBaseUrl(): void
    {
        // No REMOTE_ENVIRONMENT: the deploy pins app.baseURL from the site's host, and that is what names the deployment.
        self::$console->control(['expect_key' => $this->key, 'data' => ['environment' => 'production']]);

        $body = ProbeRunner::run('health', $this->consoleEnv(['app.baseURL' => 'https://remote.gh.aicountly.com/api/']))['body'];

        $this->assertSame('console_environment_mismatch', $body['databaseReason']);
    }

    // -----------------------------------------------------------------------
    // Console is not being asked
    // -----------------------------------------------------------------------

    public function testAKeyUnderTheWrongNameIsNamedWhenNothingElseNamesADatabase(): void
    {
        // The state this is for: CONSOLE_API_URL is there, the key is under another name (the cron-monitor key and the
        // shared service key is another credential Remote has), and database.default.database / .username have been
        // commented out because Console should name them.
        $env = [
            'CONSOLE_API_URL'          => self::$console->url(),
            'CONSOLE_SERVICE_KEY'      => 'sdb_' . str_repeat('b', 64),
            'database.default.port'    => '1',
        ];
        $hint = ConsoleDatabaseDetails::hint('console_key_missing');

        $body = ProbeRunner::run('health', $env)['body'];

        $this->assertSame([false, 'env', 'console_key_missing', $hint], [($body['database'] === 'ok'), $body['databaseSource'], $body['databaseReason'], $body['databaseHint']]);
        $this->assertStringContainsString('CONSOLE_DB_DETAILS_KEY', $body['databaseHint']);
        $this->assertStringContainsString('sdb_', $body['databaseHint']);
        $this->assertStringContainsString('CONSOLE_SERVICE_KEY', $body['databaseHint'], 'says which keys are NOT this one');
        $this->assertSame([], self::$console->requests(), 'Console was never asked: there was no key to ask with');
        $this->assertStringNotContainsString('sdb_bbbb', (string) json_encode($body), 'the value of the key under the wrong name is not echoed');
    }

    public function testAKeyWithNoUrlIsNamed(): void
    {
        $body = ProbeRunner::run('health', ['CONSOLE_DB_DETAILS_KEY' => $this->key, 'database.default.port' => '1'])['body'];

        $this->assertSame('console_url_missing', $body['databaseReason']);
        $this->assertSame(ConsoleDatabaseDetails::hint('console_url_missing'), $body['databaseHint']);
    }

    public function testAWorkingClassicSetupIsNotSecondGuessed(): void
    {
        // CONSOLE_API_URL alone, a database named in .env: Console is not used and that is fine. If the connection fails
        // here it is the driver's failure, and nothing is blamed on Console.
        $env = ['CONSOLE_API_URL' => self::$console->url(), 'database.default.database' => 'legacy_db', 'database.default.username' => 'legacy_user', 'database.default.port' => '1'];

        $health = ProbeRunner::run('health', $env);
        $body   = $health['body'];

        $this->assertSame(503, $health['code']);
        $this->assertSame('unavailable', $body['database']);
        $this->assertSame('env', $body['databaseSource']);
        $this->assertArrayNotHasKey('databaseReason', $body);
        $this->assertArrayNotHasKey('databaseHint', $body);
        $this->assertSame([], self::$console->requests());
    }

    public function testAConnectionFailureAfterConsoleAnsweredIsTheDriversNotConsoles(): void
    {
        // Console named a database; the connection to it (port 1) is what fails.
        self::$console->control(['expect_key' => $this->key, 'data' => ['database_name' => 'cp_remote_db', 'database_username' => 'cp_remote_user']]);

        $body = ProbeRunner::run('health', $this->consoleEnv())['body'];

        $this->assertSame('unavailable', $body['database']);
        $this->assertSame('console', $body['databaseSource'], 'Console was asked and answered');
        $this->assertArrayNotHasKey('databaseReason', $body, 'a refused connection is not a Console problem');
        $this->assertCount(1, self::$console->requests());
    }

    // -----------------------------------------------------------------------
    // a working connection, either way (needs the integration suite's PostgreSQL)
    // -----------------------------------------------------------------------

    public function testHealthIsOkWithTheNameAndUsernameFromEnvOrFromConsole(): void
    {
        $db = $this->testDatabase();
        $local = [
            'database.default.hostname' => $db['host'],
            'database.default.port'     => $db['port'],
            'database.default.password' => $db['pass'],
            'database.default.DBDebug'  => '0',
        ];

        // Classic: name and username from .env.
        $classic = ProbeRunner::run('health', $local + ['database.default.database' => $db['name'], 'database.default.username' => $db['user']]);
        $this->assertSame(200, $classic['code']);
        $this->assertSame('ok', $classic['body']['database']);
        $this->assertSame('env', $classic['body']['databaseSource']);
        $this->assertArrayNotHasKey('databaseReason', $classic['body']);
        $this->assertSame([], self::$console->requests());

        // Console: .env names junk, and Console's name and username are the ones that connect.
        self::$console->control(['expect_key' => $this->key, 'data' => ['database_name' => $db['name'], 'database_username' => $db['user'], 'environment' => 'sandbox']]);
        $viaConsole = ProbeRunner::run('health', $local + [
            'CONSOLE_API_URL'           => self::$console->url(),
            'CONSOLE_DB_DETAILS_KEY'    => $this->key,
            'REMOTE_ENVIRONMENT'     => 'sandbox',
            'database.default.database' => 'definitely_not_the_database',
            'database.default.username' => 'definitely_not_the_user',
        ]);
        $this->assertSame(200, $viaConsole['code']);
        $this->assertSame('ok', $viaConsole['body']['database']);
        $this->assertSame('console', $viaConsole['body']['databaseSource']);
        $this->assertArrayNotHasKey('databaseReason', $viaConsole['body']);
        $this->assertCount(1, self::$console->requests());
        $this->assertStringNotContainsString($this->key, (string) json_encode($viaConsole));
        $this->assertStringNotContainsString($db['pass'], (string) json_encode($viaConsole));
    }

    /** @return array{host: string, port: string, name: string, user: string, pass: string} */
    private function testDatabase(): array
    {
        // The suite's own `tests` connection group (database.tests.* from the environment): the database every other
        // database test here already uses.
        $group = (array) (new \Config\Database())->tests;
        $db    = [
            'host' => (string) ($group['hostname'] ?? ''),
            'port' => (string) ($group['port'] ?? '5432'),
            'name' => (string) ($group['database'] ?? ''),
            'user' => (string) ($group['username'] ?? ''),
            'pass' => (string) ($group['password'] ?? ''),
        ];
        if ($db['name'] === '' || $db['user'] === '') {
            $this->markTestSkipped('the suite\'s test PostgreSQL (database.tests.*) is not configured');
        }
        if (! function_exists('pg_connect')) {
            $this->markTestSkipped('ext-pgsql is not available');
        }
        $link = @pg_connect(sprintf("host=%s port=%s dbname=%s user=%s password='%s' connect_timeout=3", $db['host'], $db['port'], $db['name'], $db['user'], addslashes($db['pass'])));
        if ($link === false) {
            $this->markTestSkipped('the suite\'s test PostgreSQL (database.tests.*) is not reachable');
        }
        pg_close($link);

        return $db;
    }
}
