<?php

declare(strict_types=1);

namespace Aicountly\Ice\Tests;

use Aicountly\Ice\Admission;
use Aicountly\Ice\AdmissionDecision;
use Aicountly\Ice\AdmissionPolicy;
use Aicountly\Ice\Capacity;
use Aicountly\Ice\Tier;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/autoload.php';

/**
 * The rule that protects the paid reserve, as arithmetic. No relay, file or clock is involved.
 */
final class AdmissionTest extends TestCase
{
    private const CAP = 100;

    private function capacity(int $reserve = 50): Capacity
    {
        return new Capacity(self::CAP, 0, $reserve);          // allocations only: one unit each
    }

    private function decide(Tier $tier, int $paid, int $free, ?int $measured = null, int $reserve = 50): AdmissionDecision
    {
        return Admission::evaluate(
            $this->capacity($reserve),
            new AdmissionPolicy(),
            ['paid' => ['allocations' => $paid], 'free' => ['allocations' => $free]],
            $measured === null ? null : ['allocations' => $measured],
            $tier,
            ['allocations' => 1, 'bps' => 0],
        );
    }

    public function testFreeUsersFillOnlyTheSharedHalf(): void
    {
        $this->assertTrue($this->decide(Tier::Free, 0, 48)->admitted);
        $this->assertTrue($this->decide(Tier::Free, 0, 49)->admitted, 'The 50th free user is the last one the shared half holds.');
        $this->assertFalse($this->decide(Tier::Free, 0, 50)->admitted);
        $this->assertSame(AdmissionDecision::FREE_POOL_FULL, $this->decide(Tier::Free, 0, 50)->reason);
    }

    public function testThePaidReserveIsHeldWhileNoPaidUserIsUsingIt(): void
    {
        // Free is full and the node is half empty: the empty half is still paid customers'.
        $this->assertFalse($this->decide(Tier::Free, 0, 50)->admitted);
        $this->assertTrue($this->decide(Tier::Paid, 0, 50)->admitted);
        $this->assertTrue($this->decide(Tier::Paid, 49, 50)->admitted, 'Paid fills its whole reserve even with free users at their limit.');
    }

    public function testPaidMayAlsoUseTheSharedCapacityAndFreeShrinksToMakeRoom(): void
    {
        $this->assertTrue($this->decide(Tier::Paid, 60, 0)->admitted);
        // 60 paid users use 10 of the shared half: free has 30 left, but the shed threshold (85) leaves 25.
        $this->assertTrue($this->decide(Tier::Free, 60, 24)->admitted);
        $this->assertFalse($this->decide(Tier::Free, 60, 25)->admitted);
        $this->assertSame(AdmissionDecision::NODE_OVERLOADED, $this->decide(Tier::Free, 60, 25)->reason);
    }

    public function testPaidIsOnlyEverRefusedAtTheHardLimit(): void
    {
        $this->assertTrue($this->decide(Tier::Paid, 50, 49)->admitted);
        $refused = $this->decide(Tier::Paid, 50, 50);
        $this->assertFalse($refused->admitted);
        $this->assertSame(AdmissionDecision::NODE_FULL, $refused->reason);
    }

    public function testFreeIsShedBeforeThePaidHeadroomIsGone(): void
    {
        // 85% shed: with 40 paid, free stops at 45 even though the pool rule would allow 60.
        $this->assertTrue($this->decide(Tier::Free, 40, 44)->admitted);
        $this->assertFalse($this->decide(Tier::Free, 40, 45)->admitted);
    }

    public function testTrafficTheBooksCannotExplainIsChargedAgainstEveryone(): void
    {
        // Books: 10 paid, 10 free. The node is measurably carrying 80 — 60 unaccounted for.
        $this->assertFalse($this->decide(Tier::Free, 10, 10, 80)->admitted);
        $this->assertTrue($this->decide(Tier::Paid, 10, 10, 80)->admitted);
        $this->assertFalse($this->decide(Tier::Paid, 10, 10, 100)->admitted);
        // A measurement BELOW the books never loosens anything.
        $this->assertSame(
            $this->decide(Tier::Free, 10, 10)->admitted,
            $this->decide(Tier::Free, 10, 10, 3)->admitted,
        );
    }

