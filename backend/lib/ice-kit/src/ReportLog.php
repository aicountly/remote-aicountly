<?php

declare(strict_types=1);

namespace Aicountly\Ice;

/**
 * Where connectivity reports are kept, independently of the application's log level.
 *
 * The products used to write each report only through the framework logger at `info`, and production runs that logger
 * at `error` (threshold 4), so every report was dropped: the evidence `summarize-reports.php` reads never existed where
 * it mattered most. A report is already reduced by ConnectivityReport::sanitize() to enumerations, node ids and counts,
 * so it is safe to keep on its own.
 *
 * One file per UTC day, `<dir>/ice-reports-YYYY-MM-DD.log`, one line per report in the format ReportSummary reads:
 *
 *   2026-10-24T16:20:01Z remote.ice.report {"session":"…","outcome":"recovered",…}
 *
 * Files older than the retention are removed now and then, so the directory cannot grow without bound. Writing never
 * throws: a report is diagnostics, and a full disk must not turn into a failed API call.
 */
final class ReportLog
{
    public const RETENTION_DAYS = 30;

    /** One write in this many also prunes expired files. */
    private const PRUNE_ONE_IN = 50;

    /**
     * @param array<string, mixed> $report the sanitised report, plus the product's own context keys
     */
    public static function append(string $dir, string $product, array $report, ?int $now = null): bool
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $product) !== 1) {
            return false;
        }

        $now ??= time();
        $dir = rtrim($dir, '/');

        if (! is_dir($dir) && ! @mkdir($dir, 0750, true) && ! is_dir($dir)) {
            return false;
        }

        $json = json_encode($report, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        $line = gmdate('Y-m-d\TH:i:s\Z', $now) . ' ' . $product . '.ice.report ' . $json . "\n";
        $file = $dir . '/' . self::fileName($now);
        $new  = ! is_file($file);

        if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
            return false;
        }
        if ($new) {
            @chmod($file, 0640);
        }

        if (random_int(1, self::PRUNE_ONE_IN) === 1) {
            self::prune($dir, $now);
        }

        return true;
    }

    public static function fileName(int $time): string
    {
        return 'ice-reports-' . gmdate('Y-m-d', $time) . '.log';
    }

    /** Remove report files older than the retention. Returns how many were removed. */
    public static function prune(string $dir, ?int $now = null, int $retentionDays = self::RETENTION_DAYS): int
    {
        $cutoff  = gmdate('Y-m-d', ($now ?? time()) - $retentionDays * 86400);
        $removed = 0;

        foreach (glob(rtrim($dir, '/') . '/ice-reports-*.log') ?: [] as $path) {
            if (preg_match('/ice-reports-(\d{4}-\d{2}-\d{2})\.log$/', $path, $m) === 1 && $m[1] < $cutoff && @unlink($path)) {
                $removed++;
            }
        }

        return $removed;
    }
}
