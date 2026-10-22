<?php

declare(strict_types=1);

/**
 * AICOUNTLY ICE kit — bring this product's licence-entitlement cache up to date from the central licensing backend.
 *
 *   php lib/ice-kit/bin/sync-entitlements.php --bootstrap=config/ice-kit.php --product=remote [--full] [--json]
 *
 * Run every minute or two by the scheduler. A full snapshot is read the first time (or with --full), then the changes feed —
 * which is also how REVOCATIONS arrive. Every envelope is signature-verified before it is stored. Exit 0 = a complete pass,
 * 1 = the backend could not be read (the cache and its heartbeat are left as they were, so a stopped feed becomes visible
 * to the readiness check), 2 = usage or configuration error. Prints counts only: no customer, licence or secret.
 *
 * Until the central backend exists this exits 2 ("not configured") — expected during development.
 */

use Aicountly\Ice\Licence\CentralClient;
use Aicountly\Ice\Licence\CentralUnavailable;
use Aicountly\Ice\Licence\EntitlementSync;
use Aicountly\Ice\Licence\EntitlementVerifier;
use Aicountly\Ice\Licence\LicenceRuntime;

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
        fwrite(STDERR, "sync-entitlements: unexpected argument {$arg}\n");
        exit(2);
    }
}

$bootstrap = (string) ($opts['bootstrap'] ?? '');
$product   = preg_replace('/[^a-z0-9._-]/', '', strtolower((string) ($opts['product'] ?? '')));
if ($bootstrap === '' || ! is_file($bootstrap) || $product === '') {
    fwrite(STDERR, "usage: php lib/ice-kit/bin/sync-entitlements.php --bootstrap=config/ice-kit.php --product=<product id> [--full] [--json]\n");
    exit(2);
}

$kit  = require $bootstrap;
$read = $kit['read'] ?? null;
$dir  = (string) ($kit['dir'] ?? '');
if (! is_callable($read) || $dir === '') {
    fwrite(STDERR, "sync-entitlements: {$bootstrap} must return 'read' (env reader) and 'dir' (writable directory)\n");
    exit(2);
}

$client = CentralClient::fromEnvironment($read, $product);
if ($client === null) {
    fwrite(STDERR, "sync-entitlements: the central licence API is not configured (WEBRTC_LICENCE_API_URL, _KEY_ID, _ENVIRONMENT, _S2S_SECRET) - nothing to sync\n");
    exit(2);
}
$verifier = EntitlementVerifier::fromEnvironment($read);
if (! $verifier->available()) {
    fwrite(STDERR, "sync-entitlements: no usable WEBRTC_LICENCE_PUBLIC_KEYS (or libsodium missing) - refusing to store unverifiable entitlements\n");
    exit(2);
}

try {
    $stats = (new EntitlementSync($client, LicenceRuntime::cache($read, $dir), $verifier, $product))->run(isset($opts['full']));
} catch (CentralUnavailable $e) {
    fwrite(STDERR, 'sync-entitlements: ' . $e->getMessage() . "\n");
    exit(1);
}

echo isset($opts['json'])
    ? json_encode($stats, JSON_UNESCAPED_SLASHES) . "\n"
    : sprintf("%s: stored %d, rejected %d, revoked %d, cursor %d%s\n", $product, $stats['stored'], $stats['rejected'], $stats['revoked'], $stats['cursor'], $stats['full'] ? ' (full snapshot)' : '');
exit(0);
