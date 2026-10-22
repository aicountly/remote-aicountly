<?php

declare(strict_types=1);

namespace Aicountly\Ice;

/**
 * What each node is measurably carrying right now, as last reported by a collector running on (or
 * polling) the node: concurrent allocations and relayed bits per second.
 *
 * This is the "actual traffic" of the four things capacity is measured in (see Lease). It is optional
 * and advisory: admission works from the books alone when there is no fresh report, and a report only
 * ever makes admission stricter — traffic the books cannot explain is charged against both tiers.
 *
 * A report older than `maxAgeSeconds` is ignored (a dead collector must not freeze a node at its last
 * reading), and one with an implausible timestamp is refused. The file holds counters, never a credential.
 */
final class LoadStore implements LoadSource
{
    public const MAX_NODES = 8;

    public function __construct(private readonly string $path, public readonly int $maxAgeSeconds = 180)
    {
    }

    /** @param callable(string): (string|null) $read */
    public static function fromEnvironment(callable $read, string $defaultPath): self
    {
        $path = trim((string) ($read('WEBRTC_TURN_LOAD_FILE') ?? ''));

        return new self($path !== '' ? $path : $defaultPath, max(30, (int) ($read('WEBRTC_TURN_LOAD_MAX_AGE') ?: 180)));
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Accept one report: `{"node":"mum","at":<unix>,"allocations":120,"rxBps":9000000,"txBps":9100000}`,
     * optionally with `byTier` from a log-based attribution.
     *
     * @param array<string, mixed> $raw
     * @param list<string>|null    $known node ids that exist; a report for any other is refused, so junk ids can
     *                                    neither evict a real node's reading nor fill the file
     * @return array{ok: bool, error?: string}
     */
    public function ingest(array $raw, int $now, ?array $known = null): array
    {
        $parsed = LoadReport::parse($raw, $now, $known);
        if (! $parsed['ok']) {
            return ['ok' => false, 'error' => $parsed['error']];
        }

        $node  = $parsed['node'];
        $entry = $parsed['entry'];
        $this->update(static function (array $nodes) use ($node, $entry): array {
            $nodes[$node] = $entry;
            uasort($nodes, static fn (array $a, array $b): int => (int) ($b['at'] ?? 0) <=> (int) ($a['at'] ?? 0));

            return array_slice($nodes, 0, self::MAX_NODES, true);
        });

        return ['ok' => true];
    }

    /**
     * Fresh readings by node. `bps` is rx + tx — every client's upload and download through the node,
     * which is the same unit a lease's budget and a node's `maxBps` are in.
     *
     * @return array<string, array{allocations: int, bps: int, ageSeconds: int}>
     */
    public function fresh(int $now): array
    {
        $out = [];
        foreach ($this->read() as $id => $entry) {
            $age = $now - (int) ($entry['at'] ?? 0);
            if (is_array($entry) && $age <= $this->maxAgeSeconds) {
                $out[(string) $id] = [
                    'allocations' => (int) ($entry['allocations'] ?? 0),
                    'bps'         => (int) ($entry['rxBps'] ?? 0) + (int) ($entry['txBps'] ?? 0),
                    'ageSeconds'  => max(0, $age),
                ];
            }
        }

        return $out;
    }

    /** @return array<string, array<string, mixed>> */
    public function read(): array
    {
        $raw  = is_file($this->path) ? @file_get_contents($this->path) : false;
        $data = $raw === false || $raw === '' ? null : json_decode($raw, true);

        return is_array($data) && is_array($data['nodes'] ?? null) ? $data['nodes'] : [];
    }

    /** @param \Closure(array<string, array<string, mixed>>): array<string, array<string, mixed>> $change */
    private function update(\Closure $change): void
    {
        $dir = dirname($this->path);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException("Cannot create the TURN load directory {$dir}.");
        }

        $lock = @fopen($this->path . '.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException("Cannot open the TURN load lock {$this->path}.");
        }
        if (! flock($lock, LOCK_EX)) {
            fclose($lock);
            throw new \RuntimeException("Cannot lock the TURN load file {$this->path}.");
        }

        try {
            $nodes = $change($this->read());
            $tmp   = $this->path . '.' . getmypid() . '.' . bin2hex(random_bytes(3)) . '.tmp';
            if (@file_put_contents($tmp, json_encode(['version' => 1, 'nodes' => $nodes], JSON_UNESCAPED_SLASHES)) === false || ! @rename($tmp, $this->path)) {
                @unlink($tmp);
                throw new \RuntimeException("Cannot write the TURN load file {$this->path}.");
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
