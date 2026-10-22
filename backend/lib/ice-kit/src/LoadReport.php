<?php

declare(strict_types=1);

namespace Aicountly\Ice;

/** Validation of one node load report — shared by every LoadSource so they accept exactly the same things. */
final class LoadReport
{
    /**
     * @param array<string, mixed> $raw
     * @param list<string>|null    $known
     * @return array{ok: true, node: string, entry: array<string, mixed>}|array{ok: false, error: string}
     */
    public static function parse(array $raw, int $now, ?array $known = null): array
    {
        $node = $raw['node'] ?? null;
        if (! is_string($node) || preg_match('/^[a-z0-9][a-z0-9-]{0,31}\z/', $node) !== 1 || ($known !== null && ! in_array($node, $known, true))) {
            return ['ok' => false, 'error' => 'bad_node'];
        }

        $at = $raw['at'] ?? null;
        if (! is_int($at) && ! (is_string($at) && ctype_digit($at))) {
            return ['ok' => false, 'error' => 'bad_timestamp'];
        }
        $at = (int) $at;
        if (abs($now - $at) > 300) {
            return ['ok' => false, 'error' => 'timestamp_out_of_range'];
        }

        foreach (['allocations', 'rxBps', 'txBps'] as $field) {
            if (! isset($raw[$field]) || ! is_numeric($raw[$field]) || $raw[$field] < 0 || $raw[$field] > 1e13) {
                return ['ok' => false, 'error' => 'bad_' . $field];
            }
        }

        $entry = ['at' => $at, 'allocations' => (int) $raw['allocations'], 'rxBps' => (int) $raw['rxBps'], 'txBps' => (int) $raw['txBps']];

        foreach (['paid', 'free'] as $tier) {
            if (isset($raw['byTier'][$tier]['bps']) && is_numeric($raw['byTier'][$tier]['bps'])) {
                $entry['byTier'][$tier] = ['bps' => max(0, (int) $raw['byTier'][$tier]['bps'])];
            }
        }

        return ['ok' => true, 'node' => $node, 'entry' => $entry];
    }
}
