<?php

declare(strict_types=1);

namespace Tests\Database;

use Tests\Support\ConsoleStubServer;
use Tests\Support\RemoteTestCase;

/**
 * The commands an operator (and the deploy) runs: `php spark remote:db-check` and `php spark migrate`, as the server runs
 * them: a child process with a real CodeIgniter boot, a clean environment, and a real HTTP stand-in for Console. The
 * database is the suite's own `tests` group, migrated by the framework's test case exactly as every other database test
 * here is (so this class never changes what is recorded as migrated), and used read-only except for the records a case
 * takes away and puts back (and the tracking table renamed away and back); the tests skip when it is not reachable.
 *
 * A deploy runs `php spark migrate`, so a command that cannot reach the database has to exit non-zero and say why: with
 * Console naming the database, a rotated key is exactly that case.
 */
final class ConsoleDatabaseScriptsTest extends RemoteTestCase
{
    private static ConsoleStubServer $console;

    /** @var array{host: string, port: string, name: string, user: string, pass: string}|null */
    private static ?array $suiteDb = null;

    private static bool $suiteDbChecked = false;

    private string $key = '';

    public static function setUpBeforeClass(): void
    {
        self::$console = new ConsoleStubServer((int) (getenv('REMOTE_CONSOLE_STUB_PORT_SCRIPTS') ?: 8839));
        self::$console->start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$console->stop();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->key = 'key-' . bin2hex(random_bytes(8));
        self::$console->forgetRequests();
        self::$console->control([]);
    }

    // -----------------------------------------------------------------------
    // no database needed: the failures that happen before a connection
    // -----------------------------------------------------------------------

    public function testDbCheckNamesARefusedKeyAndNeverPrintsIt(): void
    {
        self::$console->control(['expect_key' => 'a-different-key']); // 401

        [$code, $out] = $this->spark('remote:db-check', $this->consoleEnv());

        $this->assertSame(1, $code);
        $this->assertStringContainsString('FAILED at Console', $out);
        $this->assertStringContainsString('Reason: console_key_rejected', $out);
        $this->assertStringContainsString('Generate a key for this deployment in Console', $out, 'and what to do about it');
        $this->assertStringNotContainsString($this->key, $out);
        $this->assertStringNotContainsString('a-different-key', $out);
    }

    public function testDbCheckSaysConsoleIsNotUsedWhenOnlyTheUrlAndAnotherKeyAreSet(): void
    {
        $env = [
            'CONSOLE_API_URL'       => self::$console->url(),
            'CONSOLE_SERVICE_KEY'   => 'estate-service-key-must-never-be-sent',
            'database.default.port' => '1',
        ];

        [$code, $out] = $this->spark('remote:db-check', $env);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('NOT used: CONSOLE_DB_DETAILS_KEY is not set', $out);
        $this->assertStringContainsString('Reason: console_key_missing', $out);
        $this->assertStringContainsString('sdb_', $out, 'what the right key looks like');
        $this->assertStringNotContainsString('estate-service-key-must-never-be-sent', $out);
        $this->assertSame([], self::$console->requests(), 'Console was never asked: there was no key to ask with');
    }

    public function testMigrateFailsWithTheReasonWhenConsoleRefusesTheKey(): void
    {
        // A deploy stops on a non-zero exit from this command: it must not carry on with the schema untouched.
        self::$console->control(['expect_key' => 'a-different-key']);

        [$code, $out] = $this->spark('migrate', $this->consoleEnv());

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('rejected CONSOLE_DB_DETAILS_KEY', $out, 'says why, in words that tell what to do');
        $this->assertStringNotContainsString($this->key, $out);
        $this->assertStringNotContainsString('a-different-key', $out);
    }

    public function testMigrateFailsWhenConsoleCannotBeReached(): void
    {
        [$code, $out] = $this->spark('migrate', ['CONSOLE_API_URL' => 'http://127.0.0.1:1/api', 'CONSOLE_DB_DETAILS_KEY' => $this->key]);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('Could not fetch database details from Console', $out);
        $this->assertStringNotContainsString($this->key, $out);
    }

    public function testMigrateNamesAKeyUnderTheWrongNameWhenNothingElseNamesADatabase(): void
    {
        [$code, $out] = $this->spark('migrate', ['CONSOLE_API_URL' => self::$console->url(), 'CONSOLE_SERVICE_KEY' => 'sdb_' . str_repeat('c', 64)]);

        $this->assertNotSame(0, $code, 'with no database named anywhere the command does not "succeed"');
        $this->assertStringContainsString('CONSOLE_API_URL is set but CONSOLE_DB_DETAILS_KEY is not', $out);
        $this->assertStringContainsString('sdb_', $out, 'what the right key looks like');
        $this->assertStringNotContainsString('sdb_cccc', $out, 'the value of the key under the wrong name is not echoed');
        $this->assertSame([], self::$console->requests());
    }

