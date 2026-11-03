<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * The estate analytics collect route, and the two decisions in it that matter most. Pinned in
 * source because the route answers 204 on every path and the browser SDK swallows every non-2xx,
 * so neither decision can be checked by its response — and the same invisibility already shipped
 * a dead route in valuations-aicountly, green, recording nothing.
 *
 *   * EVERY SUBJECT IS A CUSTOMER. Remote is still used as a support tool, but estate analytics
 *     draws no staff/customer line: every end user, and every user, is a customer. user_kind is
 *     the constant 'customer', and the identity's isSupportAgent / isPlatformAdmin flags are not
 *     consulted for analytics at all. (This reverses an earlier staff rule that marked a support
 *     agent's events 'staff' so adoption reporting excluded them; a regression would reintroduce
 *     it invisibly, which is what these assertions are for.)
 *   * NO COMPANY IS RECORDED, from any source. Organisations have been wiped from Remote, so
 *     attribution is always user-wise: not from the body, not from a query, and not from the
 *     verified launch context either — the route no longer reads one.
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

    public function testTheRouteRunsBehindTheAuthFilterAndNoLaunchContextFilter(): void
    {
        $routes = self::read('backend/app/Config/Routes.php');

        $this->assertStringContainsString(
            "\$routes->post('analytics/collect', 'AnalyticsCollectController::collect', [",
            $routes,
        );
        $block = substr($routes, (int) strpos($routes, "'analytics/collect'"), 220);
        $this->assertStringContainsString("'api-auth'", $block);
        // And NOT remote-context. It was there only so the controller could read a company from
        // the verified launch context, and no company is recorded any more. It is also the only
        // thing that could make this route answer something other than 204 — SourceContextFilter
        // fails a malformed or replayed X-Remote-Context — which a beacon must never see.
        $this->assertStringNotContainsString("'remote-context'", $block);

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

    public function testEverySubjectIsRecordedAsACustomerAndNothingIsMarkedStaff(): void
    {
        $code = self::code('backend/app/Controllers/Api/V1/AnalyticsCollectController.php');

        $this->assertStringContainsString("'user_kind'      => 'customer'", $code);

        // No staff rule survives anywhere in the controller's code: not the literal, not the
        // identity flags it used to be computed from, not the variable it was held in.
        $this->assertStringNotContainsStringIgnoringCase('staff', $code, 'no event is marked staff');
        $this->assertStringNotContainsString('isSupportAgent', $code, 'not consulted for analytics');
        $this->assertStringNotContainsString('isPlatformAdmin', $code, 'nor this');

        // And the browser has no say either way: it sends no user_kind and knows nothing of the
        // flags. trackMany() strips user_kind from every event in the batch regardless.
        $this->assertStringNotContainsString("\$body['user_kind']", $code);

        $adapter = self::read('web/src/analytics/estateAnalytics.ts');
        $this->assertStringNotContainsString('user_kind:', $adapter, 'the browser cannot label itself');
        $this->assertStringNotContainsString('isSupportAgent', $adapter, 'nor does it need to know');
    }

    public function testNoCompanyIsRecordedFromAnySource(): void
    {
        $code = self::code('backend/app/Controllers/Api/V1/AnalyticsCollectController.php');

        // Not from the launch context: the route no longer reads one, which is also why
        // remote-context came off its filter list.
        $this->assertStringNotContainsString('sourceContext()', $code);
        // Not under any name at all — company_id is simply never stated here.
        $this->assertStringNotContainsStringIgnoringCase('company', $code, 'attribution is user-wise');

        // Nor from the client, which is where it would creep back in.
        foreach (["\$body['cmp_id']", "\$body['companyId']", "getGet('cmp_id')"] as $fromClient) {
            $this->assertStringNotContainsString($fromClient, $code, 'a company may not come from the client');
        }

        $adapter = self::read('web/src/analytics/estateAnalytics.ts');
        $this->assertStringNotContainsString('cmp_id', $adapter, 'so the SPA sends none');
    }

    /**
     * The session event makes the same two decisions, and for the same reason. Kept here because
     * the two call sites are the whole of Remote's estate analytics: one of them drifting back to
     * a staff rule or a company id would be as invisible as it would be in the collect route.
     */
    public function testTheSessionStartedEventMakesTheSameTwoDecisions(): void
    {
        $code = self::code('backend/app/Controllers/Api/V1/SessionController.php');

        $this->assertStringNotContainsString('$isStaff', $code, 'the staff rule is gone from here too');

        $this->assertSame(
            1,
            preg_match(
                '/EstateAnalytics::track\(\'remote_session\.started\',\s*\[(.*?)\]\);/s',
                $code,
                $m,
            ),
            'the session-started event should still be recorded',
        );
        $statement = $m[1];

        $this->assertStringContainsString("'user_kind'  => 'customer'", $statement);
        $this->assertStringNotContainsStringIgnoringCase('staff', $statement);
        // The session itself still resolves a company — it is scoped to one — but the event is
        // attributed to the subject alone.
        $this->assertStringNotContainsStringIgnoringCase('company', $statement, 'attribution is user-wise');
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
