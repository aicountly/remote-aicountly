<?php

declare(strict_types=1);

/**
 * AICOUNTLY ICE kit — probe the configured TURN nodes from the API host.
 *
 *   php lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php [options]
 *
 *   --node=mum,ncr        probe these nodes, including disabled ones (how a new node is validated
 *                         before its status is set to active). Default: every node not disabled.
 *   --all                 every configured node, disabled included
 *   --transports=udp,tls  limit the transports tried (default: all the node offers)
 *   --allocate            also allocate a relay with a freshly minted credential — the real test
 *   --write-health        record the result for IceIssuer (the scheduled job does this)
 *   --status              print what the API would serve, and the recorded health; probe nothing
 *   --json                machine-readable output
 *   --timeout=5           seconds per step
 *   --ca-file=PATH        trust this CA for turns: (a test relay); --insecure skips verification
 *
 * The bootstrap returns ['topology' => Aicountly\Ice\Topology, 'health' => ?Aicountly\Ice\HealthStore],
 * built from the product's own .env. Exit code: 0 every probed node passed, 1 one failed, 2 usage or
 * configuration error. Output never contains a secret or a credential.
 */

use Aicountly\Ice\HealthStore;
use Aicountly\Ice\IceIssuer;
use Aicountly\Ice\NodeStatus;
use Aicountly\Ice\Probe\TurnProbe;
use Aicountly\Ice\Topology;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/autoload.php';

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m) === 1) {
        $opts[$m[1]] = $m[2] ?? true;
    } else {
        fwrite(STDERR, "probe: unexpected argument {$arg}\n");
        exit(2);
    }
}

$bootstrap = (string) ($opts['bootstrap'] ?? '');
if ($bootstrap === '' || ! is_file($bootstrap)) {
    fwrite(STDERR, "usage: php lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php [--node=id,...] [--all] [--allocate] [--write-health] [--status] [--json]\n");
    exit(2);
}

$kit      = require $bootstrap;
$topology = $kit['topology'] ?? null;
$health   = $kit['health'] ?? null;

if (! $topology instanceof Topology || ($health !== null && ! $health instanceof HealthStore)) {
    fwrite(STDERR, "probe: {$bootstrap} must return ['topology' => Topology, 'health' => ?HealthStore]\n");
    exit(2);
}

$json = isset($opts['json']);
$out  = static function (string $line) use ($json): void {
    if (! $json) {
        fwrite(STDOUT, $line . "\n");
    }
};

foreach ($topology->problems() as $problem) {
    fwrite(STDERR, "config: {$problem}\n");
}

if (isset($opts['status'])) {
    $status = (new IceIssuer($topology, $health))->status();

    if ($json) {
        fwrite(STDOUT, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    } else {
        $out("source: {$status['source']}   relay configured: " . ($status['relayConfigured'] ? 'yes' : 'NO'));
        foreach ($status['nodes'] as $node) {
            $out(sprintf('  %-10s %-9s priority %-4d region %-10s health %-8s %s', $node['id'], $node['status'], $node['priority'], $node['region'] ?: '-', $node['health'], $node['servable'] ? '' : '(not served)'));
        }
    }

    exit($topology->problems() === [] ? 0 : 2);
}

$wanted = null;
if (is_string($opts['node'] ?? null)) {
    $wanted = array_values(array_filter(array_map('trim', explode(',', strtolower($opts['node'])))));
}

$nodes = array_values(array_filter($topology->nodes(), static function ($node) use ($wanted, $opts): bool {
    if ($wanted !== null) {
        return in_array($node->id, $wanted, true);
    }

    return isset($opts['all']) || $node->status !== NodeStatus::Disabled;
}));

if ($wanted !== null && count($nodes) !== count($wanted)) {
    $known = array_map(static fn ($n) => $n->id, $topology->nodes());
    fwrite(STDERR, 'probe: unknown node(s): ' . implode(', ', array_diff($wanted, $known)) . "\n");
    exit(2);
}

if ($nodes === []) {
    $out('No TURN node to probe (none configured, or all disabled — use --node=<id> or --all).');
    exit($topology->problems() === [] ? 0 : 2);
}

$transports = is_string($opts['transports'] ?? null)
    ? array_values(array_filter(array_map('trim', explode(',', strtolower($opts['transports'])))))
    : null;

$probe = new TurnProbe(
    max(1.0, (float) ($opts['timeout'] ?? 5)),
    [
        'caFile'     => is_string($opts['ca-file'] ?? null) ? $opts['ca-file'] : null,
        'verifyPeer' => ! isset($opts['insecure']),
    ],
);

$allocate = isset($opts['allocate']);
$results  = [];
$failed   = false;

foreach ($nodes as $node) {
    $result = $probe->probeNode($node, $transports, $allocate, min(600, $topology->ttlSeconds), 'probe');
    $results[$node->id] = $result;
    $failed = $failed || ! $result['ok'];

    $out(sprintf('%s %s (%s)', $result['ok'] ? 'PASS' : 'FAIL', $node->id, $node->status->value));
    foreach ($result['transports'] as $transport => $step) {
        $line = sprintf('  %-4s binding %s', $transport, $step['binding']['ok'] ? "ok {$step['binding']['rttMs']}ms" : 'FAILED ' . ($step['binding']['error'] ?? ''));
        if (isset($step['allocation'])) {
            $a = $step['allocation'];
            $line .= '   allocation ' . ($a['ok'] ? "ok {$a['rttMs']}ms realm={$a['realm']}" : 'FAILED ' . ($a['error'] ?? '') . (isset($a['detail']) ? " ({$a['detail']})" : ''));
        }
        $out($line);
    }
    if ($result['transports'] === []) {
        $out('  no transport matched');
    }
}

if (isset($opts['write-health'])) {
    if ($health === null) {
        fwrite(STDERR, "probe: --write-health needs the bootstrap to return a HealthStore\n");
        exit(2);
    }

    $health->record(array_map(static fn (array $r): array => [
        'ok'         => $r['ok'],
        'error'      => $r['error'],
        'transports' => array_map(static fn (array $s): array => [
            'binding'    => $s['binding']['ok'],
            'allocation' => isset($s['allocation']) ? $s['allocation']['ok'] : null,
        ], $r['transports']),
    ], $results), time());

    $out('health recorded: ' . $health->path());
}

if ($json) {
    fwrite(STDOUT, json_encode(['ok' => ! $failed, 'allocate' => $allocate, 'nodes' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

exit($failed ? 1 : 0);