    // -----------------------------------------------------------------------
    // against the test PostgreSQL
    // -----------------------------------------------------------------------

    public function testDbCheckAndMigrateUseConsolesNameAndUsernameWhileEnvIsJunk(): void
    {
        $db = $this->testDatabase();
        self::$console->control(['expect_key' => $this->key, 'data' => ['database_name' => $db['name'], 'database_username' => $db['user'], 'environment' => 'sandbox']]);
        $env = $this->consoleEnv($db) + ['REMOTE_ENVIRONMENT' => 'sandbox'];

        [$code, $out] = $this->spark('remote:db-check', $env);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('yes (cache bypassed)', $out, 'asked Console right now');
        $this->assertStringContainsString($db['name'] . ' / ' . $db['user'], $out);
        $this->assertStringContainsString('OK: Remote can reach its database, and it is fully migrated.', $out);
        $this->assertStringNotContainsString($this->key, $out);
        $this->assertStringNotContainsString('definitely_not_the_', $out, 'the .env name and user are not what was used');
        $this->assertStringContainsString('password                 set in api/.env', $out, 'only whether the password is set is said, never what it is');

        [$code, $out] = $this->spark('migrate', $env);
        $this->assertSame(0, $code, $out);

        // db-check bypasses the cache and asks; migrate, a separate process, is served by the file db-check just wrote.
        $this->assertCount(1, self::$console->requests(), 'one Console call served both commands');
    }

    public function testDbCheckWithAWrongPasswordFailsAndNeverPrintsIt(): void
    {
        $db = $this->testDatabase();
        self::$console->control(['expect_key' => $this->key, 'data' => ['database_name' => $db['name'], 'database_username' => $db['user'], 'environment' => 'sandbox']]);
        $env = array_merge($this->consoleEnv($db), ['REMOTE_ENVIRONMENT' => 'sandbox', 'database.default.password' => 'not-the-password-xyz']);

        [$code, $out] = $this->spark('remote:db-check', $env);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('FAILED connecting', $out);
        $this->assertStringContainsString('password authentication failed', $out, 'the driver\'s own reason, for the person at the console');
        $this->assertStringNotContainsString('not-the-password-xyz', $out);
        $this->assertStringNotContainsString($this->key, $out);
    }

    public function testDbCheckIsReadOnlyAndSaysWhatIsMissing(): void
    {
        $db = $this->testDatabase();
        $env = $this->classicEnv($db);

        $link = $this->pg($db);
        // Whatever happens below, the suite's database is put back as it was (from a connection of its own: this test closes its
        // own). It also heals what an aborted earlier run left: the parked table put back if the real one is gone, dropped if both exist.
        $heal = function () use ($db): void {
            $own = @pg_connect(sprintf("host=%s port=%s dbname=%s user=%s password='%s' connect_timeout=3", $db['host'], $db['port'], $db['name'], $db['user'], addslashes($db['pass'])), PGSQL_CONNECT_FORCE_NEW);
            if ($own === false) {
                return;
            }
            $held = pg_fetch_result(pg_query($own, "SELECT to_regclass('migrations_diag_hold')"), 0, 0);
            $real = pg_fetch_result(pg_query($own, "SELECT to_regclass('migrations')"), 0, 0);
            if ($held && ! $real) {
                pg_query($own, 'ALTER TABLE migrations_diag_hold RENAME TO migrations');
            } elseif ($held) {
                pg_query($own, 'DROP TABLE migrations_diag_hold');
            }
            pg_close($own);
        };
        $heal();
        register_shutdown_function($heal);

        // Not migrated: the tracking table is not there. Had db-check created it (as `spark migrate:status` does), the
        // rename back would fail with "relation already exists" and this test would stop there: it is read-only.
        pg_query($link, 'ALTER TABLE migrations RENAME TO migrations_diag_hold');

        try {
            [$code, $out] = $this->spark('remote:db-check', $env);
        } finally {
            pg_query($link, 'ALTER TABLE migrations_diag_hold RENAME TO migrations');
        }
        $this->assertSame(1, $code);
        $this->assertStringContainsString('NOT READY', $out);
        $this->assertStringContainsString('Reason: not_migrated', $out);
        $this->assertStringContainsString('php spark migrate', $out, 'and what to run');

        // Pending: the newest migration's record is taken away, as if this code had shipped one nobody ran.
        // (Every record of that version: the tests' group and the default group may both have recorded it.)
        $newest = (string) pg_fetch_result(pg_query($link, 'SELECT max(version) FROM migrations'), 0, 0);
        $this->assertNotSame('', $newest);
        $rows = pg_fetch_all(pg_query_params($link, 'SELECT id, version, class, "group", namespace, time, batch FROM migrations WHERE version = $1', [$newest])) ?: [];
        $this->assertNotSame([], $rows);
        pg_query_params($link, 'DELETE FROM migrations WHERE version = $1', [$newest]);

        try {
            [$code, $out] = $this->spark('remote:db-check', $env);
        } finally {
            foreach ($rows as $row) {
                pg_query_params($link, 'INSERT INTO migrations (id, version, class, "group", namespace, time, batch) VALUES ($1, $2, $3, $4, $5, $6, $7)', [$row['id'], $row['version'], $row['class'], $row['group'], $row['namespace'], $row['time'], $row['batch']]);
            }
        }
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Reason: migrations_pending', $out);
        $this->assertStringContainsString($newest, $out, 'names the migration that is pending');

        // …and back to normal.
        [$code, $out] = $this->spark('remote:db-check', $env);
        $this->assertSame(0, $code, $out);
        pg_close($link);
    }

