<?php

declare(strict_types=1);

namespace Tests\Deploy;

use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

/**
 * What a deploy puts under <document root>/api stays private.
 *
 * `backend/` is rsynced into `api/` inside the site's document root, so every
 * real file in it is one request from being served — the framework's `spark`
 * CLI, the dependency manifests, the application code, the logs. The rules in
 * `backend/.htaccess` are what stands in front of them; with no Apache here,
 * they are read from the file and evaluated against real paths, the way the
 * other products' exposure tests do, and against Remote's own route table so a
 * refusal can never swallow an endpoint.
 *
 * @internal
 */
final class ApiHtaccessTest extends CIUnitTestCase
{
    /** Where the API's .htaccess lives, and so what `api/` holds on the server. */
    private const API = __DIR__ . '/../..';

    /** Under `api/`, as a URL path below it. All of these are real files or directories on the host. */
    private const PRIVATE = [
        'spark',
        'spark/x',
        'composer.json',
        'composer.lock',
        'phpunit.dist.xml',
        'app',
        'app/Config/Routes.php',
        'app/Config/.env',
        'system/Boot.php',
        'vendor/autoload.php',
        'vendor/composer/installed.json',
        'writable/logs/log-2026-10-05.log',
        'writable/session/ci_session_x',
        'tests/README.md',
        'tests/Feature/AccessHardeningApiTest.php',
        'database/seeds/Seeder.php',
        'scripts/x.php',
        'build/logs/clover.xml',
        'deploy/vendor.htaccess',
        '.env',
        '.env.example',
        '.gitignore',
        '.htaccess',
        '.git/HEAD',
        'public/.env',
        'error_log',
        'public/error_log',
        'README.md',
    ];

    /**
     * Must keep answering: the front controller, its static files, ACME
     * validation, and names that only *start* like a private one (the rules
     * are anchored, not prefixes).
     */
    private const PUBLIC = [
        'public/index.php',
        'public/favicon.ico',
        'public/robots.txt',
        '.well-known/acme-challenge/token',
        'public/.well-known/acme-challenge/token',
        'health',
        'application/x',
        'sparkle',
        'testsuite',
        'builder/x',
        'databases',
        'composer.json.php',
    ];

    private string $htaccess;

    protected function setUp(): void
    {
        parent::setUp();

        $this->htaccess = (string) file_get_contents(self::API . '/.htaccess');
        $this->assertNotSame('', $this->htaccess, 'backend/.htaccess is readable');
    }

    public function testEveryPrivatePathIsRefusedAndTheFrontControllerStillAnswers(): void
    {
        foreach (self::PRIVATE as $path) {
            $this->assertTrue($this->refused($path), "api/{$path} is refused by backend/.htaccess");
        }
        foreach (self::PUBLIC as $path) {
            $this->assertFalse($this->refused($path), "api/{$path} must still be reachable");
        }
    }

    public function testNoEndpointOfTheApiIsRefused(): void
    {
        $routes = Services::routes(false);
        $routes->loadRoutes();

        $paths = [];
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $verb) {
            foreach (array_keys($routes->getRoutes($verb)) as $route) {
                // (:segment), (:num), (:any) … stand for whatever the caller sends.
                $paths[] = ltrim((string) preg_replace('/\([^)]*\)/', 'x', (string) $route), '/');
            }
        }
        $paths = array_values(array_unique($paths));

        $this->assertGreaterThan(40, count($paths), 'the route table was loaded');
        $this->assertContains('v1/remote/sessions/x/invitations', $paths);
        $this->assertContains('health', $paths);

