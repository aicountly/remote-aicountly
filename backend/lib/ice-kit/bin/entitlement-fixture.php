<?php

declare(strict_types=1);

/**
 * AICOUNTLY ICE kit — licence entitlement fixtures and diagnostics, for development and staging BEFORE the central backend exists.
 *
 *   keys     --out=DIR --kid=ID                 generate an Ed25519 key pair. DIR/ID.secret (0600) STAYS on the admin machine;
 *                                               the printed `ID=<base64>` line is the public key for WEBRTC_LICENCE_PUBLIC_KEYS.
 *   sign     --secret-key-file=F --kid=ID --customer=ID --product=remote --state=paid|free|expired|suspended|unknown
 *            [--plan=CODE --licence=REF --kind=paid|trial|complimentary --valid-days=30 --subject=U --revision=1 --out=FILE]
 *                                               write a SIGNED envelope exactly as the central backend would (contract §3).
 *   load     --bootstrap=config/ice-kit.php --file=ENVELOPE      verify against this host's public keys and store in the cache
 *   revoke   --bootstrap=... --customer=ID --product=remote --revision=N      record a revocation (what the changes feed does)
 *   resolve  --bootstrap=... --customer=ID --product=remote [--subject=U]     print what a credential request would see (state, tier, reason, evidence)
 *
 * This is how the REAL path (signature check, freshness, revocation, state mapping) is exercised on a staging host while the
 * central licensing module is still under development. A fixture is signed with a STAGING key whose private half is never on
 * the API host; the host's WEBRTC_LICENCE_PUBLIC_KEYS must hold only staging public keys, never the production one.
 * Output never contains a private key.
 */

use Aicountly\Ice\Licence\EntitlementCache;
use Aicountly\Ice\Licence\EntitlementVerifier;
use Aicountly\Ice\Licence\LicenceRuntime;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/autoload.php';

$cmd  = $argv[1] ?? '';
$opts = [];
foreach (array_slice($argv, 2) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m) === 1) {
        $opts[$m[1]] = $m[2] ?? true;
    } else {
        fwrite(STDERR, "entitlement-fixture: unexpected argument {$arg}\n");
        exit(2);
    }
}
$fail = static function (string $msg): never {
    fwrite(STDERR, "entitlement-fixture: {$msg}\n");
    exit(2);
};
if (! function_exists('sodium_crypto_sign_keypair')) {
    $fail('libsodium is required');
}
$iso = static fn (int $t): string => gmdate('Y-m-d\TH:i:s\Z', $t);

if ($cmd === 'keys') {
    $dir = (string) ($opts['out'] ?? '');
    $kid = (string) ($opts['kid'] ?? '');
    if ($dir === '' || preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $kid) !== 1) {
        $fail('keys needs --out=DIR and --kid=ID');
    }
    if (! is_dir($dir) && ! mkdir($dir, 0700, true)) {
        $fail("cannot create {$dir}");
    }
    $pair = sodium_crypto_sign_keypair();
    $file = rtrim($dir, '/') . "/{$kid}.secret";
    if (file_exists($file)) {
        $fail("{$file} already exists; refusing to overwrite a key");
    }
    file_put_contents($file, base64_encode(sodium_crypto_sign_secretkey($pair)));
    chmod($file, 0600);
    echo "private key written to {$file} (keep it off the API host)\n";
    echo "WEBRTC_LICENCE_PUBLIC_KEYS entry: {$kid}=" . base64_encode(sodium_crypto_sign_publickey($pair)) . "\n";
    exit(0);
}

