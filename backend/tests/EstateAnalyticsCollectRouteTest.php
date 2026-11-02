<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * The estate analytics collect route, and the two decisions in it that matter most for a SUPPORT
 * tool. Pinned in source because the route answers 204 on every path and the browser SDK swallows
 * every non-2xx, so neither decision can be checked by its response — and the same invisibility
 * already shipped a dead route in valuations-aicountly, green, recording nothing.
 *
 *   * STAFF. An AICOUNTLY support agent working in Remote is AICOUNTLY working, not a customer
 *     adopting Remote. remote_session.started marks those events 'staff' so reports exclude them —
 *     "otherwise Remote's adoption figures would be our own support desk" — and page views, which
 *     are mostly ours, must do the same. This is the only product in the estate where user_kind
 *     is genuinely variable rather than the constant 'customer'.
 *   * COMPANY. Remote takes a company from the verified launch context only; "the company comes
 *     from the signature, never from the client" (§6C, §12). A collect route that read one from
 *     the body would accept exactly what the product refuses.
 */
final class EstateAnalyticsCollectRouteTest extends TestCase
{
    private static function read(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        self::assertFileExists($path, $relative);

        return (string) file_get_contents($path);
    }

    /** The file's CODE, with comments stripped: the controller's comments name what it must not do. */
    private static function code(string $relative): string
    {
        $out = '';
        foreach (token_get_all(self::read($relative)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    public function testTheRouteRunsBehindBothTheAuthAndTheLaunchContextFilters(): void
    {
        $routes = self::read('backend/app/Config/Routes.php');

        $this->assertStringContainsString(
            "\$routes->post('analytics/collect', 'AnalyticsCollectController::collect', [",
            $routes,
        );
        // remote-context as well as api-auth: without the filter that verifies the launch
        // signature there is no verified company to record, and the route would silently
        // attribute every beacon to nobody.
        $block = substr($routes, (int) strpos($routes, "'analytics/collect'"), 220);
        $this->assertStringContainsString("'api-auth'", $block);
        $this->assertStringContainsString("'remote-context'", $block);

        // Inside the v1/remote group, so the served path is v1/remote/analytics/collect — which is
        // what the SPA posts to.
        $group = strpos($routes, "\$routes->group('v1/remote'");
        $this->assertNotFalse($group);
        $this->assertGreaterThan($group, strpos($routes, "'analytics/collect'"));
    }

    public function testTheSpaPostsToTheSamePathTheRouteServes(): void
    {
        $adapter = self::read('web/src/analytics/estateAnalytics.ts');

        $this->assertStringContainsString(
            '`${getApiBaseUrl()}/v1/remote/analytics/collect`',
            $adapter,
            'the group prefix is part of the path, and getting it wrong records nothing, silently',
        );
        $this->assertStringContainsString("app: 'remote'", $adapter);
    }

    public function testStaffIsDecidedFromTheIdentityAndNotFromTheBrowser(): void
    {
        $code = self::code('backend/app/Controllers/Api/V1/AnalyticsCollectController.php');

        $this->assertStringContainsString('$identity->isSupportAgent', $code);
        $this->assertStringContainsString('$identity->isPlatformAdmin', $code);
        $this->assertStringContainsString("'user_kind'  => \$isStaff ? 'staff' : 'customer'", $code);

        // And the browser must have no say in it: no user_kind is read from the payload.
        $this->assertStringNotContainsString("\$body['user_kind']", $code);

        $adapter = self::read('web/src/analytics/estateAnalytics.ts');
        $this->assertStringNotContainsString('user_kind', $adapter, 'the browser cannot label itself');
        $this->assertStringNotContainsString('isSupportAgent', $adapter, 'nor does it need to know');
    }

    public function testTheCompanyComesFromTheSignatureAndNeverFromTheClient(): void
    {
        $code = self::code('backend/app/Controllers/Api/V1/AnalyticsCollectController.php');

        $this->assertStringContainsString("\$this->context()->sourceContext()?->companyId", $code);
        foreach (["\$body['cmp_id']", "\$body['companyId']", "getGet('cmp_id')"] as $fromClient) {
            $this->assertStringNotContainsString($fromClient, $code, 'a company may not come from the client');
        }

        $adapter = self::read('web/src/analytics/estateAnalytics.ts');
        $this->assertStringNotContainsString('cmp_id', $adapter, 'so the SPA sends none');
    }

    public function testAGuestOrADeviceRecordsNothingRatherThanErroring(): void
    {
        $code = self::code('backend/app/Controllers/Api/V1/AnalyticsCollectController.php');

        // identity() throws for a guest, a device credential or no session; identityOrNull() is
        // what lets all three record nothing and still answer 204.
        $this->assertStringContainsString('identityOrNull()', $code);
        $this->assertStringNotContainsString('$this->identity()', $code);
    }

    public function testEveryPathAnswers204(): void
    {
        $code = self::code('backend/app/Controllers/Api/V1/AnalyticsCollectController.php');

        // noContent() is the only responder, and BaseApiController::noContent() is 204 + no-store.
        preg_match_all('/return \$this->(\w+)\(/', $code, $m);
        $this->assertNotEmpty($m[1]);
        $this->assertSame(['noContent'], array_values(array_unique($m[1])), 'the only answer this route gives');
    }
}
