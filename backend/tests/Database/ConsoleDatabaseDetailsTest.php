<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Support\ConsoleDatabaseDetails;
use App\Support\ConsoleDatabaseDetailsException;
use CodeIgniter\Database\Exceptions\DatabaseException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\ConsoleStubServer;
use Tests\Support\ProbeRunner;

/**
 * The database name / username come from Console (SaaS Database Details), not from api/.env. Console is a real HTTP
 * server here (`php -S` running tests/support/console_db_details_stub.php), so the curl path, the headers, the
 * timeouts and the redirect / size rules are exercised for real.
 */
final class ConsoleDatabaseDetailsTest extends TestCase
{
    private const KEY_VARS = ['CONSOLE_API_URL', 'CONSOLE_DB_DETAILS_KEY', 'REMOTE_ENVIRONMENT', 'app.baseURL', 'CONSOLE_SERVICE_KEY'];

    private static ConsoleStubServer $console;

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    /** @var list<string> */
    private array $logged = [];

    private string $key = '';

    public static function setUpBeforeClass(): void
    {
        self::$console = new ConsoleStubServer((int) (getenv('REMOTE_CONSOLE_STUB_PORT') ?: 8837));
        self::$console->start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$console->stop();
    }

    protected function setUp(): void
    {
        foreach (self::KEY_VARS as $name) {
            $this->savedEnv[$name] = getenv($name);
            $this->setEnv($name, null);
        }
        self::$console->forgetRequests();
        self::$console->control([]);

        $this->key    = 'key-' . bin2hex(random_bytes(8));
        $this->logged = [];
        ConsoleDatabaseDetails::useLogger(function (string $line): void {
            $this->logged[] = $line;
        });
    }

    protected function tearDown(): void
    {
        ConsoleDatabaseDetails::resetForTesting(true);
        ConsoleDatabaseDetails::useLogger(null);
        foreach ($this->savedEnv as $name => $value) {
            $this->setEnv($name, $value === false ? null : $value);
        }
    }

    // -----------------------------------------------------------------------
    // no key = legacy
    // -----------------------------------------------------------------------

    public function testWithoutTheKeyNothingChangesAndConsoleIsNeverCalled(): void
    {
        // The URL alone may be a leftover on a host that never asked Console for its database: not enough.
        $this->setEnv('CONSOLE_API_URL', $this->url());
        $this->assertFalse(ConsoleDatabaseDetails::isConfigured());
        $this->assertSame('env', ConsoleDatabaseDetails::source());

        $this->setEnv('CONSOLE_API_URL', null);
        $this->setEnv('CONSOLE_DB_DETAILS_KEY', $this->key); // key alone neither
        $this->assertFalse(ConsoleDatabaseDetails::isConfigured());
        $this->setEnv('CONSOLE_DB_DETAILS_KEY', null);

        // Config\Database, as db_connect() builds it: the classic settings win.
        $legacy = $this->boot(['database.default.database' => 'legacy_db', 'database.default.username' => 'legacy_user', 'database.default.password' => 'pw']);
        $this->assertTrue($legacy['ok']);
        $this->assertSame('legacy_db', $legacy['group']['database']);
        $this->assertSame('legacy_user', $legacy['group']['username']);

        // ...also when only the URL is set, which is what a host with a leftover CONSOLE_API_URL looks like.
        $urlOnly = $this->boot(['CONSOLE_API_URL' => $this->url(), 'database.default.database' => 'legacy_db', 'database.default.username' => 'legacy_user']);
        $this->assertSame('legacy_db', $urlOnly['group']['database']);
        $this->assertSame('legacy_user', $urlOnly['group']['username']);

        $this->assertSame([], self::$console->requests(), 'Console was not called');
    }

    // -----------------------------------------------------------------------
    // name / username from Console
    // -----------------------------------------------------------------------

