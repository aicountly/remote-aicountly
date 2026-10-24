<?php

declare(strict_types=1);

namespace Tests\Deploy;

use PHPUnit\Framework\TestCase;

/**
 * Every Remote deploy ships the signalling code and restarts the service (scripts/ci/deploy-signalling.sh), into the
 * directory remote-signalling-setup.sh installed it in, and before the live checks ask /signal/health. Without it the
 * web app and API move on while the signalling service stays on whatever code it was set up with.
 *
 * @internal
 */
final class DeploySignallingTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    /** @return iterable<string, array{string, string}> */
    public static function deploys(): iterable
    {
        yield 'production' => ['deploy-production.yml', 'remote-signalling'];
        yield 'sandbox'    => ['deploy-sandbox.yml', 'remote-signalling-sandbox'];
    }

    /**
     * @dataProvider deploys
     */
    public function testTheDeployShipsTheSignallingCodeBeforeTheLiveChecks(string $workflow, string $appDir): void
    {
        $yaml = (string) file_get_contents(self::ROOT . '/.github/workflows/' . $workflow);

        $step   = strpos($yaml, "run: bash scripts/ci/deploy-signalling.sh {$appDir}\n");
        $verify = strpos($yaml, '- name: Verify the live app');

        $this->assertNotFalse($step, "{$workflow} must ship the signalling code to ~/{$appDir}");
        $this->assertNotFalse($verify);
        $this->assertLessThan($verify, $step, 'before the live checks, so they see the new code');
    }

    public function testTheSetupScriptAndTheDeployAgreeOnTheDirectories(): void
    {
        $setup = (string) file_get_contents(self::ROOT . '/scripts/ops/remote-signalling-setup.sh');

        $this->assertStringContainsString('APPDIR=/home/remoteaicountly/remote-signalling;', $setup);
        $this->assertStringContainsString('APPDIR=/home/ghaicountly/remote-signalling-sandbox;', $setup);
        $this->assertStringContainsString('PathModified=$APPDIR/restart.txt', $setup, 'the deploy restarts the service by writing restart.txt');
    }

    public function testTheDeployKeepsTheInstalledDependenciesAndTheRestartMarker(): void
    {
        $script = (string) file_get_contents(self::ROOT . '/scripts/ci/deploy-signalling.sh');

        $this->assertStringContainsString("--exclude='node_modules/'", $script);
        $this->assertStringContainsString("--exclude='restart.txt'", $script);
        $this->assertStringContainsString('npm ci --omit=dev', $script);
        $this->assertStringContainsString('> restart.txt', $script);
        $this->assertStringContainsString('/opt/cpanel/ea-nodejs22/bin', $script, 'Node 22 beside the older system Node');
    }
}
