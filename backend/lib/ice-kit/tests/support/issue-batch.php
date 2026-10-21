<?php

declare(strict_types=1);

/**
 * Test helper: one process issuing many grants against a shared lease file, so LeaseStoreTest can prove
 * the books stay correct when requests race. Not part of the kit.
 *
 *   php issue-batch.php <env.json> <leases-file | pg> <tier> <count> <prefix> [product]
 *
 * With `pg` the books are the shared PostgreSQL ledger named by ICE_KIT_TEST_PG_DSN/_USER/_PASSWORD, so
 * processes standing in for different products (the optional `product`) race against ONE set of books.
 *
 * Prints the number of clients that were given a relay.
 */

use Aicountly\Ice\FileLeaseStore;
use Aicountly\Ice\LedgerConnection;
use Aicountly\Ice\PdoLeaseStore;
use Aicountly\Ice\IceIssuer;
use Aicountly\Ice\Tier;
use Aicountly\Ice\Topology;

require_once dirname(__DIR__, 2) . '/autoload.php';

[, $envFile, $leaseFile, $tierName, $count, $prefix] = $argv;
$productName = $argv[6] ?? 'remote';

$env      = json_decode((string) file_get_contents($envFile), true);
$topology = Topology::fromEnvironment(static fn (string $k): ?string => $env[$k] ?? null);
$store    = $leaseFile === 'pg'
    ? new PdoLeaseStore(new LedgerConnection((string) getenv('ICE_KIT_TEST_PG_DSN'), (string) getenv('ICE_KIT_TEST_PG_USER'), (string) getenv('ICE_KIT_TEST_PG_PASSWORD')))
    : new FileLeaseStore($leaseFile);
$issuer   = new IceIssuer($topology, null, static fn (): int => 1_800_000_000, $productName, $store);
$tier     = Tier::parse($tierName);

$admitted = 0;
for ($i = 0; $i < (int) $count; $i++) {
    $grant = $issuer->issue("{$prefix}-{$i}", ['tier' => $tier, 'sessionRef' => "{$prefix}-{$i}", 'preferRegion' => 'in-west']);
    $admitted += $grant->relayAvailable() ? 1 : 0;
}

echo $admitted;
