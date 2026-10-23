<?php

declare(strict_types=1);

namespace Aicountly\Ice\Tests;

use Aicountly\Ice\FileLeaseStore;
use Aicountly\Ice\Lease;
use Aicountly\Ice\LeaseBook;
use Aicountly\Ice\Tier;

require_once __DIR__ . '/IceKitTestCase.php';

/** The books: persistence, expiry, corruption, and — the one that matters — races. */
final class LeaseStoreTest extends IceKitTestCase
{
    private function lease(string $key, string $node = 'mum', Tier $tier = Tier::Free, int $expires = self::NOW + 3600): Lease
    {
        return new Lease($key, $node, $tier, 'remote', 'default', 'u-' . $key, 's-' . $key, 3, 2_000_000, self::NOW, $expires);
    }

    public function testLeasesPersistAcrossInstancesAndCountPerTier(): void
    {
        $path = $this->tempPath();
        (new FileLeaseStore($path))->transact(function (LeaseBook $b): void {
            $b->put($this->lease('a'));
            $b->put($this->lease('b', 'mum', Tier::Paid));
            $b->put($this->lease('c', 'ncr'));
        }, self::NOW);

        $usage = (new FileLeaseStore($path))->snapshot(self::NOW)->usage('mum');

        $this->assertSame(['allocations' => 3, 'bps' => 2_000_000, 'leases' => 1, 'sessions' => 1], $usage['paid']);
        $this->assertSame(['allocations' => 3, 'bps' => 2_000_000, 'leases' => 1, 'sessions' => 1], $usage['free']);
        @unlink($path . '.lock');
    }

    public function testExpiredLeasesDropOutAfterTheGracePeriod(): void
    {
        $store = new FileLeaseStore($this->tempPath());
        $store->transact(fn (LeaseBook $b) => $b->put($this->lease('a', 'mum', Tier::Free, self::NOW + 10)), self::NOW);

        $this->assertSame(1, $store->snapshot(self::NOW + 10 + LeaseBook::GRACE_SECONDS)->usage('mum')['free']['leases'], 'An allocation outlives its credential by a little.');
        $this->assertSame(0, $store->snapshot(self::NOW + 11 + LeaseBook::GRACE_SECONDS)->usage('mum')['free']['leases']);
    }

    public function testACorruptFileIsEmptyBooksNotAnOutage(): void
    {
        $path = $this->tempPath();
        file_put_contents($path, '{not json');

        $this->assertSame([], (new FileLeaseStore($path))->snapshot(self::NOW)->all());
        (new FileLeaseStore($path))->transact(fn (LeaseBook $b) => $b->put($this->lease('a')), self::NOW);
        $this->assertCount(1, (new FileLeaseStore($path))->snapshot(self::NOW)->all(), 'And it heals on the next write.');
    }

    public function testNoSecretOrCredentialIsEverWrittenToTheBooks(): void
    {
        $path   = $this->tempPath();
        $issuer = new \Aicountly\Ice\IceIssuer($this->topology($this->activeActiveEnv()), null, static fn (): int => self::NOW, 'remote', new FileLeaseStore($path));
        $grant  = $issuer->issue('participant-1', ['tier' => Tier::Paid, 'sessionRef' => 'sess-1']);

        $file = (string) file_get_contents($path);
        $this->assertNotSame('', $file);
        foreach (['mumbai-secret-0123456789', 'noida-secret-0123456789'] as $secret) {
            $this->assertStringNotContainsString($secret, $file);
        }
        foreach ($grant->iceServers() as $server) {
            if (isset($server['credential'])) {
                $this->assertStringNotContainsString($server['credential'], $file);
            }
        }
        @unlink($path . '.lock');
    }

    /**
     * @param list<array{0: string, 1: string}> $jobs [tier, prefix] — each a process issuing 25 grants
     * @return array{free: int, paid: int} clients each tier was given a relay
     */
    private function race(string $envFile, string $books, array $jobs): array
    {
        $procs = [];
        foreach ($jobs as [$tier, $prefix]) {
            $cmd = [PHP_BINARY, __DIR__ . '/support/issue-batch.php', $envFile, $books, $tier, '25', $prefix];
            $p   = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($p);
            $procs[] = [$p, $pipes, $tier];
        }

        $admitted = ['free' => 0, 'paid' => 0];
        foreach ($procs as [$p, $pipes, $tier]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            proc_close($p);
            $this->assertSame('', $err, 'A worker failed: ' . $err);
            $admitted[$tier] += (int) $out;
        }

        return $admitted;
    }

    /**
     * Racing processes against one node holding 100 units (50 reserved for paid). However they interleave,
     * the books must hold: no lost update, no over-admission, and free never inside the reserve.
     *
     * Same-tier races are exact; a free/paid mix is checked by its invariants only, because how many free
     * clients get in legitimately depends on how many paid ones arrived first (paid growth shrinks free).
     */
    public function testConcurrentRequestsCannotOverAdmitOrLoseALease(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is unavailable.');
        }

        $env = $this->activeActiveEnv(['WEBRTC_TURN_NODE_NCR_STATUS' => 'disabled', 'WEBRTC_TURN_MAX_NODES' => '1']);
        $envFile = $this->tempPath();
        file_put_contents($envFile, json_encode($env));

        // Six processes, 150 free attempts, a 50-unit free share: exactly 50 get in.
        $books = $this->tempPath();
        $free  = $this->race($envFile, $books, array_map(static fn (int $i): array => ['free', "f{$i}"], range(1, 6)));
        $usage = (new FileLeaseStore($books))->snapshot(self::NOW)->usage('mum');
        $this->assertSame(50, $free['free']);
        $this->assertSame(50, $usage['free']['leases'], 'A lease was lost or invented.');

        // Then three paid processes, 75 attempts, a 50-unit reserve: exactly 50 get in, and the node is exactly full.
        $paid  = $this->race($envFile, $books, array_map(static fn (int $i): array => ['paid', "p{$i}"], range(1, 3)));
        $usage = (new FileLeaseStore($books))->snapshot(self::NOW)->usage('mum');
        $this->assertSame(50, $paid['paid']);
        $this->assertSame(50, $usage['paid']['leases']);
        $this->assertSame(100, $usage['free']['leases'] + $usage['paid']['leases']);

        // Both at once, from empty books.
        $mixed = $this->tempPath();
        $got   = $this->race($envFile, $mixed, [['free', 'a1'], ['free', 'a2'], ['free', 'a3'], ['free', 'a4'], ['free', 'a5'], ['free', 'a6'], ['paid', 'b1'], ['paid', 'b2'], ['paid', 'b3']]);
        $usage = (new FileLeaseStore($mixed))->snapshot(self::NOW)->usage('mum');
        $this->assertSame($got['free'], $usage['free']['leases'], 'A lease was lost or invented.');
        $this->assertSame($got['paid'], $usage['paid']['leases'], 'A lease was lost or invented.');
        $this->assertLessThanOrEqual(100, $usage['free']['leases'] + $usage['paid']['leases'], 'Over-admitted.');
        $this->assertLessThanOrEqual(100 - max($usage['paid']['leases'], 50), $usage['free']['leases'], 'Free users ate into the paid reserve.');
        // Paid may also take free-pool units free has not claimed yet, so how far above 50 it gets depends on who arrives first.
        $this->assertGreaterThanOrEqual(50, $usage['paid']['leases'], 'The whole reserve was available to paid whatever free did.');

        foreach ([$books, $mixed] as $path) {
            @unlink($path);
            @unlink($path . '.lock');
        }
        @unlink($envFile);
    }
}
