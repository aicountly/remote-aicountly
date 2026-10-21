<?php

declare(strict_types=1);

/**
 * Reconcile the admission books with what the TURN nodes are really carrying — across every product that shares them.
 *
 *   php lib/ice-kit/bin/reconcile.php --bootstrap=config/ice-kit.php --product=remote [--register]
 *        [--usage-log=FILE --window=3600] [--tolerance=0.25] [--json] [--fail-on=warn|high]
 *
 * What it checks, per node (read-only — it changes nothing in the books, ever):
 *
 *   config      each product's registered capacity for the node agrees (a node has ONE capacity; two products that
 *               disagree cannot both guarantee the reserve). `--register` records THIS product's view first.
 *   invariants  free reserved no more than its share (cap − max(paid, reserve)); the node is not booked past its cap.
 *   drift       booked allocations and bandwidth against the collector's fresh measurement:
 *                 unattributed  the node carries more than the books explain — leases lost, or traffic that never
 *                               took a lease. Admission already charges this to both tiers; here it is surfaced.
 *                 leaked        the books hold more than the node carries — sessions that ended without releasing.
 *                               Leases are the record that keeps the reserve honest, so they are NOT dropped
 *                               automatically; they lapse with their credentials. This tells you how many.
 *   tiers       with `--usage-log`, the share of relayed bytes the RELAY attributes to free users against the share
 *               the books give them.
 *
 * Exit 0 when clean, 1 when a finding at or above `--fail-on` (default warn) exists, 2 on a usage or setup error.
 * Prints no secret, credential, subject or address.
 */

use Aicountly\Ice\Admission;
use Aicountly\Ice\CapacityRegistry;
use Aicountly\Ice\LeaseStore;
use Aicountly\Ice\LoadSource;
use Aicountly\Ice\NodeStatus;
use Aicountly\Ice\Topology;
use Aicountly\Ice\UsageLog;

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
        fwrite(STDERR, "reconcile: unexpected argument {$arg}\n");
        exit(2);
    }
}

$bootstrap = (string) ($opts['bootstrap'] ?? '');
if ($bootstrap === '' || ! is_file($bootstrap)) {
    fwrite(STDERR, "reconcile: --bootstrap=FILE is required\n");
    exit(2);
}

$kit      = require $bootstrap;
$topology = $kit['topology'] ?? null;
$leases   = $kit['leases'] ?? null;
$load     = $kit['load'] ?? null;
if (! $topology instanceof Topology || ! $leases instanceof LeaseStore) {
    fwrite(STDERR, "reconcile: the bootstrap must return 'topology' and 'leases'\n");
    exit(2);
}

$product   = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($opts['product'] ?? 'unknown'))) ?: 'unknown';
$tolerance = max(0.0, min(2.0, (float) ($opts['tolerance'] ?? 0.25)));
$now       = time();
$findings  = [];
$add       = static function (string $severity, string $node, string $code, string $detail) use (&$findings): void {
    $findings[] = ['severity' => $severity, 'node' => $node, 'code' => $code, 'detail' => $detail];
};

// --- config agreement across products ----------------------------------------------------------------
if (isset($opts['register'])) {
    if ($leases instanceof CapacityRegistry) {
        $leases->register($product, $topology->nodes(), $now);
    } else {
        fwrite(STDERR, "reconcile: --register needs the shared ledger (WEBRTC_TURN_LEDGER_DSN); the file books belong to one product\n");
    }
}

$registered = $leases instanceof CapacityRegistry ? $leases->registered() : [];
$byNode     = [];
foreach ($registered as $r) {
    $byNode[$r['node']][$r['product']] = $r;
}
foreach ($byNode as $nodeId => $products) {
    $views = array_unique(array_map(static fn (array $r): string => implode('|', [$r['maxAllocations'], $r['maxBps'], $r['reservePercent'], $r['tiers']]), $products));
    if (count($views) > 1) {
        $add('high', $nodeId, 'config_mismatch', 'products disagree on this node\'s capacity: ' . implode('; ', array_map(
            static fn (array $r): string => "{$r['product']}={$r['maxAllocations']} alloc/" . round($r['maxBps'] / 1e6, 1) . " Mbit/s/{$r['reservePercent']}% reserve",
            $products,
        )));
    }
    if (count($products) < 2 && $leases instanceof CapacityRegistry) {
        $add('warn', $nodeId, 'single_product_registered', 'only ' . implode(',', array_keys($products)) . ' has registered this node; run reconcile.php --register from the other product too');
    }
}

