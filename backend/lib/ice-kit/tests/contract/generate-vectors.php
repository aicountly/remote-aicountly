<?php

declare(strict_types=1);

/**
 * Regenerates licence-v1-vectors.json — the conformance vectors for docs/LICENCE_ENTITLEMENT_CONTRACT.md.
 *
 *   php tests/contract/generate-vectors.php > tests/contract/licence-v1-vectors.json
 *
 * The signing key is derived from a PUBLISHED, FIXED seed so the output is reproducible byte for byte (Ed25519 signatures are
 * deterministic). That key is a TEST KEY: it signs nothing real and no product is ever configured with it outside tests. A team
 * building the central backend can check its serialisation and signatures against these vectors: for every case, a product
 * given `envelope` at time `now` must resolve it to `expect`.
 */

use Aicountly\Ice\Licence\EntitlementVerifier;

require_once dirname(__DIR__, 2) . '/autoload.php';

$pair   = sodium_crypto_sign_seed_keypair(hash('sha256', 'aicountly-licence-contract-v1-TEST-VECTORS-ONLY', true));
$secret = sodium_crypto_sign_secretkey($pair);
$now    = 1_800_000_000;
$iso    = static fn (int $t): string => gmdate('Y-m-d\TH:i:s\Z', $t);

$base = [
    'contract' => 'aicountly.licence/1', 'customer_id' => '42', 'product_id' => 'remote', 'subject' => null,
    'licence_id' => 'LIC-000123', 'plan_code' => 'REMOTE_BUSINESS', 'plan_class' => 'paid', 'kind' => 'paid', 'state' => 'paid',
    'valid_from' => $iso($now - 86400), 'valid_until' => $iso($now + 30 * 86400),
    'entitlements' => [['code' => 'max_session_minutes', 'value' => 480], ['code' => 'recording', 'value' => true]],
    'revision' => 5, 'issued_at' => $iso($now - 600),
];
$free = ['state' => 'free', 'licence_id' => null, 'plan_code' => 'REMOTE_FREE', 'plan_class' => 'free', 'kind' => 'free', 'valid_until' => null, 'entitlements' => []] + $base;

$sign = static fn (array $doc, string $kid = 'contract-test-1') => EntitlementVerifier::sign($doc, $kid, $secret);
$case = static fn (string $name, string $why, array $envelope, array $expect, array $extra = []): array => ['name' => $name, 'why' => $why, 'now' => $extra['now'] ?? 1_800_000_000, 'product' => $extra['product'] ?? 'remote', 'scope' => $extra['scope'] ?? 'customer', 'ref' => $extra['ref'] ?? '42', 'revoked_revision' => $extra['revoked_revision'] ?? -1, 'envelope' => $envelope, 'expect' => $expect];

$tampered = $sign($base);
$tampered['document']['plan_code'] = 'REMOTE_ENTERPRISE';
$unsigned = ['document' => $base];
$otherKey = EntitlementVerifier::sign($base, 'contract-test-1', sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(hash('sha256', 'some-other-signer', true))));

