<?php

declare(strict_types=1);

/**
 * Issue the grant a client would receive right now, from a bootstrap file's topology and health, and
 * print it as JSON. For tests/simulation/failover.sh only: the output holds live credentials for the
 * simulation's own throwaway relays.
 *
 *   php sim-grant.php boot.php > grant.json
 */

require_once dirname(__DIR__, 2) . '/autoload.php';

use Aicountly\Ice\IceIssuer;

$kit = require $argv[1];
echo json_encode((new IceIssuer($kit['topology'], $kit['health']))->issue('simulation')->toArray(), JSON_UNESCAPED_SLASHES);