// --- per node ----------------------------------------------------------------------------------------
$book     = $leases->snapshot($now);
$measured = $load instanceof LoadSource ? $load->fresh($now) : [];
$report   = [];

foreach ($topology->nodes() as $node) {
    if ($node->status === NodeStatus::Disabled) {
        continue;
    }

    $usage = $book->usage($node->id);
    $cap   = $node->capacity;
    $row   = [
        'node' => $node->id,
        'metered' => $cap->metered(),
        'booked' => ['allocations' => ['paid' => $usage['paid']['allocations'], 'free' => $usage['free']['allocations']], 'bps' => ['paid' => $usage['paid']['bps'], 'free' => $usage['free']['bps']]],
        'sessions' => ['paid' => $usage['paid']['sessions'], 'free' => $usage['free']['sessions']],
        'measured' => $measured[$node->id] ?? null,
        'byProduct' => [],
    ];

    foreach ($book->all() as $lease) {
        if ($lease->nodeId === $node->id) {
            $row['byProduct'][$lease->product]['leases'] = ($row['byProduct'][$lease->product]['leases'] ?? 0) + 1;
            $row['byProduct'][$lease->product]['allocations'] = ($row['byProduct'][$lease->product]['allocations'] ?? 0) + $lease->allocations;
        }
    }

    if (! $cap->metered()) {
        $report[] = $row;
        continue;
    }

    foreach (['allocations' => $cap->maxAllocations, 'bps' => $cap->maxBps] as $dim => $limit) {
        if ($limit <= 0) {
            continue;
        }
        $paid = $usage['paid'][$dim];
        $free = $usage['free'][$dim];

        if ($free > $limit - max($paid, $cap->reserved($limit))) {
            $add('high', $node->id, 'free_over_share', "free holds {$free} of {$dim} but its share is " . ($limit - max($paid, $cap->reserved($limit))) . ' (paid ' . $paid . ', reserve ' . $cap->reserved($limit) . ', cap ' . $limit . ')');
        }
        if ($paid + $free > $limit) {
            $add('high', $node->id, 'booked_over_capacity', "{$dim} booked " . ($paid + $free) . " > cap {$limit}");
        }
    }

    $m = $measured[$node->id] ?? null;
    if ($m === null) {
        $add('warn', $node->id, 'no_telemetry', 'no fresh load report; admission is running on the books alone');
    } else {
        $booked = $usage['paid']['allocations'] + $usage['free']['allocations'];
        $slack  = 3;
        if ($m['allocations'] > $booked * (1 + $tolerance) + $slack) {
            $add('warn', $node->id, 'unattributed_allocations', "the node holds {$m['allocations']} allocations, the books explain {$booked}: " . ($m['allocations'] - $booked) . ' are unaccounted (charged to both tiers at admission)');
        }
        if ($booked > $m['allocations'] * (1 + $tolerance) + $slack) {
            $add('warn', $node->id, 'leaked_leases', "the books hold {$booked} allocations, the node carries {$m['allocations']}: about " . (int) ceil(($booked - $m['allocations']) / max(1, $booked / max(1, $usage['paid']['leases'] + $usage['free']['leases']))) . ' leases belong to sessions that ended without releasing (they lapse with their credentials)');
        }
        $bookedBps = $usage['paid']['bps'] + $usage['free']['bps'];
        if ($bookedBps > 0 && $m['bps'] > 0) {
            $ratio = $m['bps'] / $bookedBps;
            $row['bandwidthRatio'] = round($ratio, 2);
            if ($ratio > 1 + $tolerance + 0.5 || $ratio < 1 / (1 + $tolerance + 0.5)) {
                $add('warn', $node->id, 'bandwidth_drift', 'measured NIC rx+tx is ' . round($ratio, 2) . '× the booked bandwidth: the usage profiles (WEBRTC_TURN_PROFILE_*_KBPS) do not match real sessions');
            }
        }
        $row['load'] = Admission::load(max(
            $cap->maxAllocations > 0 ? max($booked, $m['allocations']) / $cap->maxAllocations : 0.0,
            $cap->maxBps > 0 ? max($bookedBps, $m['bps']) / $cap->maxBps : 0.0,
        ), $topology->admission);
    }

    $report[] = $row;
}