    // -----------------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------------

    /**
     * The environment of a deployment that has Console configured. With $db, host/port/password are the test database's;
     * without, nothing may be listening where the connection would go.
     *
     * @param array{host: string, port: string, name: string, user: string, pass: string}|null $db
     *
     * @return array<string, string>
     */
    private function consoleEnv(?array $db = null): array
    {
        return [
            'CONSOLE_API_URL'            => self::$console->url(),
            'CONSOLE_DB_DETAILS_KEY'     => $this->key,
            'database.default.database'  => 'definitely_not_the_database',
            'database.default.username'  => 'definitely_not_the_user',
            'database.default.hostname'  => $db['host'] ?? '127.0.0.1',
            'database.default.port'      => $db['port'] ?? '1',
            'database.default.password'  => $db['pass'] ?? '',
            'database.default.DBDriver'  => 'Postgre',
        ];
    }

    /**
     * @param array{host: string, port: string, name: string, user: string, pass: string} $db
     *
     * @return array<string, string>
     */
    private function classicEnv(array $db): array
    {
        return [
            'database.default.database' => $db['name'],
            'database.default.username' => $db['user'],
            'database.default.hostname' => $db['host'],
            'database.default.port'     => $db['port'],
            'database.default.password' => $db['pass'],
            'database.default.DBDriver' => 'Postgre',
        ];
    }

    /**
     * `php spark <command>` as the server runs it: a clean environment, so only what the test passes counts.
     *
     * @param array<string, string> $env
     * @param list<string>          $args
     *
     * @return array{0: int, 1: string} exit code, stdout and stderr together
     */
    private function spark(string $command, array $env, array $args = []): array
    {
        $env  = $env + ['PATH' => (string) getenv('PATH'), 'HOME' => sys_get_temp_dir()];
        $proc = proc_open(array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/spark', $command], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), $env);
        $this->assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        $code = proc_close($proc);

        // CodeIgniter colours its output; the assertions read the words.
        return [$code, (string) preg_replace('/\e\[[0-9;]*m/', '', $out)];
    }

    /** @return array{host: string, port: string, name: string, user: string, pass: string} */
    private function testDatabase(): array
    {
        if (! self::$suiteDbChecked) {
            self::$suiteDbChecked = true;
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
            if ($db['name'] !== '' && $db['user'] !== '' && function_exists('pg_connect')) {
                $link = @pg_connect(sprintf("host=%s port=%s dbname=%s user=%s password='%s' connect_timeout=3", $db['host'], $db['port'], $db['name'], $db['user'], addslashes($db['pass'])));
                if ($link !== false) {
                    pg_close($link);
                    self::$suiteDb = $db;
                }
            }
        }
        if (self::$suiteDb === null) {
            $this->markTestSkipped('the suite\'s test PostgreSQL (database.tests.*) is not reachable');
        }

        return self::$suiteDb;
    }

    /**
     * @param array{host: string, port: string, name: string, user: string, pass: string} $db
     *
     * @return \PgSql\Connection
     */
    private function pg(array $db)
    {
        // A connection of its own: pg_connect() hands back the same one for the same settings, and closing it would close both.
        $link = pg_connect(sprintf("host=%s port=%s dbname=%s user=%s password='%s' connect_timeout=3", $db['host'], $db['port'], $db['name'], $db['user'], addslashes($db['pass'])), PGSQL_CONNECT_FORCE_NEW);
        $this->assertNotFalse($link);

        return $link;
    }
}
