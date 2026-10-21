<?php

declare(strict_types=1);

/**
 * Test helper: one process issuing many grants against a shared lease file, so LeaseStoreTest can prove
 * the books stay correct when requests race. Not part of the kit.
 *
 *   php issue-batch.php <env.json> <leases-file> <tier> <count> <prefix>
 *
 * Prints the number of clients that were given a relay.
 */

use Aicountly\Ice\FileLeaseStore;
use Aicountly\Ice\IceIssuer;
use Aicountly\Ice\Tier;
use Aicountly\Ice\Topology;

require_once dirname(__DIR__, 2) . '/autoload.php';

[, $envFile, $leaseFile, $tierName, $count, $prefix] = $argv;

$env      = json_decode((string) file_get_contents($envFile), true);
$topology = Topology::fromEnvironment(static fn (string $k): ?string => $env[$k] ?? null);
$issuer   = new IceIssuer($topology, null, static fn (): int => 1_800_000_000, 'remote', new FileLeaseStore($leaseFile));
$tier     = Tier::parse($tierName);

$admitted = 0;
for ($i = 0; $i < (int) $count; $i++) {
    $grant = $issuer->issue("{$prefix}-{$i}", ['tier' => $tier, 'sessionRef' => "{$prefix}-{$i}", 'preferRegion' => 'in-west']);
    $admitted += $grant->relayAvailable() ? 1 : 0;
}

echo $admitted;