if ($cmd === 'sign') {
    $keyFile = (string) ($opts['secret-key-file'] ?? '');
    $secret  = is_file($keyFile) ? base64_decode(trim((string) file_get_contents($keyFile)), true) : false;
    if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        $fail('--secret-key-file must be a key written by `keys`');
    }
    $state = (string) ($opts['state'] ?? '');
    $now   = isset($opts['issued-at']) ? (int) strtotime((string) $opts['issued-at']) : time();
    $days  = max(1, (int) ($opts['valid-days'] ?? 30));
    $paid  = $state === 'paid';
    $doc   = [
        'contract'     => 'aicountly.licence/1',
        'customer_id'  => (string) ($opts['customer'] ?? ''),
        'product_id'   => (string) ($opts['product'] ?? ''),
        'subject'      => isset($opts['subject']) ? (string) $opts['subject'] : null,
        'licence_id'   => isset($opts['licence']) ? (string) $opts['licence'] : ($paid ? 'LIC-FIXTURE-1' : null),
        'plan_code'    => isset($opts['plan']) ? (string) $opts['plan'] : ($paid ? 'STAGING_PAID_TEST' : null),
        'plan_class'   => $paid ? 'paid' : 'free',
        'kind'         => (string) ($opts['kind'] ?? ($paid ? 'paid' : 'free')),
        'state'        => $state,
        'valid_from'   => $iso($now - 86400),
        'valid_until'  => $paid || $state === 'expired' ? $iso($state === 'expired' ? $now - 60 : $now + $days * 86400) : null,
        'entitlements' => [],
        'revision'     => (int) ($opts['revision'] ?? 1),
        'issued_at'    => $iso($now),
    ];
    try {
        \Aicountly\Ice\Licence\Entitlement::fromDocument($doc);
    } catch (InvalidArgumentException $e) {
        $fail('the document would be refused by every product: ' . $e->getMessage());
    }
    $json = json_encode(EntitlementVerifier::sign($doc, (string) ($opts['kid'] ?? ''), $secret), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    if (isset($opts['out'])) {
        file_put_contents((string) $opts['out'], $json);
        echo "signed envelope written to {$opts['out']}\n";
    } else {
        echo $json;
    }
    exit(0);
}

$bootstrap = (string) ($opts['bootstrap'] ?? '');
if (! is_file($bootstrap)) {
    $fail("{$cmd} needs --bootstrap=config/ice-kit.php");
}
$kit  = require $bootstrap;
$read = $kit['read'] ?? null;
$dir  = (string) ($kit['dir'] ?? '');
if (! is_callable($read) || $dir === '') {
    $fail("the bootstrap must return 'read' and 'dir'");
}
$cache    = LicenceRuntime::cache($read, $dir);
$verifier = EntitlementVerifier::fromEnvironment($read);
$product  = (string) ($opts['product'] ?? '');

switch ($cmd) {
    case 'load':
        $env = json_decode((string) @file_get_contents((string) ($opts['file'] ?? '')), true);
        if (! is_array($env) || ! $verifier->verify($env)) {
            $fail('the envelope is unreadable or its signature does not verify against WEBRTC_LICENCE_PUBLIC_KEYS - not stored');
        }
        $e = \Aicountly\Ice\Licence\Entitlement::fromDocument((array) $env['document']);
        $cache->put($e->subject === null ? EntitlementCache::CUSTOMER : EntitlementCache::SUBJECT, $e->subject ?? $e->customerId, $e->productId, $env, time());
        echo "stored: state {$e->state->value}, revision {$e->revision}\n";
        exit(0);

    case 'revoke':
        $cache->revoke((string) ($opts['customer'] ?? ''), $product, (int) ($opts['revision'] ?? 0), time());
        echo "revocation recorded\n";
        exit(0);

    case 'resolve':
        $resolver = LicenceRuntime::resolver($read, $dir, null, $cache);
        $r = isset($opts['subject']) ? $resolver->forSubject((string) $opts['subject'], $product) : $resolver->forCustomer((string) ($opts['customer'] ?? ''), $product);
        echo sprintf("state %s · tier %s · reason %s · evidence %s%s\n", $r->state->value, $r->tier()->value, $r->reason, $r->evidence, $r->planCode !== null ? " · plan {$r->planCode}" : '');
        exit(0);
}
$fail('unknown command (keys | sign | load | revoke | resolve)');
