<?php

declare(strict_types=1);

/**
 * Regenerates licence-v1-vectors.json — the conformance vectors for docs/LICENCE_ENTITLEMENT_CONTRACT.md.
 *
 *   php tests/contract/generate-vectors.php > tests/contract/licence-v1-vectors.json
 *
 * The signing key is derived from a PUBLISHED, FIXED seed so the output is reproducible byte for byte (Ed25519 signatures are
 * deterministic). That key is a TEST KEY: it signs nothing real and no product is ever configured with it outside tests. A team
 * building the central backend can check its serialisation and signatures against these vectors. Each case is a CONTEXT (the
 * authenticated user, the active company, the product, and the product's own membership evidence), a set of cached envelopes
 * (each filed under the scope and reference the sync would use) and revocations; a product must resolve it to `expect`.
 */

use Aicountly\Ice\Licence\EntitlementVerifier;

require_once dirname(__DIR__, 2) . '/autoload.php';

$pair   = sodium_crypto_sign_seed_keypair(hash('sha256', 'aicountly-licence-contract-v1-TEST-VECTORS-ONLY', true));
$secret = sodium_crypto_sign_secretkey($pair);
$now    = 1_800_000_000;
$iso    = static fn (int $t): string => gmdate('Y-m-d\TH:i:s\Z', $t);

$wide = ['model' => 'company_wide', 'member' => null, 'seat' => 'not_applicable'];
$base = [
    'contract' => 'aicountly.licence/1', 'scope' => 'company', 'customer_id' => 'A', 'product_id' => 'chat', 'subject' => null,
    'licence_id' => 'LIC-000123', 'plan_code' => 'CHAT_TEAM', 'plan_class' => 'paid', 'kind' => 'paid', 'state' => 'paid',
    'valid_from' => $iso($now - 86400), 'valid_until' => $iso($now + 30 * 86400),
    'entitlements' => [['code' => 'meeting.max_guests', 'value' => 10]],
    'revision' => 5, 'issued_at' => $iso($now - 600), 'eligibility' => $wide,
];
$sign   = static fn (array $doc, string $kid = 'contract-test-1') => EntitlementVerifier::sign($doc, $kid, $secret);
$member = static fn (string $c, string $u, bool $isMember, string $model = 'company_wide', string $seat = 'not_applicable', array $over = []): array => ['customer_id' => $c, 'subject' => $u, 'eligibility' => ['model' => $model, 'member' => $isMember, 'seat' => $seat]] + $over;
$free   = ['state' => 'free', 'licence_id' => null, 'plan_class' => 'free', 'kind' => 'free', 'valid_until' => null];

$companyEnv = static fn (array $over = [], string $ref = 'A', string $kid = 'contract-test-1'): array => ['scope' => 'customer', 'ref' => $ref, 'envelope' => $sign($over + $base, $kid)];
$memberEnv  = static fn (string $c, string $u, array $over = []): array => ['scope' => 'member', 'ref' => "{$c}|{$u}", 'envelope' => $sign($over + $base)];
$personalEnv = static fn (string $u, array $over = []): array => ['scope' => 'user', 'ref' => $u, 'envelope' => $sign($over + ['scope' => 'user', 'customer_id' => null, 'subject' => $u, 'plan_code' => 'CHAT_PERSONAL',
    'eligibility' => ['model' => 'personal', 'member' => null, 'seat' => 'not_applicable']] + $base)];

$case = static fn (string $name, string $why, ?string $customer, ?string $subject, ?bool $verified, array $envelopes, array $expect, array $extra = []): array => [
    'name' => $name, 'why' => $why, 'now' => $extra['now'] ?? 1_800_000_000, 'product' => $extra['product'] ?? 'chat',
    'context' => ['customer' => $customer, 'subject' => $subject, 'membership_verified' => $verified],
    'envelopes' => $envelopes, 'revocations' => $extra['revocations'] ?? [], 'expect' => $expect,
];
$e = static fn (string $state, string $tier, string $reason, ?string $basis = null): array => ['state' => $state, 'tier' => $tier, 'reason' => $reason, 'basis' => $basis];

