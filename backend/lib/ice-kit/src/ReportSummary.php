<?php

declare(strict_types=1);

namespace Aicountly\Ice;

/**
 * What the connectivity reports in a log say, in aggregate: how many connections, how many relayed,
 * through which node and transport, how long they took, and why the ones that failed failed.
 *
 * This is the evidence for a staging test or a launch: "every session in this window that needed a
 * relay got one, from this node, over this transport". It reads the one-line records the products write
 * (`remote.ice.report {…}`, `connect.ice.report {…}`) — already reduced by ConnectivityReport to
 * enumerations and counts — so there is no address, SDP or credential in what it reads or prints.
 */
final class ReportSummary
{
    /** @var list<array<string, mixed>> */
    private array $reports = [];

    /**
     * Take the reports out of log text. Lines that are not reports, or whose JSON does not parse, are
     * counted in `skipped` rather than trusted.
     */
    public function addLog(string $text): int
    {
        $added = 0;
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (preg_match('/\b(remote|connect)\.ice\.report (\{.*\})\s*$/', $line, $m) !== 1) {
                continue;
            }

            $data = json_decode($m[2], true);
            if (! is_array($data) || ! isset($data['outcome']) && ! isset($data['path'])) {
                continue;
            }

            $data['product'] = $m[1];
            $this->reports[] = $data;
            $added++;
        }

        return $added;
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $total      = count($this->reports);
        $outcomes   = [];
        $paths      = [];
        $relay      = [];
        $errors     = [];
        $products   = [];
        $times      = ['relay' => [], 'direct' => []];
        $restarts   = 0;
        $renewals   = 0;

        foreach ($this->reports as $r) {
            $products[(string) $r['product']] = ($products[(string) $r['product']] ?? 0) + 1;
            $outcomes[(string) ($r['outcome'] ?? 'unknown')] = ($outcomes[(string) ($r['outcome'] ?? 'unknown')] ?? 0) + 1;
            $path = (string) ($r['path'] ?? 'unknown');
            $paths[$path] = ($paths[$path] ?? 0) + 1;

            if ($path === 'relay') {
                $key = ($r['relayNode'] ?? '?') . '/' . ($r['relayProtocol'] ?? '?');
                $relay[$key] = ($relay[$key] ?? 0) + 1;
            }

            foreach ((array) ($r['iceErrors'] ?? []) as $e) {
                $key = ($e['category'] ?? '?') . ($e['node'] ? '@' . $e['node'] : '');
                $errors[$key] = ($errors[$key] ?? 0) + 1;
            }

            if (isset($r['timeToConnectMs']) && in_array($path, ['relay', 'direct'], true)) {
                $times[$path][] = (int) $r['timeToConnectMs'];
            }
            $restarts += (int) ($r['restarts'] ?? 0);
            $renewals += (int) ($r['credentialRefreshes'] ?? 0);
        }

        // Stable regardless of the order the log happened to hold them in, so two summaries diff cleanly.
        ksort($products);
        ksort($outcomes);
        ksort($paths);
        arsort($relay);
        arsort($errors);

        return [
            'reports'             => $total,
            'products'            => $products,
            'outcomes'            => $outcomes,
            'paths'               => $paths,
            'relayedShare'        => $total > 0 ? round(($paths['relay'] ?? 0) / $total, 3) : null,
            'relayByNodeProtocol' => $relay,
            'iceErrors'           => $errors,
            'timeToConnectMs'     => [
                'relay'  => self::percentiles($times['relay']),
                'direct' => self::percentiles($times['direct']),
            ],
            'iceRestarts'         => $restarts,
            'credentialRenewals'  => $renewals,
            'failed'              => $outcomes['failed'] ?? 0,
        ];
    }

    /**
     * @param list<int> $values
     * @return array{n: int, p50: int|null, p95: int|null}
     */
    public static function percentiles(array $values): array
    {
        sort($values);
        $n = count($values);
        if ($n === 0) {
            return ['n' => 0, 'p50' => null, 'p95' => null];
        }

        // Nearest-rank, which never invents a value that was not measured.
        $at = static fn (float $p): int => $values[max(0, (int) ceil($p * $n) - 1)];

        return ['n' => $n, 'p50' => $at(0.50), 'p95' => $at(0.95)];
    }
}