$cases = [
    $case('paid_valid', 'A signed, fresh, current paid licence of an allowed kind.', $sign($base), ['state' => 'paid', 'tier' => 'paid', 'reason' => 'paid']),
    $case('free_plan', 'The customer is on a free plan.', $sign($free), ['state' => 'free', 'tier' => 'free', 'reason' => 'free']),
    $case('state_expired', 'The backend states the licence has expired.', $sign(['state' => 'expired'] + $base), ['state' => 'expired', 'tier' => 'free', 'reason' => 'expired']),
    $case('valid_until_passed', 'State still says paid but valid_until has passed by the time of use.', $sign(['valid_until' => $iso($now - 60)] + $base), ['state' => 'expired', 'tier' => 'free', 'reason' => 'valid_until_passed']),
    $case('state_suspended', 'The backend states the licence is suspended.', $sign(['state' => 'suspended'] + $base), ['state' => 'suspended', 'tier' => 'free', 'reason' => 'suspended']),
    $case('state_unknown', 'The backend itself does not know.', $sign(['state' => 'unknown'] + $base), ['state' => 'unknown', 'tier' => 'free', 'reason' => 'central_unknown']),
    $case('stale_document', 'Issued more than the maximum age (48 h) before use: a stopped feed or a replay is not trusted.', $sign(['issued_at' => $iso($now - 3 * 86400)] + $base), ['state' => 'unknown', 'tier' => 'free', 'reason' => 'stale']),
    $case('issued_in_future', 'Issued after "now" by more than the clock-skew allowance.', $sign(['issued_at' => $iso($now + 3600)] + $base), ['state' => 'unknown', 'tier' => 'free', 'reason' => 'issued_in_future']),
    $case('not_yet_valid', 'valid_from is in the future.', $sign(['valid_from' => $iso($now + 3600)] + $base), ['state' => 'unknown', 'tier' => 'free', 'reason' => 'not_yet_valid']),
    $case('trial_is_not_paid_capacity', 'A paid-state trial: the default policy admits kind "paid" only.', $sign(['kind' => 'trial'] + $base), ['state' => 'free', 'tier' => 'free', 'reason' => 'kind_not_paid']),
    $case('revoked_revision', 'A revocation at or above the document revision was recorded; revocation beats a still-valid-looking document.', $sign($base), ['state' => 'suspended', 'tier' => 'free', 'reason' => 'revoked'], ['revoked_revision' => 5]),
    $case('revoked_older_revision_only', 'A revocation of an OLDER revision does not affect a newer document.', $sign($base), ['state' => 'paid', 'tier' => 'paid', 'reason' => 'paid'], ['revoked_revision' => 4]),
    $case('tampered_field', 'A field edited after signing.', $tampered, ['state' => 'unknown', 'tier' => 'free', 'reason' => 'signature_invalid']),
    $case('unsigned', 'No signature at all.', $unsigned, ['state' => 'unknown', 'tier' => 'free', 'reason' => 'signature_invalid']),
    $case('signed_by_another_key', 'Signed by a key the product does not hold, under a known key id.', $otherKey, ['state' => 'unknown', 'tier' => 'free', 'reason' => 'signature_invalid']),
    $case('unknown_key_id', 'Signed correctly but under a key id the product does not hold.', $sign($base, 'rotated-away-key'), ['state' => 'unknown', 'tier' => 'free', 'reason' => 'signature_invalid']),
    $case('wrong_customer', 'A genuine paid document for customer 99 presented for customer 42 (tenant isolation).', $sign(['customer_id' => '99'] + $base), ['state' => 'unknown', 'tier' => 'free', 'reason' => 'identity_mismatch']),
    $case('wrong_product', 'A genuine paid document for product "chat" presented for "remote" (tenant isolation).', $sign(['product_id' => 'chat'] + $base), ['state' => 'unknown', 'tier' => 'free', 'reason' => 'identity_mismatch']),
    $case('subject_scope_paid', 'A by-subject answer: the best entitlement among the user\'s customers, naming the subject.', $sign(['product_id' => 'chat', 'subject' => 'u-7', 'plan_code' => 'CHAT_TEAM'] + $base), ['state' => 'paid', 'tier' => 'paid', 'reason' => 'paid'], ['product' => 'chat', 'scope' => 'subject', 'ref' => 'u-7']),
    $case('subject_document_for_customer_lookup', 'A subject-scoped document does not answer a customer lookup.', $sign(['subject' => 'u-7'] + $base), ['state' => 'unknown', 'tier' => 'free', 'reason' => 'identity_mismatch']),
    $case('malformed_paid_without_licence', 'State paid but no licence_id: the document is refused as malformed.', $sign(['licence_id' => null] + $base), ['state' => 'unknown', 'tier' => 'free', 'reason' => 'malformed']),
];

$out = [
    'contract'   => 'aicountly.licence/1',
    'note'       => 'TEST VECTORS ONLY. The key below is derived from a published seed and signs nothing real. Regenerate with tests/contract/generate-vectors.php.',
    'public_key' => ['kid' => 'contract-test-1', 'alg' => 'ed25519', 'value' => base64_encode(sodium_crypto_sign_publickey($pair))],
    'policy'     => ['max_age_seconds' => 172800, 'paid_kinds' => ['paid']],
    'cases'      => $cases,
];

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