    public function testNameAndUsernameComeFromConsoleWhileTheEnvOnesAreJunk(): void
    {
        $this->configure();
        self::$console->control(['expect_key' => $this->key, 'data' => ['database_name' => 'cp_remote_prod', 'database_username' => 'cp_remote_user']]);

        // 1) the resolver itself
        $this->assertTrue(ConsoleDatabaseDetails::isConfigured());
        $this->assertSame('console', ConsoleDatabaseDetails::source());
        $this->assertSame(['name' => 'cp_remote_prod', 'user' => 'cp_remote_user'], ConsoleDatabaseDetails::resolve());

        $requests = self::$console->requests();
        $this->assertCount(1, $requests);
        $this->assertSame('GET', $requests[0]['method']);
        $this->assertSame('/api/database-details/resolve', $requests[0]['path']);
        $this->assertSame('', $requests[0]['query'], 'no product / domain parameter: the key identifies the row');
        $this->assertSame('Bearer ' . $this->key, $requests[0]['headers']['authorization']);

        // 2) Config\Database in a real CodeIgniter boot, with junk name/user/DSN in the environment: Console's values
        //    win, host/port/password/schema stay local.
        ConsoleDatabaseDetails::resetForTesting(true); // the child asks Console itself
        $booted = $this->boot([
            'CONSOLE_API_URL'           => $this->url(),
            'CONSOLE_DB_DETAILS_KEY'    => $this->key,
            'database.default.database' => 'definitely_not_the_database',
            'database.default.username' => 'definitely_not_the_user',
            'database.default.DSN'      => 'pgsql:host=evil;dbname=definitely_not_the_database',
            'database.default.hostname' => '10.1.2.3',
            'database.default.port'     => '6543',
            'database.default.password' => 'local-secret',
        ]);
        $this->assertTrue($booted['ok'], (string) ($booted['message'] ?? ''));
        $group = $booted['group'];
        $this->assertSame('cp_remote_prod', $group['database']);
        $this->assertSame('cp_remote_user', $group['username']);
        $this->assertSame('', $group['DSN'], 'a DSN would carry its own database and user');
        $this->assertSame('10.1.2.3', $group['hostname']);
        $this->assertSame(6543, $group['port']);
        $this->assertSame('local-secret', $group['password']);
    }

    public function testOnlyTheNameAndUsernameAreReadFromTheAnswer(): void
    {
        $this->configure();
        self::$console->control(['expect_key' => $this->key, 'data' => [
            'database_host' => 'elsewhere.example.test', 'database_port' => 6543, 'database_sslmode' => 'require', 'database_password' => 'from-console',
            'cpanel_username' => 'cp', 'id' => 7,
        ]]);

        $group = ConsoleDatabaseDetails::applyTo(['database' => 'junk', 'username' => 'junk', 'DSN' => 'pgsql:dbname=junk', 'hostname' => 'h', 'port' => 5432, 'password' => 'p', 'schema' => 'public']);

        $this->assertSame('cp_remote_db', $group['database']);
        $this->assertSame('cp_remote_user', $group['username']);
        $this->assertSame('', $group['DSN']);
        $this->assertSame(['h', 5432, 'p', 'public'], [$group['hostname'], $group['port'], $group['password'], $group['schema']], 'host, port, password and schema are what api/.env said');
    }

    public function testAConsoleFailureMakesTheDatabaseConfigUnavailableNotBlank(): void
    {
        $this->configure();
        self::$console->control(['expect_key' => 'some-other-key']); // 401

        $booted = $this->boot([
            'CONSOLE_API_URL'           => $this->url(),
            'CONSOLE_DB_DETAILS_KEY'    => $this->key,
            'database.default.database' => 'definitely_not_the_database',
            'database.default.username' => 'definitely_not_the_user',
        ]);

        $this->assertFalse($booted['ok'], 'no group is built, so nothing can connect with a blank or stale name/user, nor with the .env ones');
        $this->assertStringContainsString('rejected CONSOLE_DB_DETAILS_KEY', $booted['message']);
        $this->assertStringNotContainsString($this->key, $booted['message']);
    }

    // -----------------------------------------------------------------------
    // what each failure is called
    // -----------------------------------------------------------------------