// --- tier shares from the relay's own log -----------------------------------------------------------
$tiers = null;
if (isset($opts['usage-log']) && is_string($opts['usage-log'])) {
    $h = @fopen($opts['usage-log'], 'r');
    if ($h === false) {
        fwrite(STDERR, "reconcile: cannot read the usage log\n");
        exit(2);
    }
    $lines = (static function () use ($h): Generator {
        while (($l = fgets($h)) !== false) {
            yield $l;
        }
    })();
    $tiers = UsageLog::summarize($lines);
    $bytes = array_sum(array_column($tiers['tiers'], 'bytes'));
    $free  = array_sum(array_map(static fn (array $r): int => $r['booked']['bps']['free'], $report));
    $all   = array_sum(array_map(static fn (array $r): int => $r['booked']['bps']['free'] + $r['booked']['bps']['paid'], $report));
    if ($bytes > 0 && $all > 0) {
        $relayShare  = $tiers['tiers']['free']['bytes'] / $bytes;
        $bookedShare = $free / $all;
        $tiers['freeShare'] = ['relay' => round($relayShare, 3), 'booked' => round($bookedShare, 3)];
        if ($relayShare > $bookedShare * (1 + $tolerance) + 0.05) {
            $add('high', '*', 'free_traffic_over_booked_share', 'the relays attribute ' . round($relayShare * 100) . '% of carried bytes to free users, the books give them ' . round($bookedShare * 100) . '%');
        }
    }
}

$order = ['high' => 2, 'warn' => 1];
usort($findings, static fn (array $a, array $b): int => $order[$b['severity']] <=> $order[$a['severity']]);

if (isset($opts['json'])) {
    echo json_encode(['product' => $product, 'at' => gmdate('c', $now), 'nodes' => $report, 'registered' => $registered, 'tiers' => $tiers, 'findings' => $findings], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
} else {
    foreach ($report as $row) {
        $m = $row['measured'];
        printf(
            "%-8s booked alloc paid %d free %d · Mbit/s paid %.1f free %.1f · sessions paid %d free %d · measured %s%s\n",
            $row['node'], $row['booked']['allocations']['paid'], $row['booked']['allocations']['free'],
            $row['booked']['bps']['paid'] / 1e6, $row['booked']['bps']['free'] / 1e6, $row['sessions']['paid'], $row['sessions']['free'],
            $m === null ? 'n/a' : "{$m['allocations']} alloc, " . round($m['bps'] / 1e6, 1) . ' Mbit/s (' . $m['ageSeconds'] . 's old)',
            $row['byProduct'] === [] ? '' : ' · by product ' . implode(', ', array_map(static fn (string $p, array $v): string => "{$p} {$v['leases']} leases", array_keys($row['byProduct']), $row['byProduct'])),
        );
    }
    if ($findings === []) {
        echo "reconciled: no findings\n";
    }
    foreach ($findings as $f) {
        printf("%-4s %-8s %-30s %s\n", strtoupper($f['severity']), $f['node'], $f['code'], $f['detail']);
    }
}

$failOn = ($opts['fail-on'] ?? 'warn') === 'high' ? 2 : 1;
exit(array_filter($findings, static fn (array $f): bool => $order[$f['severity']] >= $failOn) === [] ? 0 : 1);