    public function testAnUnmeteredNodeAdmitsEveryoneAndASplitNodeServesOnlyItsTiers(): void
    {
        $policy = new AdmissionPolicy();
        $ask    = ['allocations' => 1, 'bps' => 0];

        $open = Admission::evaluate(Capacity::unmetered(), $policy, ['paid' => [], 'free' => []], null, Tier::Free, $ask);
        $this->assertTrue($open->admitted);
        $this->assertSame(AdmissionDecision::UNMETERED, $open->reason);

        $paidOnly = new Capacity(100, 0, 0, [Tier::Paid]);
        $this->assertFalse(Admission::evaluate($paidOnly, $policy, ['paid' => [], 'free' => []], null, Tier::Free, $ask)->admitted);
        $this->assertSame(AdmissionDecision::TIER_NOT_SERVED, Admission::evaluate($paidOnly, $policy, ['paid' => [], 'free' => []], null, Tier::Free, $ask)->reason);
        $this->assertTrue(Admission::evaluate($paidOnly, $policy, ['paid' => ['allocations' => 99], 'free' => []], null, Tier::Paid, $ask)->admitted);
    }

    public function testEitherDimensionCanRefuse(): void
    {
        $capacity = new Capacity(100, 1_000_000, 50);
        $policy   = new AdmissionPolicy();
        $used     = ['paid' => [], 'free' => ['allocations' => 0, 'bps' => 480_000]];

        $this->assertTrue(Admission::evaluate($capacity, $policy, $used, null, Tier::Free, ['allocations' => 1, 'bps' => 20_000])->admitted);
        $this->assertFalse(Admission::evaluate($capacity, $policy, $used, null, Tier::Free, ['allocations' => 1, 'bps' => 30_000])->admitted, 'Bandwidth alone is enough to refuse.');
        $this->assertFalse(Admission::evaluate($capacity, $policy, ['paid' => [], 'free' => ['allocations' => 50]], null, Tier::Free, ['allocations' => 1, 'bps' => 1])->admitted, 'So are allocations.');
    }

    public function testLoadWordsAndRoutingPenaltyAreMonotonic(): void
    {
        $policy = new AdmissionPolicy();
        $this->assertSame('normal', Admission::load(0.69, $policy));
        $this->assertSame('busy', Admission::load(0.70, $policy));
        $this->assertSame('overloaded', Admission::load(0.85, $policy));
        $this->assertSame('full', Admission::load(1.0, $policy));

        $previous = -1.0;
        for ($u = 0.0; $u <= 1.2; $u += 0.01) {
            $penalty = Admission::loadPenaltyMs($u, $policy);
            $this->assertGreaterThanOrEqual($previous, $penalty);
            $previous = $penalty;
        }
        $this->assertSame(0.0, Admission::loadPenaltyMs(0.5, $policy));
    }

    /**
     * Random admissions and releases, tier mix and reserve varied: after every step the two invariants
     * hold — free never exceeds cap − max(paid, reserve) and the node never exceeds its capacity — and a
     * paid user inside the reserve is never refused.
     */
    public function testInvariantsHoldUnderRandomLoad(): void
    {
        mt_srand(20261021);

        foreach ([50, 60, 75, 90] as $reserve) {
            $paid = 0;
            $free = 0;

            for ($step = 0; $step < 4000; $step++) {
                if (mt_rand(0, 99) < 35) {                      // a session ends
                    if (mt_rand(0, 1) === 1 && $paid > 0) {
                        $paid--;
                    } elseif ($free > 0) {
                        $free--;
                    }
                    continue;
                }

                $tier     = mt_rand(0, 99) < 40 ? Tier::Paid : Tier::Free;
                $decision = $this->decide($tier, $paid, $free, null, $reserve);
                $r        = (int) ceil(self::CAP * $reserve / 100);

                if ($tier === Tier::Paid && $paid + 1 <= $r) {
                    $this->assertTrue($decision->admitted, "A paid user inside the reserve was refused (paid={$paid}, free={$free}, reserve={$reserve}).");
                }

                if ($decision->admitted) {
                    $tier === Tier::Paid ? $paid++ : $free++;
                }

                $this->assertLessThanOrEqual(self::CAP, $paid + $free, 'Capacity exceeded.');
                // The real invariant: whenever free grew, it left the reserve intact.
                if ($decision->admitted && $tier === Tier::Free) {
                    $this->assertLessThanOrEqual(self::CAP - max($paid, $r), $free, "Free consumed the paid reserve (paid={$paid}, free={$free}, reserve={$reserve}).");
                }
            }
        }
    }
}
