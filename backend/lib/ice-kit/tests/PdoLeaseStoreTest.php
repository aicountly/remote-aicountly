<?php

declare(strict_types=1);

namespace Aicountly\Ice\Tests;

use Aicountly\Ice\IceIssuer;
use Aicountly\Ice\LedgerConnection;
use Aicountly\Ice\Lease;
use Aicountly\Ice\LeaseBook;
use Aicountly\Ice\PdoLeaseStore;
use Aicountly\Ice\PdoLoadStore;
use Aicountly\Ice\Tier;

require_once __DIR__ . '/IceKitTestCase.php';

/**
 * The shared ledger. Remote and Connect are different applications on the same relays; these tests stand a
 * "remote" issuer and a "connect" issuer on ONE PostgreSQL database and check that the reserve is a property
 * of the node, not of whichever product asked.
 *
 * Needs a database the test may wipe:
 *   ICE_KIT_TEST_PG_DSN="pgsql:host=127.0.0.1;dbname=aicountly_turn_test" ICE_KIT_TEST_PG_USER=… ICE_KIT_TEST_PG_PASSWORD=…
 * Skipped without it.
 */
final class PdoLeaseStoreTest extends IceKitTestCase
{
    private LedgerConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $dsn = getenv('ICE_KIT_TEST_PG_DSN');
        if (! is_string($dsn) || $dsn === '') {
            $this->markTestSkipped('ICE_KIT_TEST_PG_DSN is not set.');
        }

