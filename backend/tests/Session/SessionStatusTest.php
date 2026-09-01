<?php

declare(strict_types=1);

namespace Tests\Session;

use App\Domain\Session\SessionStatus;
use App\Domain\Support\ApiException;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The state machine, on its own (§21).
 *
 * No database: the transition table is pure logic and deserves to be tested as
 * such, so a failure here points at the rule rather than at a fixture.
 *
 * @internal
 */
final class SessionStatusTest extends CIUnitTestCase
{
    public function testTheHappyPathIsWalkable(): void
    {
        $path = [
            SessionStatus::CREATED,
            SessionStatus::WAITING,
            SessionStatus::JOIN_REQUESTED,
            SessionStatus::CONNECTING,
            SessionStatus::ACTIVE,
            SessionStatus::ENDED,
        ];

        for ($i = 0; $i < count($path) - 1; $i++) {
            $this->assertTrue(
                SessionStatus::canTransition($path[$i], $path[$i + 1]),
                "{$path[$i]} → {$path[$i + 1]} must be allowed.",
            );
        }
    }

    public function testTerminalStatesAreTerminal(): void
    {
        foreach ([SessionStatus::ENDED, SessionStatus::DECLINED, SessionStatus::EXPIRED, SessionStatus::FAILED] as $terminal) {
            $this->assertTrue(SessionStatus::isTerminal($terminal));
            $this->assertFalse(SessionStatus::isLive($terminal));

            foreach (SessionStatus::all() as $target) {
                if ($target === $terminal) {
                    continue;
                }
                $this->assertFalse(
                    SessionStatus::canTransition($terminal, $target),
                    "A {$terminal} session must not become {$target}.",
                );
            }
        }
    }

    public function testAnEndedSessionReportsItselfAsSuch(): void
    {
        try {
            SessionStatus::assertTransition(SessionStatus::ENDED, SessionStatus::ACTIVE);
            $this->fail('Expected a conflict.');
        } catch (ApiException $e) {
            $this->assertSame('SESSION_ALREADY_ENDED', $e->errorCode());
            $this->assertSame(409, $e->status());
        }
    }

    public function testAnImpossibleMoveBetweenLiveStatesIsReported(): void
    {
        try {
            // Nothing goes straight from waiting to paused: there is nothing
            // to pause yet.
            SessionStatus::assertTransition(SessionStatus::WAITING, SessionStatus::PAUSED);
            $this->fail('Expected a conflict.');
        } catch (ApiException $e) {
            $this->assertSame('SESSION_STATE_INVALID', $e->errorCode());
        }
    }

    public function testReassertingTheCurrentStateIsANoOp(): void
    {
        // Idempotency matters: a retried request must not be an error.
        foreach (SessionStatus::all() as $status) {
            $this->assertTrue(SessionStatus::canTransition($status, $status));
            SessionStatus::assertTransition($status, $status);
        }

        $this->addToAssertionCount(1);
    }

    public function testEveryLiveStateCanReachEnded(): void
    {
        // However a session goes wrong, someone must be able to end it.
        foreach (SessionStatus::all() as $status) {
            if (SessionStatus::isTerminal($status)) {
                continue;
            }

            $this->assertTrue(
                SessionStatus::canTransition($status, SessionStatus::ENDED),
                "A session in {$status} must be endable.",
            );
        }
    }

    public function testAnActiveSessionCanDropBackToReconnecting(): void
    {
        $this->assertTrue(SessionStatus::canTransition(SessionStatus::ACTIVE, SessionStatus::RECONNECTING));
        $this->assertTrue(SessionStatus::canTransition(SessionStatus::RECONNECTING, SessionStatus::ACTIVE));
        $this->assertTrue(SessionStatus::canTransition(SessionStatus::ACTIVE, SessionStatus::PAUSED));
        $this->assertTrue(SessionStatus::canTransition(SessionStatus::PAUSED, SessionStatus::ACTIVE));
    }
}
