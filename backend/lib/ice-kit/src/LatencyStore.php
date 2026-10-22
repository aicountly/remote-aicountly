<?php

declare(strict_types=1);

namespace Aicountly\Ice;

/**
 * Round-trip times clients have measured to each node, summarised per client region.
 *
 * This is how a first request from a region with no position data and no measurement of its own still
 * gets routed by evidence instead of a guess: other clients in that region have already timed the path.
 *
 * It is built to resist one client steering a whole region. Each client (identified only by a short hash,
 * never stored raw) keeps ONE sample per node — its latest — and the value used is the MEDIAN across
 * clients, counted only when at least `MIN_CLIENTS` distinct clients contributed and the newest sample is
 * no older than a week. Repeating a request changes nothing; poisoning needs more than half of the
 * contributing clients, who must each be authenticated. Buckets are the caller's to restrict (IceIssuer
 * accepts only regions it knows), bounded to `MAX_BUCKETS`, with `MAX_CLIENTS` samples each.
 */
final class LatencyStore
{
    public const MIN_CLIENTS = 5;
    public const MAX_CLIENTS = 32;
    public const MAX_BUCKETS = 32;
    public const MAX_AGE     = 7 * 86400;

    public function __construct(private readonly string $path)
    {
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @param array<string, int> $rtt       ms by node id, as ClientContext sanitised them
     * @param list<string>       $known     node ids that exist; others are ignored
     * @param string             $clientKey anything stable and opaque for this client (a subject); only a hash is kept
     */
    public function observe(string $region, array $rtt, array $known, int $now, string $clientKey = ''): void
    {
        $rtt = array_intersect_key($rtt, array_flip($known));
        if ($region === '' || $rtt === []) {
            return;
        }

        $client = substr(hash('sha256', $clientKey === '' ? bin2hex(random_bytes(8)) : $clientKey), 0, 12);

        $this->update(static function (array $data) use ($region, $rtt, $now, $client): array {
            foreach ($rtt as $node => $ms) {
                $samples = $data[$region][$node] ?? [];
                $samples[$client] = [(int) $ms, $now];
                uasort($samples, static fn (array $a, array $b): int => $b[1] <=> $a[1]);
                $data[$region][$node] = array_slice($samples, 0, self::MAX_CLIENTS, true);
            }

            if (count($data) > self::MAX_BUCKETS) {
                uasort($data, static fn (array $a, array $b): int => self::newest($b) <=> self::newest($a));
                $data = array_slice($data, 0, self::MAX_BUCKETS, true);
            }

            return $data;
        });
    }

    /** @return array<string, int> ms by node id, for nodes with samples from enough distinct clients */
    public function learned(string $region, int $now): array
    {
        $out = [];
        foreach ($this->read()[$region] ?? [] as $node => $samples) {
            $fresh = [];
            foreach ((array) $samples as $sample) {
                if (is_array($sample) && isset($sample[0], $sample[1]) && $now - (int) $sample[1] <= self::MAX_AGE) {
                    $fresh[] = (int) $sample[0];
                }
            }
            if (count($fresh) >= self::MIN_CLIENTS) {
                sort($fresh);
                $out[(string) $node] = $fresh[intdiv(count($fresh), 2)];
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function read(): array
    {
        $raw  = is_file($this->path) ? @file_get_contents($this->path) : false;
        $data = $raw === false || $raw === '' ? null : json_decode($raw, true);

        return is_array($data) && is_array($data['regions'] ?? null) ? $data['regions'] : [];
    }

    /** @param array<string, array<string, array<string, array{0: int, 1: int}>>> $region */
    private static function newest(array $region): int
    {
        $max = 0;
        foreach ($region as $samples) {
            foreach ((array) $samples as $sample) {
                $max = max($max, (int) ($sample[1] ?? 0));
            }
        }

        return $max;
    }

    /** @param \Closure(array<string, mixed>): array<string, mixed> $change */
    private function update(\Closure $change): void
    {
        $dir = dirname($this->path);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return;
        }

        $lock = @fopen($this->path . '.lock', 'c');
        if ($lock === false) {
            return;
        }
        if (! flock($lock, LOCK_EX)) {
            fclose($lock);

            return;
        }

        try {
            $tmp = $this->path . '.' . getmypid() . '.' . bin2hex(random_bytes(3)) . '.tmp';
            if (@file_put_contents($tmp, json_encode(['version' => 2, 'regions' => $change($this->read())], JSON_UNESCAPED_SLASHES)) === false || ! @rename($tmp, $this->path)) {
                @unlink($tmp);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
