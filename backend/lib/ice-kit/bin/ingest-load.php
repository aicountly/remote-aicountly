<?php

declare(strict_types=1);

/**
 * Record what a TURN node is measurably carrying, so admission can account for traffic the lease books
 * cannot see. Reads load reports (JSON, one object or a list) from standard input:
 *
 *   ssh -i ~/.ssh/turn_load turn-load@turn-mum.aicountly.com | php lib/ice-kit/bin/ingest-load.php --bootstrap=config/ice-kit.php
 *
 * where the remote end runs bin/turn-node-load.sh (a forced command: read-only, no shell, no root).
 * Run from the same scheduler as the health probe, at least once a minute — a report older than
 * WEBRTC_TURN_LOAD_MAX_AGE (180 s) is ignored.
 *
 * The bootstrap returns ['load' => Aicountly\Ice\LoadStore, …]. Exit 0 when every report was recorded,
 * 1 when one was refused, 2 on a usage or configuration error. Prints node ids and outcomes only.
 */

use Aicountly\Ice\LoadSource;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/autoload.php';

$bootstrap = '';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--bootstrap=')) {
        $bootstrap = substr($arg, 12);
    } else {
        fwrite(STDERR, "ingest-load: unexpected argument {$arg}\n");
        exit(2);
    }
}
if ($bootstrap === '' || ! is_file($bootstrap)) {
    fwrite(STDERR, "ingest-load: --bootstrap=FILE is required\n");
    exit(2);
}

$kit   = require $bootstrap;
$store = $kit['load'] ?? null;
// Only nodes this deployment knows: a report for any other id is refused, so a node (or a typo) cannot
// evict another node's reading or fill the file.
$known = isset($kit['topology']) ? array_map(static fn ($n): string => $n->id, $kit['topology']->nodes()) : null;
if (! $store instanceof LoadSource) {
    fwrite(STDERR, "ingest-load: the bootstrap file returned no 'load' LoadStore\n");
    exit(2);
}

$raw  = stream_get_contents(STDIN);
$data = json_decode((string) $raw, true);
if (! is_array($data)) {
    fwrite(STDERR, "ingest-load: standard input is not JSON\n");
    exit(2);
}

$reports = isset($data['node']) ? [$data] : $data;
$failed  = false;
foreach ($reports as $report) {
    $result = is_array($report) ? $store->ingest($report, time(), $known) : ['ok' => false, 'error' => 'not_an_object'];
    // Echo the id only when it is a well-formed one: the report is untrusted text and this is a terminal.
    $label = is_array($report) && is_string($report['node'] ?? null) && preg_match('/^[a-z0-9][a-z0-9-]{0,31}\z/', $report['node']) === 1 ? $report['node'] : '?';
    printf("%s: %s\n", $label, $result['ok'] ? 'recorded' : 'refused (' . $result['error'] . ')');
    $failed = $failed || ! $result['ok'];
}

exit($failed ? 1 : 0);
