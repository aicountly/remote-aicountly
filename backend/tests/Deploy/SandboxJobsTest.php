<?php

declare(strict_types=1);

namespace Tests\Deploy;

use PHPUnit\Framework\TestCase;

/**
 * The sandbox deploy installs bin/ops/jobs.sandbox.txt: the TURN health probe and nothing else. The production list's
 * company-lifecycle jobs act on data (purges, archive drops) and must never be scheduled on the sandbox by accident.
 *
 * @internal
 */
final class SandboxJobsTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** @return list<string> job lines (name | schedule | command) */
    private static function jobs(string $file): array
    {
        $lines = file(self::ROOT . '/bin/ops/' . $file, FILE_IGNORE_NEW_LINES) ?: [];

        return array_values(array_filter($lines, static fn (string $l): bool => trim($l) !== '' && $l[0] !== '#'));
    }

    public function testTheSandboxListHoldsOnlyTheTurnProbe(): void
    {
        $jobs = self::jobs('jobs.sandbox.txt');

        $this->assertCount(1, $jobs);
        $this->assertStringStartsWith('turn-probe ', $jobs[0]);
        $this->assertStringContainsString('lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php --allocate --write-health', $jobs[0]);
        $this->assertStringNotContainsString('--monitor', $jobs[0], 'the sandbox must not report to Console\'s Cron Job Monitor');

        $text = (string) file_get_contents(self::ROOT . '/bin/ops/jobs.sandbox.txt');
        $this->assertStringContainsString('#! app: remote', $text);
        $this->assertStringNotContainsString('company-lifecycle', implode("\n", $jobs));
        $this->assertStringNotContainsString('legacy-pattern', $text, 'the sandbox install must not remove any other crontab line');
    }

    public function testTheProbeLineMatchesProduction(): void
    {
        $prod = array_values(array_filter(self::jobs('jobs.txt'), static fn (string $l): bool => str_starts_with($l, 'turn-probe ')));
        $this->assertSame($prod, self::jobs('jobs.sandbox.txt'));
    }

    public function testTheSandboxWorkflowInstallsTheSandboxListOnly(): void
    {
        $wf   = (string) file_get_contents(self::ROOT . '/../.github/workflows/deploy-sandbox.yml');
        $step = substr($wf, (int) strpos($wf, '- name: Scheduled jobs (TURN health probe only)'));
        $step = substr($step, 0, (int) strpos($step, "\n      - name:", 10));

        $this->assertStringContainsString('JOBS_FILE=bin/ops/jobs.sandbox.txt bash bin/ops/install-jobs.sh --apply', $step);
        $this->assertStringContainsString('JOBS_FILE=bin/ops/jobs.sandbox.txt bash bin/ops/install-jobs.sh --check', $step);
        $this->assertStringNotContainsString('post-deploy-jobs.sh', $step);
        $this->assertStringNotContainsString('CONSOLE_CRON_MONITOR_KEY', $step);
    }
}