        $this->connection = new LedgerConnection($dsn, (string) getenv('ICE_KIT_TEST_PG_USER'), (string) getenv('ICE_KIT_TEST_PG_PASSWORD'));
        $pdo = $this->connection->pdo();
        $pdo->exec((string) file_get_contents(dirname(__DIR__) . '/sql/turn_capacity.sql'));
        $pdo->exec('TRUNCATE turn_leases, turn_node_load, turn_capacity_config');
    }

    private function ledgerIssuer(string $product, ?PdoLeaseStore $store = null, array $envExtra = []): IceIssuer
    {
        $env = $this->activeActiveEnv(['WEBRTC_TURN_NODE_NCR_STATUS' => 'disabled', 'WEBRTC_TURN_MAX_NODES' => '1'] + $envExtra);

        return new IceIssuer($this->topology($env), null, static fn (): int => self::NOW, $product, $store ?? new PdoLeaseStore($this->connection), new PdoLoadStore($this->connection));
    }

    private function admit(IceIssuer $issuer, Tier $tier, int $n, string $prefix): int
    {
        $ok = 0;
        for ($i = 0; $i < $n; $i++) {
            $ok += $issuer->issue("{$prefix}{$i}", ['tier' => $tier, 'sessionRef' => "s-{$prefix}{$i}"])->relayAvailable() ? 1 : 0;
        }

        return $ok;
    }

    public function testLeasesRoundTripAndAnotherConnectionSeesThem(): void
    {
        $store = new PdoLeaseStore($this->connection);
        $store->transact(fn (LeaseBook $b) => $b->put(new Lease('k1', 'mum', Tier::Free, 'remote', 'default', 's', 'r', 3, 2_000_000, self::NOW, self::NOW + 100, true, true)), self::NOW);

        $other = new PdoLeaseStore(new LedgerConnection((string) getenv('ICE_KIT_TEST_PG_DSN'), (string) getenv('ICE_KIT_TEST_PG_USER'), (string) getenv('ICE_KIT_TEST_PG_PASSWORD')));
        $usage = $other->snapshot(self::NOW)->usage('mum');

        $this->assertSame(['allocations' => 3, 'bps' => 2_000_000, 'leases' => 1, 'sessions' => 1], $usage['free']);
        $lease = array_values($other->snapshot(self::NOW)->all())[0];
        $this->assertTrue($lease->reported);
        $this->assertTrue($lease->primary);
    }

    public function testAFailedTransactionLeavesNothingBehind(): void
    {
        $store = new PdoLeaseStore($this->connection);
        try {
            $store->transact(function (LeaseBook $b): void {
                $b->put(new Lease('k1', 'mum', Tier::Free, 'remote', 'default', 's', 'r', 3, 1, self::NOW, self::NOW + 100));
                throw new \RuntimeException('boom');
            }, self::NOW);
        } catch (\RuntimeException) {
        }

        $this->assertSame([], $store->snapshot(self::NOW)->all());
    }

    public function testExpiredLeasesAreDroppedAfterTheGrace(): void
    {
        $store = new PdoLeaseStore($this->connection);
        $store->transact(fn (LeaseBook $b) => $b->put(new Lease('k1', 'mum', Tier::Free, 'remote', 'default', 's', 'r', 3, 1, self::NOW, self::NOW + 10)), self::NOW);

        $this->assertCount(1, $store->snapshot(self::NOW + 10 + LeaseBook::GRACE_SECONDS)->all());
        $store->transact(static fn (LeaseBook $b) => null, self::NOW + 11 + LeaseBook::GRACE_SECONDS);
        $this->assertSame(0, (int) $this->connection->pdo()->query('SELECT count(*) FROM turn_leases')->fetchColumn());
    }

    public function testTheTwoProductsShareOneFreeShareAndOneReserve(): void
    {
        $remote  = $this->ledgerIssuer('remote');
        $connect = $this->ledgerIssuer('connect');

        // Each product alone could admit 50 free clients. Together they may admit 50.
        $free = $this->admit($remote, Tier::Free, 40, 'r') + $this->admit($connect, Tier::Free, 40, 'c');
        $this->assertSame(50, $free, 'A node has one free share, whoever asks.');

        // And the paid reserve is whole for whichever product's paid clients arrive.
        $paid = $this->admit($connect, Tier::Paid, 30, 'cp') + $this->admit($remote, Tier::Paid, 30, 'rp');
        $this->assertSame(50, $paid);

        $usage = $remote->status()['nodes'][0];
        $this->assertSame(['paid' => 50, 'free' => 50], $usage['sessions']);
        $this->assertSame($usage, $connect->status()['nodes'][0], 'Both products see the same books.');
    }

    public function testASessionEndedInOneProductDoesNotReleaseTheOthersLease(): void
    {
        $remote  = $this->ledgerIssuer('remote');
        $connect = $this->ledgerIssuer('connect');
        $remote->issue('u', ['tier' => Tier::Free, 'sessionRef' => 'same-ref']);
        $connect->issue('u', ['tier' => Tier::Free, 'sessionRef' => 'same-ref']);

        $this->assertSame(1, $remote->release('same-ref'));
        $this->assertSame(1, $remote->status()['nodes'][0]['sessions']['free'], 'The Connect lease with the same reference survives.');
    }

    public function testWhenTheLedgerIsDownPaidIsServedAndFreeIsRefusedWhileEnforcing(): void
    {
        $down = new PdoLeaseStore(new LedgerConnection('pgsql:host=127.0.0.1;port=1;dbname=nope;connect_timeout=1', 'x', 'y', 500));

        $enforcing = $this->ledgerIssuer('remote', $down);
        $paid = $enforcing->issue('p', ['tier' => Tier::Paid, 'sessionRef' => 'a']);
        $free = $enforcing->issue('f', ['tier' => Tier::Free, 'sessionRef' => 'b']);
        $this->assertTrue($paid->relayAvailable(), 'The reserve exists for paid clients: an outage of the books does not take it from them.');
        $this->assertFalse($free->relayAvailable());
        $this->assertSame('ledger_unavailable', $free->admission['reason']);

        $observing = $this->ledgerIssuer('remote', $down, ['WEBRTC_TURN_ADMISSION' => 'observe']);
        $this->assertTrue($observing->issue('f', ['tier' => Tier::Free, 'sessionRef' => 'b'])->relayAvailable(), 'Observing never denies.');
    }

    public function testMeasuredLoadIsOneReadingPerNodeSharedByEveryProduct(): void
    {
        $load = new PdoLoadStore($this->connection, 180);
        $this->assertTrue($load->ingest(['node' => 'mum', 'at' => self::NOW, 'allocations' => 7, 'rxBps' => 1000, 'txBps' => 2000], self::NOW, ['mum', 'ncr'])['ok']);
        $this->assertFalse($load->ingest(['node' => 'zzz', 'at' => self::NOW, 'allocations' => 7, 'rxBps' => 1, 'txBps' => 1], self::NOW, ['mum', 'ncr'])['ok']);

        $this->assertSame(['mum' => ['allocations' => 7, 'bps' => 3000, 'ageSeconds' => 0]], (new PdoLoadStore($this->connection, 180))->fresh(self::NOW));
        $this->assertSame([], $load->fresh(self::NOW + 181));

        $load->ingest(['node' => 'mum', 'at' => self::NOW + 5, 'allocations' => 9, 'rxBps' => 1, 'txBps' => 1], self::NOW + 5);
        $this->assertSame(9, $load->fresh(self::NOW + 5)['mum']['allocations'], 'A newer reading replaces the old one.');
    }

    public function testProductsThatDisagreeAboutACapacityAreFoundByTheReconciler(): void
    {
        $store = new PdoLeaseStore($this->connection);
        $remote  = $this->topology($this->activeActiveEnv(['WEBRTC_TURN_NODE_NCR_STATUS' => 'disabled']));
        $connect = $this->topology($this->activeActiveEnv(['WEBRTC_TURN_NODE_NCR_STATUS' => 'disabled', 'WEBRTC_TURN_NODE_MUM_MAX_ALLOCATIONS' => '600']));
        $store->register('remote', $remote->nodes(), self::NOW);
        $store->register('connect', $connect->nodes(), self::NOW);

        $boot = $this->tempPath() . '.php';
        file_put_contents($boot, "<?php\nrequire_once " . var_export(dirname(__DIR__) . '/autoload.php', true) . ";\n"
            . '$env = ' . var_export($this->activeActiveEnv(['WEBRTC_TURN_NODE_NCR_STATUS' => 'disabled']), true) . ";\n"
            . '$c = new Aicountly\Ice\LedgerConnection(' . var_export((string) getenv('ICE_KIT_TEST_PG_DSN'), true) . ', ' . var_export((string) getenv('ICE_KIT_TEST_PG_USER'), true) . ', ' . var_export((string) getenv('ICE_KIT_TEST_PG_PASSWORD'), true) . ");\n"
            . "return ['topology' => Aicountly\\Ice\\Topology::fromEnvironment(fn (\$k) => \$env[\$k] ?? null), 'leases' => new Aicountly\\Ice\\PdoLeaseStore(\$c), 'load' => new Aicountly\\Ice\\PdoLoadStore(\$c)];\n");

        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/reconcile.php') . ' --bootstrap=' . escapeshellarg($boot) . ' --product=remote --json 2>&1', $out, $code);
        $report = json_decode(implode("\n", $out), true);

        $this->assertSame(1, $code);
        $this->assertContains('config_mismatch', array_column($report['findings'], 'code'));
        @unlink($boot);
    }

    public function testTheReconcilerSeesLeakedLeasesAndUnaccountedTraffic(): void
    {
        $remote = $this->ledgerIssuer('remote');
        $this->admit($remote, Tier::Free, 10, 'leak');                          // 30 allocations booked
        (new PdoLoadStore($this->connection))->ingest(['node' => 'mum', 'at' => time(), 'allocations' => 4, 'rxBps' => 1, 'txBps' => 1], time());

        $boot = $this->tempPath() . '.php';
        file_put_contents($boot, "<?php\nrequire_once " . var_export(dirname(__DIR__) . '/autoload.php', true) . ";\n"
            . '$env = ' . var_export($this->activeActiveEnv(['WEBRTC_TURN_NODE_NCR_STATUS' => 'disabled']), true) . ";\n"
            . '$c = new Aicountly\Ice\LedgerConnection(' . var_export((string) getenv('ICE_KIT_TEST_PG_DSN'), true) . ', ' . var_export((string) getenv('ICE_KIT_TEST_PG_USER'), true) . ', ' . var_export((string) getenv('ICE_KIT_TEST_PG_PASSWORD'), true) . ");\n"
            . "return ['topology' => Aicountly\\Ice\\Topology::fromEnvironment(fn (\$k) => \$env[\$k] ?? null), 'leases' => new Aicountly\\Ice\\PdoLeaseStore(\$c), 'load' => new Aicountly\\Ice\\PdoLoadStore(\$c)];\n");
        $out = [];
        // The books use the fixed test clock (2027); the tool uses the real one, so leases are long expired — book them 'now'.
        $this->connection->pdo()->exec('UPDATE turn_leases SET expires_at = ' . (time() + 600) . ', issued_at = ' . time());
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/reconcile.php') . ' --bootstrap=' . escapeshellarg($boot) . ' --product=remote --json 2>&1', $out, $code);
        $report = json_decode(implode("\n", $out), true);

        $this->assertContains('leaked_leases', array_column($report['findings'], 'code'));
        $this->assertStringNotContainsString('mumbai-secret', implode("\n", $out));
        @unlink($boot);
    }

    /** Nine processes, three of them "Remote", three "Connect" and three paid, racing on ONE database. */
    public function testProductsRacingOnTheSharedLedgerCannotOverAdmit(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is unavailable.');
        }

        $env = $this->activeActiveEnv(['WEBRTC_TURN_NODE_NCR_STATUS' => 'disabled', 'WEBRTC_TURN_MAX_NODES' => '1']);
        $envFile = $this->tempPath();
        file_put_contents($envFile, json_encode($env));

        $jobs = [['free', 'r1', 'remote'], ['free', 'r2', 'remote'], ['free', 'r3', 'remote'], ['free', 'c1', 'connect'], ['free', 'c2', 'connect'], ['free', 'c3', 'connect']];
        $procs = [];
        foreach ($jobs as [$tier, $prefix, $product]) {
            $p = proc_open(
                [PHP_BINARY, __DIR__ . '/support/issue-batch.php', $envFile, 'pg', $tier, '25', $prefix, $product],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                ['ICE_KIT_TEST_PG_DSN' => (string) getenv('ICE_KIT_TEST_PG_DSN'), 'ICE_KIT_TEST_PG_USER' => (string) getenv('ICE_KIT_TEST_PG_USER'), 'ICE_KIT_TEST_PG_PASSWORD' => (string) getenv('ICE_KIT_TEST_PG_PASSWORD'), 'PATH' => (string) getenv('PATH')],
            );
            $this->assertIsResource($p);
            $procs[] = [$p, $pipes];
        }

        $admitted = 0;
        foreach ($procs as [$p, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            proc_close($p);
            $this->assertSame('', $err, 'A worker failed: ' . $err);
            $admitted += (int) $out;
        }

        $this->assertSame(50, $admitted, '150 free attempts from two products against one 50-unit free share admit exactly 50.');
        $this->assertSame(50, (int) $this->connection->pdo()->query("SELECT count(*) FROM turn_leases WHERE tier = 'free'")->fetchColumn());
        $this->assertSame(2, (int) $this->connection->pdo()->query("SELECT count(DISTINCT product) FROM turn_leases")->fetchColumn(), 'Both products really took part.');
    }
}
