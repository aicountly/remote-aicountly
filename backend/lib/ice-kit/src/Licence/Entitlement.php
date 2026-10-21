<?php

declare(strict_types=1);

namespace Aicountly\Ice\Licence;

/**
 * One licence entitlement document — what the central licensing backend says about ONE customer's licence for
 * ONE product (docs/LICENCE_ENTITLEMENT_CONTRACT.md §3). Immutable and strictly validated: anything malformed is
 * refused, not repaired, because a document that cannot be read cleanly is not evidence of anything.
 *
 * The document is the signed part. What a product learns locally (when it fetched it, whether it was revoked
 * since) lives in the cache beside it, never inside it.
 */
final class Entitlement
{
    public const CONTRACT = 'aicountly.licence/1';

    private const TIME = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';

    /** @param list<array{code: string, value: string|int|bool}> $entitlements */
    private function __construct(
        public readonly string $customerId,
        public readonly string $productId,
        public readonly ?string $subject,
        public readonly ?string $licenceId,
        public readonly ?string $planCode,
        public readonly string $planClass,
        public readonly string $kind,
        public readonly LicenceState $state,
        public readonly ?int $validFrom,
        public readonly ?int $validUntil,
        public readonly array $entitlements,
        public readonly int $revision,
        public readonly int $issuedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $doc the `document` member of an envelope
     * @throws \InvalidArgumentException naming the first problem
     */
    public static function fromDocument(array $doc): self
    {
        if (($doc['contract'] ?? null) !== self::CONTRACT) {
            throw new \InvalidArgumentException('contract must be ' . self::CONTRACT);
        }

        $customer = self::id($doc['customer_id'] ?? null, 'customer_id', 64);
        $product  = self::id($doc['product_id'] ?? null, 'product_id', 32);
        $subject  = ($doc['subject'] ?? null) === null ? null : self::id($doc['subject'], 'subject', 64);

        $licence = ($doc['licence_id'] ?? null) === null ? null : self::id($doc['licence_id'], 'licence_id', 64);
        $plan    = ($doc['plan_code'] ?? null) === null ? null : self::id($doc['plan_code'], 'plan_code', 64);

        $planClass = $doc['plan_class'] ?? null;
        if (! in_array($planClass, ['free', 'paid'], true)) {
            throw new \InvalidArgumentException('plan_class must be free or paid');
        }
        $kind = $doc['kind'] ?? null;
        if (! in_array($kind, ['free', 'paid', 'trial', 'complimentary'], true)) {
            throw new \InvalidArgumentException('kind must be free, paid, trial or complimentary');
        }
        $stateRaw = $doc['state'] ?? null;
        if (! is_string($stateRaw) || LicenceState::tryFrom($stateRaw) === null) {
            throw new \InvalidArgumentException('state must be one of free, paid, expired, suspended, unknown');
        }
        $state = LicenceState::from($stateRaw);

        if ($state === LicenceState::Paid && ($licence === null || $plan === null || $planClass !== 'paid')) {
            throw new \InvalidArgumentException('a paid state needs licence_id, plan_code and plan_class paid');
        }

        $from  = self::time($doc['valid_from'] ?? null, 'valid_from');
        $until = self::time($doc['valid_until'] ?? null, 'valid_until');
        if ($from !== null && $until !== null && $until <= $from) {
            throw new \InvalidArgumentException('valid_until must be after valid_from');
        }

        $list = $doc['entitlements'] ?? null;
        if (! is_array($list) || ! array_is_list($list) || count($list) > 64) {
            throw new \InvalidArgumentException('entitlements must be a list of at most 64 {code, value}');
        }
        $seen = [];
        $ents = [];
        foreach ($list as $e) {
            if (! is_array($e) || ! is_string($e['code'] ?? null) || preg_match('/^[a-z][a-z0-9_.]{0,63}$/', $e['code']) !== 1
                || ! (is_string($e['value'] ?? null) || is_int($e['value'] ?? null) || is_bool($e['value'] ?? null))
                || (is_string($e['value']) && strlen($e['value']) > 128) || isset($seen[$e['code']])) {
                throw new \InvalidArgumentException('each entitlement needs a unique code and a short string/int/bool value');
            }
            $seen[$e['code']] = true;
            $ents[]           = ['code' => $e['code'], 'value' => $e['value']];
        }

        $revision = $doc['revision'] ?? null;
        if (! is_int($revision) || $revision < 0) {
            throw new \InvalidArgumentException('revision must be a non-negative integer');
        }
        $issued = self::time($doc['issued_at'] ?? null, 'issued_at');
        if ($issued === null) {
            throw new \InvalidArgumentException('issued_at is required');
        }

        return new self($customer, $product, $subject, $licence, $plan, $planClass, $kind, $state, $from, $until, $ents, $revision, $issued);
    }

    /** The value of one named entitlement, or null. */
    public function entitlement(string $code): string|int|bool|null
    {
        foreach ($this->entitlements as $e) {
            if ($e['code'] === $code) {
                return $e['value'];
            }
        }

        return null;
    }

    private static function id(mixed $v, string $field, int $max): string
    {
        if (! is_string($v) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,' . ($max - 1) . '}$/', $v) !== 1) {
            throw new \InvalidArgumentException("{$field} must be 1-{$max} characters of [A-Za-z0-9._:-]");
        }

        return $v;
    }

    private static function time(mixed $v, string $field): ?int
    {
        if ($v === null) {
            return null;
        }
        if (! is_string($v) || preg_match(self::TIME, $v) !== 1 || ($t = strtotime($v)) === false) {
            throw new \InvalidArgumentException("{$field} must be an RFC 3339 UTC time like 2026-10-21T00:00:00Z");
        }

        return $t;
    }
}
