<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\DeviceApiRequests;
use Tests\Support\RemoteTestCase;

/**
 * Whose budget a rate limit is.
 *
 * Every request in this suite arrives from one address, as every request from
 * one office does. The limiter used to count them all against that address:
 * four people being helped at once in one office used up the help-session
 * budget for the fifth, and one helper typing join codes could have the next
 * helper refused. A limit follows whoever an earlier filter authenticated — a
 * computer, a person, a guest — and only a call nobody has authenticated yet is
 * counted against where it came from.
 *
 * Through the real routes, filters and throttler.
 *
 * @internal
 */
final class RateLimitTest extends RemoteTestCase
{
    use DeviceApiRequests;
    use FeatureTestTrait;

    /**
     * The throttler gives tokens back as time passes — two a second on a budget of 120 a minute — so
     * on a busy machine a loop of calls can be handed tokens faster than it uses them, and "the 121st
     * is refused" is then a statement about how fast this machine is. The clock is held still: what
     * is under test is whose budget a call is counted against.
     */
    protected function setUp(): void
    {
        parent::setUp();

        service('throttler')->setTestTime(time());
    }

    protected function tearDown(): void
    {
        // A throttler with a stopped clock must not be the one the next test class is given.
        \Config\Services::resetSingle('throttler');

        parent::tearDown();
    }

    public function testEachComputerHasItsOwnBudgetBehindOneAddress(): void
    {
        $owner = $this->makeIdentity('Office Owner');

        $frontDesk  = $this->asDevice($this->enrolDevice($owner, null, ['deviceName' => 'Front desk', 'hostname' => 'FRONT-DESK']));
        $backOffice = $this->asDevice($this->enrolDevice($owner, null, ['deviceName' => 'Back office', 'hostname' => 'BACK-OFFICE']));

        // `device-presence-token` is thirty a minute.
        for ($call = 1; $call <= 30; $call++) {
            $this->assertSame(200, $this->postJson($frontDesk, 'devices/me/presence-token')->response()->getStatusCode(), "Call {$call} is within the budget.");
        }

        $refused = $this->postJson($frontDesk, 'devices/me/presence-token');
        $this->assertSame(429, $refused->response()->getStatusCode(), 'The thirty-first is over it.');
        $this->assertSame('RATE_LIMITED', json_decode($refused->getJSON(), true)['error']['code']);
        $this->assertNotSame('', $refused->response()->getHeaderLine('Retry-After'));

        $this->assertSame(
            200,
            $this->postJson($backOffice, 'devices/me/presence-token')->response()->getStatusCode(),
            'The computer beside it, behind the same address, has used none of its own.',
        );
    }

    public function testEachPersonHasTheirOwnBudgetBehindOneAddress(): void
    {
        $first  = $this->asPerson($this->makeIdentity('First Helper'));
        $second = $this->asPerson($this->makeIdentity('Second Helper'));

        // `join-code` is ten a minute — the limit that matters most, since nine digits can be walked.
        // A code nobody issued is refused as not found; it is still an attempt, and still counted.
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->assertNotSame(
                429,
                $this->postJson($first, 'join/code', ['code' => sprintf('%09d', 100000000 + $attempt)])->response()->getStatusCode(),
                "Attempt {$attempt} is within the budget.",
            );
        }

        $this->assertSame(
            429,
            $this->postJson($first, 'join/code', ['code' => '100000011'])->response()->getStatusCode(),
            'The eleventh guess by one person is refused.',
        );

        $this->assertNotSame(
            429,
            $this->postJson($second, 'join/code', ['code' => '100000012'])->response()->getStatusCode(),
            'A colleague at the next desk is not refused for it.',
        );
    }

    /**
     * Nobody has been authenticated when a computer asks for a challenge — the signature that follows *is* the
     * authentication — so there is nothing to count it against but the address, and naming a different computer
     * each time must not buy a fresh budget.
     */
    public function testAnUnauthenticatedCallIsStillCountedAgainstItsAddressWhoeverItNames(): void
    {
        $budget = 120;   // `device-challenge`: an office of sixty computers starting together, twice each.

        for ($call = 1; $call <= $budget; $call++) {
            $this->assertNotSame(
                429,
                $this->postJson([], 'devices/auth/challenge', ['deviceUuid' => sprintf('00000000-0000-4000-8000-%012d', $call)])->response()->getStatusCode(),
                "Call {$call} is within the budget.",
            );
        }

        $this->assertSame(
            429,
            $this->postJson([], 'devices/auth/challenge', ['deviceUuid' => '00000000-0000-4000-8000-999999999999'])->response()->getStatusCode(),
            'One more from the same address is refused, though it names a computer not named before.',
        );
    }

    /**
     * The context is one object per process. A call with no credential, made after somebody else's authenticated
     * call in the same process, must not be counted as theirs.
     */
    public function testACallWithNoCredentialIsNeverCountedAsTheCallerBeforeIt(): void
    {
        $owner  = $this->makeIdentity('Office Owner');
        $device = $this->asDevice($this->enrolDevice($owner, null, ['deviceName' => 'Front desk']));

        // Uses the whole of this computer's presence-token budget.
        for ($call = 1; $call <= 30; $call++) {
            $this->postJson($device, 'devices/me/presence-token');
        }
        $this->assertSame(429, $this->postJson($device, 'devices/me/presence-token')->response()->getStatusCode());

        // An unauthenticated route with its own bucket: counted against the address, starting from nothing.
        $this->assertNotSame(
            429,
            $this->postJson([], 'devices/auth/challenge', ['deviceUuid' => '00000000-0000-4000-8000-000000000001'])->response()->getStatusCode(),
        );
    }
}
