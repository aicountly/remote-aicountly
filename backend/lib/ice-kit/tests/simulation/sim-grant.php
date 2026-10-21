<?php

declare(strict_types=1);

/**
 * Issue the grant a client would receive right now, from a bootstrap file's topology, health and (when it
 * returns them) lease books and load file, and print it as JSON. For the simulations in this directory
 * only: the output holds live credentials for the simulation's own throwaway relays.
 *
 *   php sim-grant.php boot.php [tier=free] [region=] [subject=simulation] [session=]
 */

require_once dirname(__DIR__, 2) . '/autoload.php';

use Aicountly\Ice\IceIssuer;

$kit = require $argv[1];
$issuer = new IceIssuer($kit['topology'], $kit['health'] ?? null, null, 'simulation', $kit['leases'] ?? null, $kit['load'] ?? null);

echo json_encode($issuer->issue($argv[4] ?? 'simulation', [
    'tier'       => $argv[2] ?? 'free',
    'preferRegion' => ($argv[3] ?? '') ?: null,
    'sessionRef' => $argv[5] ?? '',
])->toArray(), JSON_UNESCAPED_SLASHES);
