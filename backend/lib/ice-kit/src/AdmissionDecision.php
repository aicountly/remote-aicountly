<?php

declare(strict_types=1);

namespace Aicountly\Ice;

/** The verdict on admitting one client of one tier to one node, and the load that produced it. */
final class AdmissionDecision
{
    public const OK              = 'ok';
    public const UNMETERED       = 'unmetered';
    public const FREE_POOL_FULL  = 'free_pool_full';
    public const NODE_OVERLOADED = 'node_overloaded';
    public const NODE_FULL       = 'node_full';
    public const TIER_NOT_SERVED = 'tier_not_served';

    /**
     * @param string                               $load        normal | busy | overloaded | full
     * @param float                                $utilization 0..1+, the busiest metered dimension
     * @param array{paid: array<string,int>, free: array<string,int>} $headroom what each tier could still be given
     */
    public function __construct(
        public readonly bool $admitted,
        public readonly string $reason,
        public readonly string $load,
        public readonly float $utilization,
        public readonly array $headroom = ['paid' => [], 'free' => []],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'admitted'    => $this->admitted,
            'reason'      => $this->reason,
            'load'        => $this->load,
            'utilization' => round($this->utilization, 3),
            'headroom'    => $this->headroom,
        ];
    }
}
