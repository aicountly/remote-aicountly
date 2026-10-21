<?php

declare(strict_types=1);

namespace Aicountly\Ice;

/**
 * The thresholds and per-session budgets admission uses, all from the environment so both products and
 * every node agree.
 *
 *   WEBRTC_TURN_ADMISSION             off | observe | enforce       default observe
 *   WEBRTC_TURN_PAID_RESERVE_PERCENT  50..90                         default 50 (the floor)
 *   WEBRTC_TURN_BUSY_AT               percent of capacity            default 70 — routing starts avoiding the node
 *   WEBRTC_TURN_SHED_FREE_AT          percent of capacity            default 85 — no new free sessions
 *   WEBRTC_TURN_BALANCE_BAND_MS       ms                             default 15 — nodes this close are balanced by load
 *   WEBRTC_TURN_DIRECT_CHARGE_PERCENT 0..100                         default 20 — what a client that REPORTS a direct
 *                                                                    connection is still charged (the report is unverifiable)
 *   WEBRTC_TURN_PROFILE_<NAME>_KBPS   kbit/s                         expected up+down through the relay per client
 *
 * `observe` computes every decision and records it but denies nothing — the way to learn real numbers in
 * staging before a limit can lock anyone out. `off` skips the books entirely.
 */
final class AdmissionPolicy
{
    public const MODES = ['off', 'observe', 'enforce'];

    /** Budgets are ASSUMPTIONS until measured on the real nodes (runbook part F); they are only as good as that. */
    public const DEFAULT_PROFILES_KBPS = [
        'default' => 2_000,   // one relayed participant
        'remote'  => 6_000,   // a Remote session: screen video down, input up, sometimes a file transfer
        'call'    => 3_000,   // a Connect 1:1 call, audio + video both ways
        'room'    => 3_000,   // one participant in a Connect meeting room
        'audio'   => 150,     // audio-only
    ];

    /**
     * @param array<string, int> $profilesBps expected relayed bits/s per client, by profile name
     */
    public function __construct(
        public readonly string $mode = 'observe',
        public readonly float $busyAt = 0.70,
        public readonly float $shedFreeAt = 0.85,
        public readonly int $balanceBandMs = 15,
        public readonly int $paidReservePercent = Capacity::MIN_RESERVE_PERCENT,
        private readonly array $profilesBps = [],
        public readonly int $directChargePercent = 20,
        /** The mode as written, when it was not one of MODES (a typo that fell back to `observe`). */
        public readonly string $invalidMode = '',
    ) {
    }

    /** @param callable(string): (string|null) $get */
    public static function fromEnvironment(callable $get): self
    {
        $value = static function (string $key) use ($get): ?string {
            $v = $get($key);

            return $v === null || $v === false || trim((string) $v) === '' ? null : trim((string) $v);
        };

        $written = strtolower($value('WEBRTC_TURN_ADMISSION') ?? 'observe');
        $mode    = in_array($written, self::MODES, true) ? $written : 'observe';

        $busy = max(10, min(95, (int) ($value('WEBRTC_TURN_BUSY_AT') ?? 70))) / 100;
        $shed = max(15, min(99, (int) ($value('WEBRTC_TURN_SHED_FREE_AT') ?? 85))) / 100;
        $shed = max($shed, $busy + 0.05);

        $profiles = [];
        foreach (self::DEFAULT_PROFILES_KBPS as $name => $kbps) {
            $override = $value('WEBRTC_TURN_PROFILE_' . strtoupper($name) . '_KBPS');
            $profiles[$name] = max(8, $override !== null ? (int) $override : $kbps) * 1000;
        }

        return new self(
            $mode,
            $busy,
            min(0.99, $shed),
            max(0, min(200, (int) ($value('WEBRTC_TURN_BALANCE_BAND_MS') ?? 15))),
            max(Capacity::MIN_RESERVE_PERCENT, min(Capacity::MAX_RESERVE_PERCENT, (int) ($value('WEBRTC_TURN_PAID_RESERVE_PERCENT') ?? Capacity::MIN_RESERVE_PERCENT))),
            $profiles,
            max(0, min(100, (int) ($value('WEBRTC_TURN_DIRECT_CHARGE_PERCENT') ?? 20))),
            $mode === $written ? '' : substr(preg_replace('/[^a-z0-9_-]/', '', $written) ?? '', 0, 20),
        );
    }

    /** What a client that says it is connected directly is still charged: the claim cannot be checked. */
    public function directBps(string $profile): int
    {
        return intdiv($this->bpsFor($profile) * $this->directChargePercent, 100);
    }

    /** Expected relayed bits/s for one client of this kind. An unknown name falls back to `default`. */
    public function bpsFor(string $profile): int
    {
        $profiles = $this->profilesBps !== [] ? $this->profilesBps : array_map(static fn (int $k): int => $k * 1000, self::DEFAULT_PROFILES_KBPS);

        return $profiles[$profile] ?? $profiles['default'] ?? 2_000_000;
    }

    public function enforcing(): bool
    {
        return $this->mode === 'enforce';
    }

    public function active(): bool
    {
        return $this->mode !== 'off';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'mode'               => $this->mode,
            'paidReservePercent' => $this->paidReservePercent,
            'busyAtPercent'      => (int) round($this->busyAt * 100),
            'shedFreeAtPercent'  => (int) round($this->shedFreeAt * 100),
            'balanceBandMs'      => $this->balanceBandMs,
            'directChargePercent' => $this->directChargePercent,
            'profilesKbps'       => array_map(static fn (int $b): int => intdiv($b, 1000), $this->profilesBps !== [] ? $this->profilesBps : array_map(static fn (int $k): int => $k * 1000, self::DEFAULT_PROFILES_KBPS)),
        ];
    }
}
