<?php

declare(strict_types=1);

namespace Aicountly\Ice;

/**
 * What the last probes said about each node, in one small JSON file.
 *
 * Written by `bin/probe.php --write-health` (a scheduled job), read on every credential request. A file
 * rather than a table because the API is stateless PHP on shared hosting and this is a few hundred bytes
 * that one cron job writes and every request reads.
 *
 * The rules are biased hard towards serving a relay:
 *
 *   * a node is only "down" after `failureThreshold` consecutive failed probes — one dropped packet is
 *     not an outage;
 *   * a result older than `maxAgeSeconds` is ignored — a dead probe job must not freeze a node out;
 *   * a missing or unreadable file means "no information", never "everything is down";
 *   * and IceIssuer never removes the last servable node on this file's word (it serves it, flagged
 *     `degraded`), because a relay that might work beats one that certainly is not offered.
 */
final class HealthStore
{
    public function __construct(
        private readonly string $path,
        public readonly int $maxAgeSeconds = 900,
        public readonly int $failureThreshold = 2,
    ) {
    }

    /**
     * @param callable(string): (string|null) $read
     */
    public static function fromEnvironment(callable $read, string $defaultPath): self
    {
        $path = trim((string) ($read('WEBRTC_TURN_HEALTH_FILE') ?? ''));

        return new self(
            $path !== '' ? $path : $defaultPath,
            max(60, (int) ($read('WEBRTC_TURN_HEALTH_MAX_AGE') ?: 900)),
            max(1, (int) ($read('WEBRTC_TURN_HEALTH_FAILURES') ?: 2)),
        );
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return array<string, array{ok: bool, checkedAt: int, consecutiveFailures: int, transports?: array<string, mixed>, error?: string|null}>
     */
    public function read(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        $raw = @file_get_contents($this->path);
        if ($raw === false || $raw === '') {
            return [];
        }

        $data = json_decode($raw, true);

        return is_array($data) && is_array($data['nodes'] ?? null) ? $data['nodes'] : [];
    }

    /** Whether recent, repeated probes say this node cannot be used. */
    public function isDown(string $nodeId, int $now): bool
    {
        return $this->state($nodeId, $now) === 'down';
    }

    /**
     * `up`, `degraded` (the last probe worked, but not on every transport), `failing` (a failed probe,
     * below the threshold), `down`, `stale` or `unknown`.
     */
    public function state(string $nodeId, int $now): string
    {
        return $this->states($now)[$nodeId] ?? 'unknown';
    }

    /**
     * Every recorded node's state from one read of the file — what a credential request uses, so it
     * costs one small file read however many nodes there are.
     *
     * @return array<string, string>
     */
    public function states(int $now): array
    {
        $states = [];

        foreach ($this->read() as $id => $entry) {
            if (! is_array($entry)) {
                continue;
            }
            if (! $this->isFresh($entry, $now)) {
                $states[(string) $id] = 'stale';

                continue;
            }

            $failures = (int) ($entry['consecutiveFailures'] ?? 0);

            $states[(string) $id] = match (true) {
                $failures >= $this->failureThreshold => 'down',
                $failures > 0                        => 'failing',
                self::hasFailedTransport($entry)     => 'degraded',
                default                              => 'up',
            };
        }

        return $states;
    }

    /**
     * What an operator means by a node's health, from a state token: `healthy`, `degraded`,
     * `unreachable` or `unknown`. `failing` is degraded — one missed probe is not yet an outage but is
     * already a reason to offer the standby beside it — and `stale` is unknown, because a probe job
     * that stopped running says nothing about the node.
     */
    public static function condition(string $state): string
    {
        return match ($state) {
            'up'                => 'healthy',
            'degraded', 'failing' => 'degraded',
            'down'              => 'unreachable',
            default             => 'unknown',
        };
    }

    /**
     * Whether the last probe worked overall but one of the node's transports did not. TLS on 443 is
     * the path restrictive networks depend on, so a node that has lost it while UDP still works is
     * not as good as one that has not — and a bare "ok" would hide that.
     *
     * @param array<string, mixed> $entry
     */
    private static function hasFailedTransport(array $entry): bool
    {
        foreach ((array) ($entry['transports'] ?? []) as $result) {
            if (! is_array($result)) {
                continue;
            }

            // An allocation is the stronger statement; fall back to the binding when none was tried.
            if (($result['allocation'] ?? $result['binding'] ?? null) === false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fold one probe run into the file. Nodes not in this run keep their previous entry.
     *
     * @param array<string, array{ok: bool, transports?: array<string, mixed>, error?: string|null}> $results by node id
     */
    public function record(array $results, int $now): void
    {
        $nodes = $this->read();

        foreach ($results as $id => $result) {
            $previous = (int) ($nodes[$id]['consecutiveFailures'] ?? 0);
            $ok       = (bool) ($result['ok'] ?? false);

            $nodes[$id] = [
                'ok'                  => $ok,
                'checkedAt'           => $now,
                'consecutiveFailures' => $ok ? 0 : $previous + 1,
                'transports'          => $result['transports'] ?? [],
                'error'               => $result['error'] ?? null,
            ];
        }

        $dir = dirname($this->path);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException("Cannot create the TURN health directory {$dir}.");
        }

        // Write-then-rename, so a request never reads half a file.
        $tmp = $this->path . '.' . getmypid() . '.tmp';
        $json = json_encode(['version' => 1, 'updatedAt' => $now, 'nodes' => $nodes], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (@file_put_contents($tmp, $json) === false || ! @rename($tmp, $this->path)) {
            @unlink($tmp);

            throw new \RuntimeException("Cannot write the TURN health file {$this->path}.");
        }
    }

    /** @param array<string, mixed> $entry */
    private function isFresh(array $entry, int $now): bool
    {
        return ($now - (int) ($entry['checkedAt'] ?? 0)) <= $this->maxAgeSeconds;
    }
}
