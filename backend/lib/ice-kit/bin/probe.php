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
 *   --negative            with --allocate: also prove the relay REFUSES a wrong-secret credential, an
 *                         expired one, and none at all. A relay that allocates for any of them fails
 *   --expiry-test[=tcp]   (about 80 s per node) what the relay does to a LIVE allocation whose credential
 *                         expires, and to a NEW allocation with that expired credential. Decides how much
 *                         credential renewal is worth; run it once per node, on the real relay
 *   --expect-ip=mum=1.2.3.4,ncr=5.6.7.8
 *                         fail a node whose host name does not resolve to the address you expect
 *   --emit-grant=FILE     write an aicountly.ice/1 grant for the probed nodes to FILE (mode 0600) for
 *                         web/src/ice-kit/tools/relay-check.mjs on a test machine. --ttl=SECONDS
 *                         (60..900, default 600) bounds it; it holds live credentials — delete it after
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
if (! isset($opts['status'])) {
    foreach ($topology->warnings() as $warning) {
        fwrite(STDERR, "warning: {$warning}\n");
    }
}

if (isset($opts['status'])) {
    $status = (new IceIssuer($topology, $health))->status();

    if ($json) {
        fwrite(STDOUT, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    } else {
        $out("source: {$status['source']}   relay configured: " . ($status['relayConfigured'] ? 'yes' : 'NO'));
        foreach ($status['nodes'] as $node) {
            $out(sprintf(
                '  %-8s %-9s %-12s priority %-4d region %-9s probe %-9s %s',
                $node['id'],
                $node['role'],
                $node['state'],
                $node['priority'],
                $node['region'] ?: '-',
                $node['health'],
                $node['serving'] ? 'SERVING NOW' : '',
            ));
        }
        foreach ($status['warnings'] as $warning) {
            $out("  warning: {$warning}");
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

$negative = isset($opts['negative']);
if ($negative && ! $allocate) {
    fwrite(STDERR, "probe: --negative needs --allocate\n");
    exit(2);
}

$expectIp = [];
foreach (array_filter(explode(',', is_string($opts['expect-ip'] ?? null) ? $opts['expect-ip'] : '')) as $pair) {
    [$id, $ip] = array_pad(explode('=', $pair, 2), 2, '');
    $expectIp[strtolower(trim($id))] = trim($ip);
}

foreach ($nodes as $node) {
    $result = $probe->probeNode($node, $transports, $allocate, min(600, $topology->ttlSeconds), 'probe', $negative);

    // Where the name points, as seen from here: the first thing to check when a node "cannot be reached".
    $host     = $node->describe()['urls'] !== [] ? (TurnProbe::parseTurnUrl($node->describe()['urls'][0])['host'] ?? '') : '';
    $resolved = $host !== '' && filter_var($host, FILTER_VALIDATE_IP) === false ? (gethostbynamel($host) ?: []) : ($host !== '' ? [$host] : []);
    $result['host']     = $host;
    $result['resolved'] = $resolved;
    if (isset($expectIp[$node->id])) {
        $result['expectedIp'] = $expectIp[$node->id];
        if (! in_array($expectIp[$node->id], $resolved, true)) {
            $result['ok']    = false;
            $result['error'] = 'wrong_address';
        }
    }

    $results[$node->id] = $result;
    $failed = $failed || ! $result['ok'];

    $out(sprintf('%s %s (%s) %s -> %s', $result['ok'] ? 'PASS' : 'FAIL', $node->id, $node->status->value, $host ?: '-', $resolved ? implode(',', $resolved) : 'unresolved'));
    if (($result['error'] ?? null) === 'wrong_address') {
        $out("  host resolves to " . implode(',', $resolved) . ", expected {$expectIp[$node->id]}");
    }
    foreach ($result['transports'] as $transport => $step) {
        $line = sprintf('  %-4s binding %s', $transport, $step['binding']['ok'] ? "ok {$step['binding']['rttMs']}ms" : 'FAILED ' . ($step['binding']['error'] ?? ''));
        if (isset($step['allocation'])) {
            $a = $step['allocation'];
            $line .= '   allocation ' . ($a['ok'] ? "ok {$a['rttMs']}ms realm={$a['realm']}" : 'FAILED ' . ($a['error'] ?? '') . (isset($a['detail']) ? " ({$a['detail']})" : ''));
        }
        $out($line);

        $cert = $step['binding']['certificate'] ?? null;
        if ($cert !== null) {
            $out(sprintf(
                '       certificate %s for %s, issuer %s, valid to %s (%d days)%s',
                $cert['verified'] ? 'VERIFIED' : 'NOT VERIFIED',
                $cert['subject'] ?: ($cert['san'][0] ?? '?'),
                $cert['issuer'] ?: '?',
                substr($cert['validTo'], 0, 10),
                $cert['daysRemaining'],
                $cert['daysRemaining'] < 14 ? '  <-- renew soon' : '',
            ));
        }

        foreach ($step['negative'] ?? [] as $check => $n) {
            $out(sprintf('       must refuse %-11s %s%s', $check, strtoupper($n['verdict']), $n['verdict'] === 'accepted' ? '  <-- SECURITY FINDING' : ''));
        }
    }
    if ($result['transports'] === []) {
        $out('  no transport matched');
    }
    if (($result['error'] ?? null) === 'accepts_invalid_credentials') {
        $out('  this relay allocated for a credential it must refuse');
    }
}

if (isset($opts['expiry-test'])) {
    $transport = is_string($opts['expiry-test']) && $opts['expiry-test'] !== '' ? strtolower($opts['expiry-test']) : 'tcp';
    foreach ($nodes as $node) {
        $out("expiry test: {$node->id} over {$transport} (about 80 s)...");
        $measured = $probe->expirySemantics($node, $transport);
        $results[$node->id]['expirySemantics'] = $measured;

        foreach ($measured['steps'] ?? [] as $step) {
            $out(sprintf('  %-20s %-15s %s', $step['at'], $step['action'], $step['ok'] ? 'SUCCESS' : 'REFUSED ' . ($step['error'] ?? '')));
        }
        if (isset($measured['establishedSurvivesExpiry'])) {
            $out('  => an established allocation ' . ($measured['establishedSurvivesExpiry'] ? 'SURVIVES' : 'DOES NOT SURVIVE') . ' its credential expiring; '
                . 'a new allocation with the expired credential is ' . ($measured['newAllocationRefusedAfterExpiry'] ? 'refused' : 'ACCEPTED (expiry is not enforced at all)'));
        } else {
            $out('  => not measured: ' . ($measured['error'] ?? 'unknown'));
            $failed = true;
        }
    }
}

if (is_string($opts['emit-grant'] ?? null) && $opts['emit-grant'] !== '') {
    $ttl = max(60, min(900, (int) ($opts['ttl'] ?? 600)));
    $now = time();
    $servers = [];
    $described = [];
    foreach ($nodes as $node) {
        $urls = array_values(array_filter($node->turnUrls(), static fn (string $u): bool => $transports === null
            || in_array(TurnProbe::parseTurnUrl($u)['transport'] ?? '', $transports, true)));
        $credentials = $node->credentialsFor('relay-check', $ttl, $now);
        if ($urls === [] || $credentials === null) {
            continue;
        }
        $servers[]   = ['urls' => $urls, 'username' => $credentials['username'], 'credential' => $credentials['credential']];
        $described[] = ['id' => $node->id, 'label' => $node->describe()['label'], 'region' => $node->region, 'priority' => $node->priority, 'status' => $node->status->value, 'health' => 'unknown', 'urls' => $urls];
    }

    $grant = [
        'contract' => 'aicountly.ice/1', 'iceServers' => $servers, 'iceTransportPolicy' => 'all', 'iceCandidatePoolSize' => 0,
        'relayAvailable' => $servers !== [], 'expiresIn' => $ttl, 'refreshAfter' => (int) floor($ttl * 0.8),
        'relayNodes' => $described, 'degraded' => false, 'failover' => false,
    ];

    $file = $opts['emit-grant'];
    $old  = umask(0177);
    $wrote = @file_put_contents($file, json_encode($grant, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
    umask($old);
    @chmod($file, 0600);
    if (! $wrote) {
        fwrite(STDERR, "probe: could not write {$file}\n");
        exit(2);
    }
    $out("grant written: {$file} (mode 0600, " . count($servers) . " relay server(s), valid {$ttl}s) - it holds live credentials; delete it after use");
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
    // The evidence envelope: who ran this, where from, when, against what — the facts a result is
    // meaningless without. No secret and no credential is in it.
    fwrite(STDOUT, json_encode([
        'ok'          => ! $failed,
        'allocate'    => $allocate,
        'negative'    => $negative,
        'probedFrom'  => gethostname() ?: 'unknown',
        'probedAt'    => gmdate('c'),
        'php'         => PHP_VERSION,
        'kitVersion'  => trim((string) @file_get_contents(dirname(__DIR__) . '/VERSION')),
        'nodes'       => $results,
        'warnings'    => $topology->warnings(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

exit($failed ? 1 : 0);