$seatDoc = ['eligibility' => ['model' => 'seat', 'member' => null, 'seat' => 'not_applicable']];

$cases = [
    // ---- company-wide licence ---------------------------------------------------------------------------
    $case('company_wide_member_verified_by_product', 'A company-wide subscription qualifies a current member the product has verified.', 'A', 'u1', true, [$companyEnv()], $e('paid', 'paid', 'paid', 'company_wide')),
    $case('company_wide_no_membership_evidence', 'The company is paid but nothing says this user belongs to it: not paid.', 'A', 'u1', null, [$companyEnv()], $e('free', 'free', 'membership_unverified', 'company_wide')),
    $case('company_wide_user_removed_per_product', 'The product knows the user is no longer a member.', 'A', 'u1', false, [$companyEnv()], $e('free', 'free', 'not_a_member', 'company_wide')),
    $case('company_wide_member_document', 'The backend states current membership in a member-level document.', 'A', 'u2', null, [$memberEnv('A', 'u2', $member('A', 'u2', true))], $e('paid', 'paid', 'paid', 'company_wide')),
    $case('member_document_not_a_member', 'The backend states the user is not (or no longer) a member.', 'A', 'u3', null, [$memberEnv('A', 'u3', $member('A', 'u3', false))], $e('free', 'free', 'not_a_member', 'company_wide')),
    $case('company_level_question_without_user', 'A company-level question (e.g. a Remote session accounted to its company) is answered by a company-wide licence.', 'A', null, null, [$companyEnv()], $e('paid', 'paid', 'paid', 'company_wide')),
    // ---- seats ------------------------------------------------------------------------------------------
    $case('seat_assigned', 'Individually assigned seat: the holder is paid.', 'S', 'holder', null, [$companyEnv($seatDoc + ['customer_id' => 'S'], 'S'), $memberEnv('S', 'holder', $member('S', 'holder', true, 'seat', 'assigned'))], $e('paid', 'paid', 'paid', 'seat')),
    $case('seat_unassigned', 'A member of a seat-licensed company without a seat.', 'S', 'spare', null, [$companyEnv($seatDoc + ['customer_id' => 'S'], 'S'), $memberEnv('S', 'spare', $member('S', 'spare', true, 'seat', 'unassigned'))], $e('free', 'free', 'seat_unassigned', 'seat')),
    $case('seat_revoked', 'A seat that has been revoked.', 'S', 'former', null, [$memberEnv('S', 'former', $member('S', 'former', true, 'seat', 'revoked'))], $e('free', 'free', 'seat_revoked', 'seat')),
    $case('seat_licence_company_document_only', 'A seat licence cannot be satisfied by the company document and a membership claim.', 'S', 'stranger', true, [$companyEnv($seatDoc + ['customer_id' => 'S'], 'S')], $e('free', 'free', 'seat_not_assigned', 'seat')),
    $case('seat_licence_question_without_user', 'A seat licence needs a user.', 'S', null, null, [$companyEnv($seatDoc + ['customer_id' => 'S'], 'S')], $e('free', 'free', 'seat_requires_subject', 'seat')),
    $case('seat_revoked_by_feed', 'The changes feed revoked this (company, user) pair at the document\'s revision.', 'S', 'u1', null, [$memberEnv('S', 'u1', $member('S', 'u1', true, 'seat', 'assigned'))], $e('suspended', 'free', 'revoked', 'seat'), ['revocations' => [['customer' => 'S', 'subject' => 'u1', 'revision' => 5]]]),
    $case('seat_revocation_of_another_user', 'Revoking another user\'s seat does not touch this one.', 'S', 'u2', null, [$memberEnv('S', 'u2', $member('S', 'u2', true, 'seat', 'assigned'))], $e('paid', 'paid', 'paid', 'seat'), ['revocations' => [['customer' => 'S', 'subject' => 'u1', 'revision' => 5]]]),
    $case('company_lapse_beats_seat_document', 'The company licence is suspended; a seat document that has not caught up does not help.', 'S', 'u1', null, [$companyEnv($seatDoc + ['customer_id' => 'S', 'state' => 'suspended'], 'S'), $memberEnv('S', 'u1', $member('S', 'u1', true, 'seat', 'assigned'))], $e('suspended', 'free', 'suspended', 'seat')),
    // ---- tenants and switching --------------------------------------------------------------------------
    $case('switching_company_no_entitlement', 'The same user in company B, which has no licence for them: nothing carries over from A.', 'B', 'u1', true, [$companyEnv(), $memberEnv('A', 'u1', $member('A', 'u1', true))], $e('unknown', 'free', 'no_entitlement', null)),
    $case('switching_company_expired', 'The same user in company B whose subscription has expired.', 'B', 'u1', true, [$companyEnv(['state' => 'expired', 'customer_id' => 'B'], 'B'), $memberEnv('A', 'u1', $member('A', 'u1', true))], $e('expired', 'free', 'expired', 'company_wide')),
    $case('misfiled_member_document', 'A genuine paid member document of company A filed under company B.', 'B', 'u1', null, [['scope' => 'member', 'ref' => 'B|u1', 'envelope' => $sign($member('A', 'u1', true) + $base)]], $e('unknown', 'free', 'identity_mismatch', null)),
    $case('misfiled_company_document', 'A genuine paid company document of A filed under B.', 'B', null, null, [$companyEnv([], 'B')], $e('unknown', 'free', 'identity_mismatch', null)),
    $case('member_document_of_another_user', 'u1\'s member document filed under u9.', 'A', 'u9', null, [['scope' => 'member', 'ref' => 'A|u9', 'envelope' => $sign($member('A', 'u1', true) + $base)]], $e('unknown', 'free', 'identity_mismatch', null)),
    $case('wrong_product', 'A genuine paid document for another product.', 'A', 'u1', true, [$companyEnv(['product_id' => 'remote'])], $e('unknown', 'free', 'identity_mismatch', null)),
    // ---- personal licences ------------------------------------------------------------------------------
    $case('personal_licence_in_personal_context', 'A personal, user-scoped licence applies in a personal context.', null, 'u1', null, [$personalEnv('u1')], $e('paid', 'paid', 'paid', 'personal')),
    $case('personal_licence_not_carried_into_a_company', 'It is not carried into a company context where the company has no licence for the user.', 'Z', 'u1', true, [$personalEnv('u1')], $e('unknown', 'free', 'no_entitlement', null)),
    $case('company_licence_is_not_personal', 'A company licence does not make a personal context paid.', null, 'u1', null, [$companyEnv(), $memberEnv('A', 'u1', $member('A', 'u1', true))], $e('unknown', 'free', 'no_entitlement', null)),
    $case('personal_context_without_user', 'No company and no user: nothing to evaluate.', null, null, null, [], $e('unknown', 'free', 'no_context', null)),
    // ---- states, validity, freshness, trust -------------------------------------------------------------
    $case('free_plan', 'The company is on a free plan.', 'A', 'u1', true, [$companyEnv($free + ['plan_code' => 'CHAT_FREE'])], $e('free', 'free', 'free', 'company_wide')),
    $case('state_expired', 'The backend states the licence has expired.', 'A', 'u1', true, [$companyEnv(['state' => 'expired'])], $e('expired', 'free', 'expired', 'company_wide')),
    $case('valid_until_passed', 'State still says paid but valid_until has passed by the time of use.', 'A', 'u1', true, [$companyEnv(['valid_until' => $iso($now - 60)])], $e('expired', 'free', 'valid_until_passed', 'company_wide')),
    $case('state_suspended', 'The backend states the licence is suspended.', 'A', 'u1', true, [$companyEnv(['state' => 'suspended'])], $e('suspended', 'free', 'suspended', 'company_wide')),
    $case('state_unknown', 'The backend itself does not know.', 'A', 'u1', true, [$companyEnv(['state' => 'unknown'])], $e('unknown', 'free', 'central_unknown', 'company_wide')),
    $case('stale_document', 'Issued more than the maximum age (48 h) before use.', 'A', 'u1', true, [$companyEnv(['issued_at' => $iso($now - 3 * 86400)])], $e('unknown', 'free', 'stale', null)),
    $case('issued_in_future', 'Issued after "now" by more than the clock-skew allowance.', 'A', 'u1', true, [$companyEnv(['issued_at' => $iso($now + 3600)])], $e('unknown', 'free', 'issued_in_future', null)),
    $case('not_yet_valid', 'valid_from is in the future.', 'A', 'u1', true, [$companyEnv(['valid_from' => $iso($now + 3600)])], $e('unknown', 'free', 'not_yet_valid', 'company_wide')),
    $case('trial_is_not_paid_capacity', 'A paid-state trial: the default policy admits kind "paid" only.', 'A', 'u1', true, [$companyEnv(['kind' => 'trial'])], $e('free', 'free', 'kind_not_paid', 'company_wide')),
    $case('company_revoked', 'A revocation of the company document at its revision.', 'A', null, null, [$companyEnv()], $e('suspended', 'free', 'revoked', 'company_wide'), ['revocations' => [['customer' => 'A', 'subject' => null, 'revision' => 5]]]),
    $case('company_revocation_of_older_revision', 'A revocation of an OLDER revision does not affect a newer document.', 'A', null, null, [$companyEnv()], $e('paid', 'paid', 'paid', 'company_wide'), ['revocations' => [['customer' => 'A', 'subject' => null, 'revision' => 4]]]),
    $case('tampered_field', 'A field edited after signing.', 'A', 'u1', true, [['scope' => 'customer', 'ref' => 'A', 'envelope' => (static function () use ($sign, $base): array {
        $t = $sign($base);
        $t['document']['plan_code'] = 'CHAT_ENTERPRISE';

        return $t;
    })()]], $e('unknown', 'free', 'signature_invalid', null)),
    $case('unsigned', 'No signature at all.', 'A', 'u1', true, [['scope' => 'customer', 'ref' => 'A', 'envelope' => ['document' => $base]]], $e('unknown', 'free', 'signature_invalid', null)),
    $case('signed_by_another_key', 'Signed by a key the product does not hold, under a known key id.', 'A', 'u1', true, [['scope' => 'customer', 'ref' => 'A', 'envelope' => EntitlementVerifier::sign($base, 'contract-test-1', sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(hash('sha256', 'some-other-signer', true))))]], $e('unknown', 'free', 'signature_invalid', null)),
    $case('unknown_key_id', 'Signed correctly but under a key id the product does not hold.', 'A', 'u1', true, [$companyEnv([], 'A', 'rotated-away-key')], $e('unknown', 'free', 'signature_invalid', null)),
    $case('malformed_paid_without_licence', 'State paid but no licence_id: refused as malformed.', 'A', 'u1', true, [$companyEnv(['licence_id' => null])], $e('unknown', 'free', 'malformed', null)),
    $case('malformed_member_claim_on_company_document', 'A company-level document that claims a membership: refused as malformed.', 'A', 'u1', true, [$companyEnv(['eligibility' => ['model' => 'company_wide', 'member' => true, 'seat' => 'not_applicable']])], $e('unknown', 'free', 'malformed', null)),
];

$out = [
    'contract'   => 'aicountly.licence/1',
    'note'       => 'TEST VECTORS ONLY. The key below is derived from a published seed and signs nothing real. Regenerate with tests/contract/generate-vectors.php.',
    'public_key' => ['kid' => 'contract-test-1', 'alg' => 'ed25519', 'value' => base64_encode(sodium_crypto_sign_publickey($pair))],
    'policy'     => ['max_age_seconds' => 172800, 'paid_kinds' => ['paid']],
    'cases'      => $cases,
];

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
