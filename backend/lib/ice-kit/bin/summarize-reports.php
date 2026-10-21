<?php

declare(strict_types=1);

/**
 * AICOUNTLY ICE kit — summarise the connectivity reports in the API's logs.
 *
 *   php lib/ice-kit/bin/summarize-reports.php writable/logs/log-2026-10-21.log [more.log ...] [--json]
 *
 * Reads the `remote.ice.report` / `connect.ice.report` lines the products write and prints how many
 * connections there were, how many were relayed (through which node, over which transport), how long
 * they took, and why the failed ones failed. The staging test's evidence. Exit 0 unless it could not
 * read a file or found no reports at all (exit 1).
 */

require_once dirname(__DIR__) . '/autoload.php';

use Aicountly\Ice\ReportSummary;

$files = array_values(array_filter(array_slice($argv, 1), static fn (string $a): bool => ! str_starts_with($a, '--')));
$json  = in_array('--json', $argv, true);

if ($files === []) {
    fwrite(STDERR, "usage: php lib/ice-kit/bin/summarize-reports.php LOGFILE [LOGFILE ...] [--json]\n");
    exit(2);
}

$summary = new ReportSummary();
foreach ($files as $file) {
    $text = @file_get_contents($file);
    if ($text === false) {
        fwrite(STDERR, "cannot read {$file}\n");
        exit(2);
    }
    $summary->addLog($text);
}

$s = $summary->summary();
if ($json) {
    echo json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit($s['reports'] > 0 ? 0 : 1);
}

if ($s['reports'] === 0) {
    echo "No connectivity reports found in: " . implode(', ', $files) . "\n";
    exit(1);
}

$line = static fn (array $m): string => $m === [] ? '-' : implode(', ', array_map(static fn ($k, $v) => "{$k}={$v}", array_keys($m), $m));

echo "reports:   {$s['reports']}  (" . $line($s['products']) . ")\n";
echo "outcomes:  " . $line($s['outcomes']) . "\n";
echo "path:      " . $line($s['paths']) . "  relayed share " . ($s['relayedShare'] === null ? '-' : round($s['relayedShare'] * 100) . '%') . "\n";
echo "relay via: " . $line($s['relayByNodeProtocol']) . "\n";
echo "ICE errors: " . $line($s['iceErrors']) . "\n";
foreach (['relay', 'direct'] as $p) {
    $t = $s['timeToConnectMs'][$p];
    echo sprintf("time to connect, %-6s n=%d p50=%s p95=%s ms\n", $p . ':', $t['n'], $t['p50'] ?? '-', $t['p95'] ?? '-');
}
echo "ICE restarts: {$s['iceRestarts']}   credential renewals: {$s['credentialRenewals']}\n";
exit(0);