        foreach ($paths as $path) {
            $this->assertFalse($this->refused($path), "the endpoint api/{$path} must not be refused");
        }
    }

    /**
     * The one that keeps this honest as the tree grows: whatever sits at the top
     * of backend/ is either refused or is `public/`. A new top-level directory or
     * file that nobody thought about fails here instead of going out on the
     * next deploy.
     */
    public function testEveryTopLevelEntryOfTheApiIsRefusedOrIsPublic(): void
    {
        $entries = array_values(array_diff(scandir(self::API) ?: [], ['.', '..', 'public']));

        $this->assertContains('spark', $entries, 'the entry the live scan found is among those checked');
        $this->assertContains('composer.json', $entries);
        $this->assertContains('app', $entries);
        $this->assertContains('writable', $entries);

        foreach ($entries as $entry) {
            $this->assertTrue(
                $this->refused($entry),
                "backend/{$entry} is deployed to api/{$entry} and is not refused: add it to the rules in backend/.htaccess, or move it under public/ if it is meant to be served",
            );
        }
    }

    public function testTheRefusalsComeBeforeTheRewriteIntoPublic(): void
    {
        $refusals = $this->positions('/^\s*RewriteRule\s+\S+\s+-\s+\[[^\]]*\bF\b[^\]]*\]/m');
        $inside   = $this->positions('/^\s*RewriteRule\s+\^public\/\s+-\s+\[L\]/m');
        $rewrite  = $this->positions('/^\s*RewriteRule\s+\^\(\.\*\)\$\s+public\/\$1\s+\[L\]/m');

        $this->assertCount(1, $inside, 'requests already inside public/ are left to its own rules');
        $this->assertCount(1, $rewrite, 'everything else is still rewritten into public/');
        $this->assertGreaterThanOrEqual(2, count($refusals));
        $this->assertLessThan($inside[0], max($refusals), 'every refusal is evaluated before the request is let through or rewritten');
        $this->assertLessThan($rewrite[0], $inside[0]);

        // The Authorization hand-off and the no-mod_rewrite fallback are untouched.
        $this->assertStringContainsString('RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]', $this->htaccess);
        $this->assertMatchesRegularExpression('/<IfModule !mod_rewrite\.c>\s*Require all denied\s*<\/IfModule>/', $this->htaccess);
    }

    public function testTheLiveDeployCheckProbesTheCliEntryPoint(): void
    {
        $checks = (string) file_get_contents(self::API . '/../scripts/ci/post-deploy-checks.sh');

        $this->assertSame(
            1,
            preg_match('/for path in([^;]*);\s*do/', $checks, $m),
            'the private-path probe loop was found',
        );
        $this->assertContains('/api/spark', preg_split('/[\s\\\\]+/', trim($m[1])) ?: [], 'a deploy proves /api/spark is not served');
    }

    // ------------------------------------------------------------ the rules

    /** Whether any rule in backend/.htaccess refuses `api/<path>`. */
    private function refused(string $path): bool
    {
        // mod_rewrite in .htaccess sees the path below the directory, and a rule
        // that rewrites to "-" with F, G or R=404 is a refusal. Conditions are
        // ignored, which is right for these paths: all are real files on the host.
        foreach (preg_split('/\R/', $this->htaccess) ?: [] as $line) {
            if (preg_match('/^\s*RewriteRule\s+(\S+)\s+-\s+\[([^\]]*)\]/', $line, $m) !== 1) {
                continue;
            }
            if (preg_match('/(^|,)(F|G|R=404)(,|$)/', $m[2]) === 1 && $this->hits($m[1], $path)) {
                return true;
            }
        }

        // mod_alias matches the whole URL path, which here starts at /api/.
        foreach (preg_split('/\R/', $this->htaccess) ?: [] as $line) {
            if (preg_match('/^\s*RedirectMatch\s+(?:404|410)\s+(\S+)/', $line, $m) === 1 && $this->hits($m[1], '/api/' . $path)) {
                return true;
            }
        }

        // <FilesMatch> blocks that say "Require all denied" test the file name.
        if (preg_match_all('/<FilesMatch\s+"([^"]+)">(.*?)<\/FilesMatch>/s', $this->htaccess, $blocks, PREG_SET_ORDER) > 0) {
            foreach ($blocks as $block) {
                if (preg_match('/^\s*Require\s+all\s+denied\s*$/m', $block[2]) === 1 && $this->hits($block[1], basename($path))) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hits(string $pattern, string $subject): bool
    {
        return preg_match('#' . str_replace('#', '\#', $pattern) . '#', $subject) === 1;
    }

    /** @return list<int> byte offsets of every match, in file order */
    private function positions(string $regex): array
    {
        preg_match_all($regex, $this->htaccess, $m, PREG_OFFSET_CAPTURE);

        return array_map(static fn (array $hit): int => $hit[1], $m[0]);
    }
}
