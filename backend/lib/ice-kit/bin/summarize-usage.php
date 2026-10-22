<?php

declare(strict_types=1);

/**
 * Attribute the traffic a TURN node carried to licence tiers, from coturn's own log.
 *
 *   php lib/ice-kit/bin/summarize-usage.php /var/log/turnserver/turn_2026-10-21.log [more files] [--window=3600] [--json]
 *   journalctl -u coturn --since '1 hour ago' | php lib/ice-kit/bin/summarize-usage.php --window=3600
 *
 * Reads the files (or standard input), counts every closed allocation's client-leg bytes by the tier
 * its username names, and — given the length of the log window in seconds — the average rate. It is the
 * relay's side of the story, independent of the application's lease books: run it after a load test to
 * see whether free traffic stayed inside its share. Prints no username, address or credential.
 */

use Aicountly\Ice\UsageLog;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/autoload.php';

$files  = [];
$window = 0;
$json   = false;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--window=(\d+)$/', $arg, $m) === 1) {
        $window = (int) $m[1];
    } elseif ($arg === '--json') {
        $json = true;
    } elseif (str_starts_with($arg, '--')) {
        fwrite(STDERR, "summarize-usage: unknown option {$arg}\n");
        exit(2);
    } else {
        $files[] = $arg;
    }
}

$lines = (static function () use ($files): \Generator {
    if ($files === []) {
        while (($line = fgets(STDIN)) !== false) {
            yield $line;
        }

        return;
    }
    foreach ($files as $file) {
        $h = @fopen($file, 'r');
        if ($h === false) {
            fwrite(STDERR, "summarize-usage: cannot read {$file}\n");
            exit(2);
        }
        while (($line = fgets($h)) !== false) {
            yield $line;
        }
        fclose($h);
    }
})();

$summary = UsageLog::summarize($lines);
$total   = array_sum(array_column($summary['tiers'], 'bytes'));

foreach ($summary['tiers'] as $tier => &$row) {
    $row['share'] = $total > 0 ? round($row['bytes'] / $total, 4) : 0.0;
    if ($window > 0) {
        $row['avgBps'] = (int) round($row['bytes'] * 8 / $window);
    }
}
unset($row);

if ($json) {
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

printf("%d closed allocations read (%d without a credential ignored)\n", $summary['lines'], $summary['ignored']);
printf("%-9s %13s %14s %14s %7s %12s\n", 'tier', 'closed allocs', 'bytes in', 'bytes out', 'share', $window > 0 ? 'avg bit/s' : '');
foreach ($summary['tiers'] as $tier => $row) {
    printf("%-9s %13d %14d %14d %6.1f%% %12s\n", $tier, $row['allocations'], $row['bytesIn'], $row['bytesOut'], $row['share'] * 100, isset($row['avgBps']) ? (string) $row['avgBps'] : '');
}