    #[DataProvider('categories')]
    public function testEachFailureHasItsOwnCategoryAndAFixedHint(array $control, string $category): void
    {
        $this->configure();
        // array_merge: a case that names its own expect_key (a key Console does not know) must win over ours.
        self::$console->control(array_merge(['expect_key' => $this->key], $control));

        $e = $this->resolveFailure();
        $this->assertSame($category, $e->category);
        $this->assertStringNotContainsString($this->key, $e->getMessage());

        // The hint is chosen from the category, never from the exception: nothing Console sent can be in it.
        $hint = ConsoleDatabaseDetails::hint($category);
        $this->assertNotSame('', $hint);
        $this->assertStringNotContainsString($this->key, $hint);

        // DatabaseException wraps it (as Config\Database does) and diagnose() still finds it.
        $diagnosis = ConsoleDatabaseDetails::diagnose(new DatabaseException($e->getMessage(), 0, $e));
        $this->assertSame(['reason' => $category, 'hint' => $hint], $diagnosis);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function categories(): array
    {
        return [
            'a revoked key (401)'                  => [['expect_key' => 'a-different-key'], 'console_key_rejected'],
            'an inactive row (403)'                => [['status' => 403], 'console_row_inactive'],
            'Console down (503)'                   => [['status' => 503], 'console_unreachable'],
            'Console rate limiting (429)'          => [['status' => 429], 'console_unreachable'],
            'a route Console does not have (404)'  => [['status' => 404], 'console_unexpected_answer'],
            'an answer that is not JSON'           => [['raw' => '<html>maintenance</html>'], 'console_unexpected_answer'],
            'a response over 64 KB'                => [['pad' => 70000], 'console_unexpected_answer'],
            'a name with a ";" in it'              => [['data' => ['database_name' => 'cp;host=evil']], 'console_unexpected_answer'],
            'nothing recorded for the product'     => [['data' => ['database_name' => '', 'database_username' => '']], 'console_no_database_recorded'],
            'a redirect'                           => [['redirect' => '/elsewhere'], 'console_unexpected_answer'],
        ];
    }

    public function testEveryCategoryHasAHintAndNothingElseDoes(): void
    {
        foreach (ConsoleDatabaseDetails::categories() as $category) {
            $this->assertNotSame('', ConsoleDatabaseDetails::hint($category), $category);
        }
        $this->assertSame('', ConsoleDatabaseDetails::hint('a-category-nobody-defined'));
        $this->assertNull(ConsoleDatabaseDetails::diagnose(new \RuntimeException('a driver error')), 'a failure that is not Console\'s is not named as if it were');
    }

    public function testAMalformedUrlOrKeyIsAConfigurationProblem(): void
    {
        $this->configure();
        $this->setEnv('CONSOLE_API_URL', 'ftp://127.0.0.1/api');
        $this->assertSame('console_config', $this->resolveFailure()->category);

        $this->setEnv('CONSOLE_API_URL', $this->url());
        $this->setEnv('CONSOLE_DB_DETAILS_KEY', "bad key\r\nX-Injected: 1");
        ConsoleDatabaseDetails::resetForTesting(true);
        $this->assertSame('console_config', $this->resolveFailure()->category);
        $this->assertSame([], self::$console->requests(), 'nothing was sent');
    }

    // -----------------------------------------------------------------------
    // the key is in the wrong place
    // -----------------------------------------------------------------------

    public function testAUrlWithoutTheKeyIsNamedOnlyWhenNothingElseNamesADatabase(): void
    {
        $this->setEnv('CONSOLE_API_URL', $this->url());
        $this->assertSame('console_key_missing', ConsoleDatabaseDetails::notUsedReason());

        // A database named locally: that is a working classic setup, there is nothing to report.
        $this->assertNull(ConsoleDatabaseDetails::diagnoseUnused(['database' => 'legacy_db', 'username' => 'legacy_user']));

        // Nothing named locally (the keys put under another name, then database.default.database commented out):
        $diagnosis = ConsoleDatabaseDetails::diagnoseUnused(['database' => '', 'username' => '']);
        $this->assertSame('console_key_missing', $diagnosis['reason'] ?? null);
        $this->assertStringContainsString('CONSOLE_DB_DETAILS_KEY', $diagnosis['hint'] ?? '');
        $this->assertStringContainsString('sdb_', $diagnosis['hint'] ?? '');
        $this->assertStringContainsString('CONSOLE_SERVICE_KEY', $diagnosis['hint'] ?? '', 'says which key is NOT this one');
        $this->assertNotNull(ConsoleDatabaseDetails::diagnoseUnused(['database' => 'only_a_name', 'username' => '']));

        $this->setEnv('CONSOLE_API_URL', null);
        $this->setEnv('CONSOLE_DB_DETAILS_KEY', $this->key);
        $this->assertSame('console_url_missing', ConsoleDatabaseDetails::notUsedReason());
        $this->assertSame('console_url_missing', ConsoleDatabaseDetails::diagnoseUnused([])['reason'] ?? null);

        $this->setEnv('CONSOLE_DB_DETAILS_KEY', null);
        $this->assertNull(ConsoleDatabaseDetails::notUsedReason(), 'neither is a plain classic deployment');
        $this->assertNull(ConsoleDatabaseDetails::diagnoseUnused([]));

        $this->configure();
        $this->assertNull(ConsoleDatabaseDetails::notUsedReason(), 'both set: Console is used, nothing is "not used"');
    }

    public function testTheKeyUnderAnotherNameIsNotAKey(): void
    {
        // CONSOLE_SERVICE_KEY is another credential Remote already has. It is not read here.
        $this->setEnv('CONSOLE_API_URL', $this->url());
        $this->setEnv('CONSOLE_SERVICE_KEY', 'sdb_' . str_repeat('a', 64));

        try {
            $this->assertFalse(ConsoleDatabaseDetails::isConfigured());
            $this->assertSame('console_key_missing', ConsoleDatabaseDetails::notUsedReason());
            $this->assertSame([], self::$console->requests());
        } finally {
            $this->setEnv('CONSOLE_SERVICE_KEY', null);
        }
    }

    // -----------------------------------------------------------------------
    // cache
    // -----------------------------------------------------------------------

    public function testTheAnswerIsCachedAndTheKeyNeverReachesDisk(): void
    {
        $this->configure();
        self::$console->control(['expect_key' => $this->key]);

        ConsoleDatabaseDetails::resolve();
        ConsoleDatabaseDetails::resetForTesting(false); // a new process: only the file survives
        ConsoleDatabaseDetails::resolve();
        ConsoleDatabaseDetails::resolve();

        $this->assertCount(1, self::$console->requests(), 'one Console call serves them all');

        $path = ConsoleDatabaseDetails::cacheFilePath();
        $this->assertStringStartsWith(rtrim(sys_get_temp_dir(), '/') . '/remote-console-db-', $path);
        $this->assertStringNotContainsString($this->key, $path);
        $this->assertFileExists($path);
        $contents = (string) file_get_contents($path);
        $this->assertStringNotContainsString($this->key, $contents);
        $this->assertSame(['name', 'user', 'fetched_at'], array_keys((array) json_decode($contents, true)), 'only the two identifiers and a timestamp');
        $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
        $this->assertSame([], glob($path . '.*.tmp') ?: [], 'no temp file left behind');
    }

    public function testTheCacheFileIsOwnerOnlyEvenUnderAPermissiveUmask(): void
    {
        $this->configure();
        self::$console->control(['expect_key' => $this->key]);

        $previous = umask(0000);

        try {
            ConsoleDatabaseDetails::resolve();
        } finally {
            umask($previous);
        }

        $this->assertSame('0600', substr(sprintf('%o', fileperms(ConsoleDatabaseDetails::cacheFilePath())), -4));
        $this->assertSame($previous, umask($previous), 'the process umask is put back');
    }

    public function testAnExpiredAnswerIsFetchedAgainAndTheCacheFollowsAKeyChange(): void
    {
        $this->configure();
        self::$console->control(['expect_key' => $this->key]);
        ConsoleDatabaseDetails::resolve();
        $this->ageCache(600);

        self::$console->control(['expect_key' => $this->key, 'data' => ['database_name' => 'cp_renamed']]);
        $this->assertSame('cp_renamed', ConsoleDatabaseDetails::resolve()['name'], 'older than ~5 minutes: asked again');
        $this->assertCount(2, self::$console->requests());

        // A different key is a different cache: a rotated key never reads the old answer.
        $oldFile = ConsoleDatabaseDetails::cacheFilePath();
        $this->setEnv('CONSOLE_DB_DETAILS_KEY', $this->key . '-rotated');
        $this->assertNotSame($oldFile, ConsoleDatabaseDetails::cacheFilePath());
        @unlink($oldFile);
    }

    public function testRefreshIgnoresEveryCache(): void
    {
        $this->configure();
        self::$console->control(['expect_key' => $this->key]);
        ConsoleDatabaseDetails::resolve();

        self::$console->control(['expect_key' => $this->key, 'data' => ['database_name' => 'cp_moved']]);
        $this->assertSame('cp_remote_db', ConsoleDatabaseDetails::resolve()['name'], 'resolve() serves the cached answer');
        $this->assertSame('cp_moved', ConsoleDatabaseDetails::refresh()['name'], 'refresh() asks Console again');
        ConsoleDatabaseDetails::resetForTesting(false);
        $this->assertSame('cp_moved', ConsoleDatabaseDetails::resolve()['name'], '…and what it said is what the next process gets');
        $this->assertCount(2, self::$console->requests());
    }

    public function testASymlinkOrAForeignFileInPlaceOfTheCacheIsNotBelieved(): void
    {
        $this->configure();
        self::$console->control(['expect_key' => $this->key, 'status' => 500]);

        $path = ConsoleDatabaseDetails::cacheFilePath();
        $target = tempnam(sys_get_temp_dir(), 'remote-link-target-');
        file_put_contents($target, json_encode(['name' => 'planted_db', 'user' => 'planted_user', 'fetched_at' => time()]));
        @unlink($path);

        try {
            if (@symlink($target, $path)) {
                $e = $this->resolveFailure();
                $this->assertStringContainsString('HTTP 500', $e->getMessage(), 'the link was not followed: Console was asked (and is down)');
            } else {
                $this->markTestSkipped('symlinks are not available here');
            }
        } finally {
            @unlink($path);
            @unlink($target);
        }
    }

    // -----------------------------------------------------------------------
    // refusals are never papered over
    // -----------------------------------------------------------------------

    public function testARevokedKeyIsRefusedEvenWhenAnOlderAnswerIsCached(): void
    {
        $this->configure();
        self::$console->control(['expect_key' => $this->key]);
        ConsoleDatabaseDetails::resolve();
        $this->ageCache(3600);

        self::$console->control(['expect_key' => 'a-different-key']); // Console no longer knows this key: 401

        $e = $this->resolveFailure();
        $this->assertStringContainsString('rejected CONSOLE_DB_DETAILS_KEY', $e->getMessage());
        $this->assertStringNotContainsString($this->key, $e->getMessage());
        $this->assertSame([], $this->logged, 'a refusal is not logged as "using the last details"');

        // and again, from a fresh process: the stale file still does not help
        ConsoleDatabaseDetails::resetForTesting(false);
        $this->resolveFailure();
    }

    public function testAnInactiveRowIsRefusedEvenWhenAnOlderAnswerIsCached(): void
    {
        $this->configure();
        self::$console->control(['expect_key' => $this->key]);
        ConsoleDatabaseDetails::resolve();
        $this->ageCache(3600);

        self::$console->control(['expect_key' => $this->key, 'status' => 403]);

        $e = $this->resolveFailure();
        $this->assertStringContainsString('inactive', $e->getMessage());
        $this->assertStringNotContainsString($this->key, $e->getMessage());
    }

    #[DataProvider('unusableAnswers')]
    public function testAnUnusableAnswerIsRefusedEvenWhenAnOlderAnswerIsCached(array $control, string $expectedInMessage): void
    {
        $this->configure();
        self::$console->control(['expect_key' => $this->key]);
        ConsoleDatabaseDetails::resolve();
        $this->ageCache(3600);

        self::$console->control(['expect_key' => $this->key] + $control);

        $e = $this->resolveFailure();
        $this->assertStringContainsString($expectedInMessage, $e->getMessage());
        $this->assertStringNotContainsString($this->key, $e->getMessage());
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function unusableAnswers(): array
    {
        $row = static fn (array $data): array => ['data' => $data];

        return [
            'not JSON'                 => [['raw' => '<html>maintenance</html>'], 'not the expected JSON'],
            'JSON without data'        => [['raw' => '{"success":true}'], 'not the expected JSON'],
            'success false'            => [['raw' => '{"success":false,"data":{"database_name":"x","database_username":"y"}}'], 'not the expected JSON'],
            'empty name'               => [$row(['database_name' => '']), 'no database name'],
            'empty username'           => [$row(['database_username' => '  ']), 'no database name'],
            'name is not a string'     => [$row(['database_name' => ['x']]), 'no database name'],
            'semicolon in name'        => [$row(['database_name' => 'good_db;host=evil.example']), 'characters'],
            'equals sign in name'      => [$row(['database_name' => 'a=b']), 'characters'],
            'space in name'            => [$row(['database_name' => 'db name']), 'characters'],
            'quote in username'        => [$row(['database_username' => "u'x"]), 'characters'],
            'slash in username'        => [$row(['database_username' => 'u/../x']), 'characters'],
            'newline in username'      => [$row(['database_username' => "user\nname"]), 'characters'],
            'name longer than 128'     => [$row(['database_name' => str_repeat('a', 129)]), 'characters'],
            'other 4xx'                => [['status' => 404], 'HTTP 404'],
            'response over 64 KB'      => [['pad' => 70000], 'not the expected JSON'],
        ];
    }

    public function testIdentifiersAtTheLimitsOfTheRuleAreAccepted(): void
    {
        $this->configure();
        $name = str_repeat('a', 128);
        self::$console->control(['expect_key' => $this->key, 'data' => ['database_name' => $name, 'database_username' => 'Az09_.$-']]);

        $this->assertSame(['name' => $name, 'user' => 'Az09_.$-'], ConsoleDatabaseDetails::resolve());
    }

    public function testARedirectIsNotFollowed(): void
    {
        $this->configure();
        self::$console->control(['expect_key' => $this->key, 'redirect' => '/elsewhere']);

        $e = $this->resolveFailure();
        $this->assertStringContainsString('HTTP 302', $e->getMessage());
        $this->assertCount(1, self::$console->requests(), 'the Location was not requested (the bearer key would have gone with it)');
    }

    public function testOnlyHttpAndHttpsUrlsAreUsed(): void
    {
        $this->configure();
        $this->setEnv('CONSOLE_API_URL', 'ftp://127.0.0.1:8826/api');

        $e = $this->resolveFailure();
        $this->assertStringContainsString('http(s) URL', $e->getMessage());
        $this->assertSame([], self::$console->requests());
    }

    // -----------------------------------------------------------------------
    // outage
    // -----------------------------------------------------------------------

    #[DataProvider('outages')]
    public function testAnOutageUsesTheLastGoodAnswerAndSaysSo(array $control): void
    {
        $this->configure();
        self::$console->control(['expect_key' => $this->key, 'data' => ['database_name' => 'cp_last_good', 'database_username' => 'cp_user']]);
        ConsoleDatabaseDetails::resolve();
        $this->ageCache(3600); // stale, but not ancient

        self::$console->control(['expect_key' => $this->key] + $control);

        $this->assertSame(['name' => 'cp_last_good', 'user' => 'cp_user'], ConsoleDatabaseDetails::resolve(), 'carried on through the outage');
        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('using the last details', $this->logged[0]);
        $this->assertStringStartsWith('[remote-db] ', $this->logged[0]);
        $this->assertStringNotContainsString($this->key, $this->logged[0]);

        // the outage is remembered for a few seconds: no second Console call from this process
        $callsSoFar = count(self::$console->requests());
        ConsoleDatabaseDetails::resolve();
        $this->assertCount($callsSoFar, self::$console->requests());
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function outages(): array
    {
        return [
            '500' => [['status' => 500]],
            '502' => [['status' => 502]],
            '503' => [['status' => 503]],
            '429' => [['status' => 429]],
        ];
    }

    public function testAnUnreachableConsoleUsesTheLastGoodAnswerAndWithNoneItFails(): void
    {
        $this->configure();
        self::$console->control(['expect_key' => $this->key]);
        ConsoleDatabaseDetails::resolve();
        $this->ageCache(3600);

        $this->setEnv('CONSOLE_API_URL', 'http://127.0.0.1:1/api'); // nothing listens there
        // The cache file is named by URL + key, so seed it for the dead URL too.
        $this->seedCache('cp_last_good', 'cp_user', 3600);
        ConsoleDatabaseDetails::resetForTesting(false);

        $this->assertSame(['name' => 'cp_last_good', 'user' => 'cp_user'], ConsoleDatabaseDetails::resolve());
        $this->assertStringContainsString('could not be reached', $this->logged[0]);

        ConsoleDatabaseDetails::resetForTesting(true); // nothing remembered at all
        $e = $this->resolveFailure();
        $this->assertStringContainsString('could not be reached', $e->getMessage());
        $this->assertSame('console_unreachable', $e->category);
        $this->assertStringNotContainsString($this->key, $e->getMessage());
    }

    public function testAnOutageWithNothingCachedFails(): void
    {
        $this->configure();
        self::$console->control(['expect_key' => $this->key, 'status' => 500]);

        $e = $this->resolveFailure();
        $this->assertStringContainsString('Console answered HTTP 500', $e->getMessage());

        // The failure is remembered ~15 s, so one request that connects twice waits once, and keeps its category.
        $again = $this->resolveFailure();
        $this->assertSame('console_unreachable', $again->category);
        $this->assertCount(1, self::$console->requests());
    }

    public function testAnswerOlderThanAWeekIsNotUsedInAnOutage(): void
    {
        $this->configure();
        self::$console->control(['expect_key' => $this->key]);
        ConsoleDatabaseDetails::resolve();
        $this->ageCache(8 * 86400);

        self::$console->control(['expect_key' => $this->key, 'status' => 500]);

        $this->resolveFailure();
        $this->assertSame([], $this->logged);
    }

    public function testASlowConsoleIsGivenUpOnAfterAboutFourSeconds(): void
    {
        $this->configure();
        self::$console->control(['expect_key' => $this->key, 'delay' => 7]);

        $start = microtime(true);
        $e     = $this->resolveFailure();
        $took  = microtime(true) - $start;

        $this->assertLessThan(6.5, $took, 'total timeout is 4 s');
        $this->assertGreaterThanOrEqual(3.5, $took);
        $this->assertStringContainsString('could not be reached', $e->getMessage());
    }

    public function testADoctoredCacheFileIsIgnored(): void
    {
        $this->configure();
        self::$console->control(['expect_key' => $this->key, 'status' => 500]);
        $this->seedCache('db;host=evil', 'user', 10);

        $e = $this->resolveFailure();
        $this->assertStringContainsString('HTTP 500', $e->getMessage(), 'the file was not trusted, so Console was asked (and is down)');
    }

    // -----------------------------------------------------------------------
    // environment
    // -----------------------------------------------------------------------

    public function testASandboxKeyCannotBeUsedByAProductionDeploymentAndViceVersa(): void
    {
        $this->configure();
        self::$console->control(['expect_key' => $this->key, 'data' => ['environment' => 'sandbox', 'database_name' => 'cp_sandbox_db']]);

        $this->setEnv('REMOTE_ENVIRONMENT', 'production');
        $e = $this->resolveFailure();
        $this->assertStringContainsString('sandbox database but this deployment is production', $e->getMessage());
        $this->assertStringContainsString('REMOTE_ENVIRONMENT', $e->getMessage(), 'says how to say "this is the sandbox"');
        $this->assertSame('console_environment_mismatch', $e->category);
        $this->assertStringNotContainsString($this->key, $e->getMessage());

        $this->setEnv('REMOTE_ENVIRONMENT', 'sandbox');
        ConsoleDatabaseDetails::resetForTesting(true);
        $this->assertSame('cp_sandbox_db', ConsoleDatabaseDetails::resolve()['name'], 'the matching environment is accepted');

        self::$console->control(['expect_key' => $this->key, 'data' => ['environment' => 'production']]);
        ConsoleDatabaseDetails::resetForTesting(true);
        $e = $this->resolveFailure();
        $this->assertStringContainsString('production database but this deployment is sandbox', $e->getMessage());
    }

    public function testAMismatchIsRefusedEvenWithAnOlderAnswerCached(): void
    {
        $this->configure();
        $this->setEnv('REMOTE_ENVIRONMENT', 'sandbox');
        self::$console->control(['expect_key' => $this->key, 'data' => ['environment' => 'sandbox']]);
        ConsoleDatabaseDetails::resolve();
        $this->ageCache(3600);

        self::$console->control(['expect_key' => $this->key, 'data' => ['environment' => 'production']]);
        $e = $this->resolveFailure();
        $this->assertStringContainsString('production database but this deployment is sandbox', $e->getMessage());
    }

    #[DataProvider('aliases')]
    public function testTheEnvironmentNamesAreTheOnesTheConsoleRowsUse(string $deployment, string $row, bool $accepted): void
    {
        $this->configure();
        $this->setEnv('REMOTE_ENVIRONMENT', $deployment);
        self::$console->control(['expect_key' => $this->key, 'data' => ['environment' => $row]]);

        $this->assertSame($accepted, $this->tryResolve(), "a $deployment deployment with a row saying '$row'");
    }

    /** @return array<string, array{0: string, 1: string, 2: bool}> */
    public static function aliases(): array
    {
        return [
            'prod / production'        => ['prod', 'production', true],
            'production / prod'        => ['production', 'prod', true],
            'staging / sandbox'        => ['staging', 'sandbox', true],
            'sandbox / staging'        => ['sandbox', 'staging', true],
            'production / staging'     => ['production', 'staging', false],
            'staging / prod'           => ['staging', 'prod', false],
            'upper case and spaces'    => [' PRODUCTION ', 'Production', true],
        ];
    }

    public function testARowThatNamesNoEnvironmentIsNeverAMismatch(): void
    {
        $this->configure();
        foreach (['production', 'sandbox'] as $deployment) {
            $this->setEnv('REMOTE_ENVIRONMENT', $deployment);
            ConsoleDatabaseDetails::resetForTesting(true);
            self::$console->control(['expect_key' => $this->key, 'data' => ['environment' => '']]);
            $this->assertTrue($this->tryResolve(), "a $deployment deployment, row with an empty environment");

            ConsoleDatabaseDetails::resetForTesting(true);
            self::$console->control(['expect_key' => $this->key, 'raw' => json_encode(['success' => true, 'data' => ['database_name' => 'cp_x', 'database_username' => 'cp_y']])]);
            $this->assertTrue($this->tryResolve(), "a $deployment deployment, row with no environment field at all");
        }
    }

    #[DataProvider('uncheckedEnvironments')]
    public function testADeploymentThatNamesNoKnownEnvironmentIsNeverChecked(?string $remote): void
    {
        $this->configure();
        $this->setEnv('REMOTE_ENVIRONMENT', $remote);
        foreach (['production', 'sandbox'] as $row) {
            ConsoleDatabaseDetails::resetForTesting(true);
            self::$console->control(['expect_key' => $this->key, 'data' => ['environment' => $row]]);
            $this->assertSame('cp_remote_db', ConsoleDatabaseDetails::resolve()['name'], "a $row row is fine for this deployment");
        }
    }

    /** @return array<string, array{0: ?string}> */
    public static function uncheckedEnvironments(): array
    {
        return [
            'not set'         => [null],
            'local'           => ['local'],
            'development'     => ['development'],
            'testing'         => ['testing'],
            'something else'  => ['qa'],
        ];
    }

    /**
     * Remote has no environment setting of its own beyond the optional REMOTE_ENVIRONMENT: a deploy pins app.baseURL
     * from the site's own host (cpanel-post-deploy-api.sh), so that host names the deployment when nothing says otherwise.
     */
    #[DataProvider('baseUrlEnvironments')]
    public function testWithoutAnExplicitEnvironmentTheHostOfAppBaseUrlDecides(string $baseUrl, string $row, bool $accepted): void
    {
        $this->configure();
        $this->setEnv('app.baseURL', $baseUrl);
        self::$console->control(['expect_key' => $this->key, 'data' => ['environment' => $row]]);

        $this->assertSame($accepted, $this->tryResolve(), "app.baseURL $baseUrl with a row saying '$row'");
    }

    /** @return array<string, array{0: string, 1: string, 2: bool}> */
    public static function baseUrlEnvironments(): array
    {
        return [
            'production host, production row'     => ['https://remote.aicountly.com/api/', 'production', true],
            'production host, sandbox row'        => ['https://remote.aicountly.com/api/', 'sandbox', false],
            'sandbox host (.gh.), sandbox row'    => ['https://remote.gh.aicountly.com/api/', 'sandbox', true],
            'sandbox host (.gh.), production row' => ['https://remote.gh.aicountly.com/api/', 'production', false],
            'sandbox host (gh-), sandbox row'     => ['https://gh-remote.aicountly.com/api/', 'sandbox', true],
            'sandbox host (gh-), production row'  => ['https://gh-remote.aicountly.com/api/', 'production', false],
            'localhost is never checked'          => ['http://localhost:8080/api/', 'production', true],
            'a loopback address is never checked' => ['http://127.0.0.1:8080/api/', 'sandbox', true],
            'a host this code cannot place'       => ['https://remote.example.test/api/', 'production', true],
            'no app.baseURL at all'               => ['', 'production', true],
        ];
    }

    public function testAnExplicitEnvironmentBeatsTheHostOfAppBaseUrl(): void
    {
        $this->configure();
        // A production-looking base URL on a server the operator says is the sandbox: the operator wins.
        $this->setEnv('app.baseURL', 'https://remote.aicountly.com/api/');
        $this->setEnv('REMOTE_ENVIRONMENT', 'sandbox');
        self::$console->control(['expect_key' => $this->key, 'data' => ['environment' => 'sandbox']]);
        $this->assertTrue($this->tryResolve());

        ConsoleDatabaseDetails::resetForTesting(true);
        self::$console->control(['expect_key' => $this->key, 'data' => ['environment' => 'production']]);
        $e = $this->resolveFailure();
        $this->assertSame('console_environment_mismatch', $e->category);
        $this->assertStringContainsString('REMOTE_ENVIRONMENT', $e->getMessage(), 'says how to say "this is the sandbox"');
    }

    public function testCodeIgniterEnvironmentIsNeverConsulted(): void
    {
        // A sandbox server runs CI_ENVIRONMENT as "production" too, so it says nothing about which deployment this is.
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Support/ConsoleDatabaseDetails.php');

        $this->assertSame(0, preg_match('/[\'"]CI_ENVIRONMENT[\'"]|\bENVIRONMENT\b\s*(===?|!==?|\))/', $source));
    }

    // -----------------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------------

    private function url(): string
    {
        return self::$console->url();
    }

    private function configure(): void
    {
        $this->setEnv('CONSOLE_API_URL', $this->url() . '/'); // a trailing slash is tolerated
        $this->setEnv('CONSOLE_DB_DETAILS_KEY', $this->key);
        ConsoleDatabaseDetails::resetForTesting(true);
    }

    private function resolveFailure(): ConsoleDatabaseDetailsException
    {
        try {
            ConsoleDatabaseDetails::resolve();
        } catch (ConsoleDatabaseDetailsException $e) {
            return $e;
        }
        $this->fail('Console details were accepted');
    }

    private function tryResolve(): bool
    {
        try {
            ConsoleDatabaseDetails::resolve();

            return true;
        } catch (ConsoleDatabaseDetailsException) {
            return false;
        }
    }

    private function setEnv(string $name, ?string $value): void
    {
        if ($value === null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);

            return;
        }
        putenv($name . '=' . $value);
        $_ENV[$name]    = $value;
        $_SERVER[$name] = $value;
    }

    /** Make the cached Console answer look $seconds old, and forget what this process holds. */
    private function ageCache(int $seconds): void
    {
        $path = ConsoleDatabaseDetails::cacheFilePath();
        $data = json_decode((string) file_get_contents($path), true);
        $data['fetched_at'] = time() - $seconds;
        file_put_contents($path, json_encode($data));
        ConsoleDatabaseDetails::resetForTesting(false);
    }

    private function seedCache(string $name, string $user, int $ageSeconds): void
    {
        file_put_contents(ConsoleDatabaseDetails::cacheFilePath(), json_encode(['name' => $name, 'user' => $user, 'fetched_at' => time() - $ageSeconds]));
    }

    /**
     * Config\Database as db_connect() builds it, in a child process with a real CodeIgniter boot (so BaseConfig's own env
     * loading is in play too).
     *
     * @param array<string, string> $env
     *
     * @return array{ok: bool, message?: string, group?: array<string, mixed>}
     */
    private function boot(array $env): array
    {
        return ProbeRunner::run('config', $env);
    }
}
