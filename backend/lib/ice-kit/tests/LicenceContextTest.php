<?php

declare(strict_types=1);

namespace Aicountly\Ice\Tests;

use Aicountly\Ice\Licence\CentralClient;
use Aicountly\Ice\Licence\EntitlementSync;
use Aicountly\Ice\Licence\EntitlementVerifier;
use Aicountly\Ice\Licence\LicenceContext;
use Aicountly\Ice\Licence\LicencePolicy;
use Aicountly\Ice\Licence\LicenceResolver;
use Aicountly\Ice\Licence\LicenceState;
use Aicountly\Ice\Licence\MeetingGuests;
use Aicountly\Ice\Licence\MemoryEntitlementCache;
use Aicountly\Ice\Licence\Resolution;
use Aicountly\Ice\Tier;
use PHPUnit\Framework\TestCase;

/**
 * Contextual, company-scoped licensing. A user is paid in a company because THAT company's subscription permits THAT user's
 * access — company-wide to every current member, or to an assigned seat — and never because some other company, or some
 * other context, is paid. A personal licence is a separate, user-scoped entitlement. External meeting guests are served under
 * the host's licensed meeting, without any account being upgraded.
 */
final class LicenceContextTest extends TestCase
{
    private const NOW = 1_800_000_000;
    private const COMPANY_WIDE = ['model' => 'company_wide', 'member' => null, 'seat' => 'not_applicable'];

    private string $key = '';
    private MemoryEntitlementCache $cache;
    private int $clock = self::NOW;

    protected function setUp(): void
    {
        $pair        = sodium_crypto_sign_keypair();
        $this->key   = sodium_crypto_sign_secretkey($pair);
        $this->cache = new MemoryEntitlementCache();
        $this->pub   = sodium_crypto_sign_publickey($pair);
    }

    private string $pub = '';

    private static function iso(int $t): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $t);
    }

    /** @param array<string, mixed> $over */
    private function doc(array $over = []): array
    {
        return $over + [
            'contract' => 'aicountly.licence/1', 'scope' => 'company', 'customer_id' => 'A', 'product_id' => 'chat', 'subject' => null,
            'licence_id' => 'LIC-1', 'plan_code' => 'CHAT_TEAM', 'plan_class' => 'paid', 'kind' => 'paid', 'state' => 'paid',
            'valid_from' => self::iso(self::NOW - 86400), 'valid_until' => self::iso(self::NOW + 30 * 86400),
            'entitlements' => [], 'revision' => 1, 'issued_at' => self::iso(self::NOW - 60), 'eligibility' => self::COMPANY_WIDE,
        ];
    }

    /** Store a signed company-level document (company-wide or seat licence). @param array<string, mixed> $over */
    private function company(string $customer, string $model = 'company_wide', array $over = []): void
    {
        $this->cache->put('customer', $customer, 'chat', EntitlementVerifier::sign($this->doc($over + ['customer_id' => $customer, 'eligibility' => ['model' => $model, 'member' => null, 'seat' => 'not_applicable']]), 'k', $this->key), self::NOW);
    }

    /** Store a signed member-level document: this user's eligibility inside this company. @param array<string, mixed> $over */
    private function member(string $customer, string $subject, bool $isMember, string $model = 'company_wide', string $seat = 'not_applicable', array $over = []): void
    {
        $doc = $this->doc($over + ['customer_id' => $customer, 'subject' => $subject, 'eligibility' => ['model' => $model, 'member' => $isMember, 'seat' => $seat]]);
        $this->cache->put('member', "{$customer}|{$subject}", 'chat', EntitlementVerifier::sign($doc, 'k', $this->key), self::NOW);
    }

    /** @param array<string, mixed> $over */
    private function personal(string $subject, array $over = []): void
    {
        $doc = $this->doc($over + ['scope' => 'user', 'customer_id' => null, 'subject' => $subject, 'plan_code' => 'CHAT_PERSONAL',
            'eligibility' => ['model' => 'personal', 'member' => null, 'seat' => 'not_applicable']]);
        $this->cache->put('user', $subject, 'chat', EntitlementVerifier::sign($doc, 'k', $this->key), self::NOW);
    }

    private function resolver(): LicenceResolver
    {
        return new LicenceResolver($this->cache, new EntitlementVerifier(['k' => $this->pub]), new LicencePolicy(172800, ['paid'], false, true), fn (): int => $this->clock);
    }

    private function ask(?string $customer, ?string $subject, ?bool $membershipVerified = null): Resolution
    {
        return $this->resolver()->forContext(new LicenceContext($customer, 'chat', $subject, $membershipVerified));
    }

    /** @return array{0: string, 1: string} state and reason */
    private static function sr(Resolution $r): array
    {
        return [$r->state->value, $r->reason];
    }

    // ---- company-wide licences ---------------------------------------------------------------------------------

    public function testACompanyWideLicenceQualifiesACurrentMemberOnlyWithMembershipEvidence(): void
    {
        $this->company('A');

        $this->assertSame(['free', 'membership_unverified'], self::sr($this->ask('A', 'u1')), 'the company is paid, but nothing says u1 belongs to it');
        $this->assertSame(['paid', 'paid'], self::sr($this->ask('A', 'u1', true)), 'the product confirms current membership');
        $this->assertSame(['free', 'not_a_member'], self::sr($this->ask('A', 'u1', false)), 'the product knows the user was removed');
        $this->assertSame('company_wide', $this->ask('A', 'u1', true)->basis);

        // A signed member-level document is the central statement of membership.
        $this->member('A', 'u2', true);
        $this->assertSame(['paid', 'paid'], self::sr($this->ask('A', 'u2')));
        $this->member('A', 'u3', false);
        $this->assertSame(['free', 'not_a_member'], self::sr($this->ask('A', 'u3')), 'the backend says u3 is not a member');
        $this->assertSame(['free', 'not_a_member'], self::sr($this->ask('A', 'u2', false)), 'either side saying "not a member" ends eligibility');
    }

    public function testACompanyLevelQuestionWithNoUserIsAnsweredByACompanyWideLicenceOnly(): void
    {
        $this->company('A');
        $this->assertSame(['paid', 'paid'], self::sr($this->ask('A', null)), 'a Remote session accounted to its company');

        $this->company('S', 'seat');
        $this->assertSame(['free', 'seat_requires_subject'], self::sr($this->ask('S', null)));
    }

    // ---- seats -------------------------------------------------------------------------------------------------

    public function testASeatLicenceQualifiesOnlyTheAssignedSeatHolder(): void
    {
        $this->company('S', 'seat');
        $this->member('S', 'holder', true, 'seat', 'assigned');
        $this->member('S', 'spare', true, 'seat', 'unassigned');
        $this->member('S', 'former', true, 'seat', 'revoked');

        $this->assertSame(['paid', 'paid'], self::sr($this->ask('S', 'holder')));
        $this->assertSame('seat', $this->ask('S', 'holder')->basis);
        $this->assertSame(['free', 'seat_unassigned'], self::sr($this->ask('S', 'spare')), 'a member without a seat');
        $this->assertSame(['free', 'seat_revoked'], self::sr($this->ask('S', 'former')));
        $this->assertSame(['free', 'seat_not_assigned'], self::sr($this->ask('S', 'stranger', true)), 'membership alone is not a seat, and the company document is not an assignment');
    }

    public function testRevokingASeatOrRemovingAMemberRevokesThatPairOnlyAndImmediately(): void
    {
        $this->company('S', 'seat');
        $this->member('S', 'u1', true, 'seat', 'assigned', ['revision' => 4]);
        $this->member('S', 'u2', true, 'seat', 'assigned', ['revision' => 7]);
        $this->assertSame('paid', $this->ask('S', 'u1')->state->value);

        $this->cache->revoke('S', 'chat', 4, self::NOW, 'u1');        // what the changes feed does when a seat is revoked / a member removed

        $this->assertSame(['suspended', 'revoked'], self::sr($this->ask('S', 'u1')));
        $this->assertSame(Tier::Free, $this->ask('S', 'u1')->tier());
        $this->assertSame('paid', $this->ask('S', 'u2')->state->value, 'the other seat holder is untouched');

        $this->member('S', 'u1', true, 'seat', 'assigned', ['revision' => 5]);          // a seat re-assigned later lifts it
        $this->assertSame('paid', $this->ask('S', 'u1')->state->value);
    }

    // ---- tenant isolation and switching ------------------------------------------------------------------------

    public function testPaidStatusNeverTransfersBetweenCompanies(): void
    {
        $this->company('A');
        $this->member('A', 'u1', true);
        // u1 is also a member of B, whose subscription has lapsed; and of C, which has no licence on record at all.
        $this->company('B', 'company_wide', ['state' => 'expired']);
        $this->member('B', 'u1', true, 'company_wide', 'not_applicable', ['state' => 'expired']);

        $this->assertSame(['paid', 'paid'], self::sr($this->ask('A', 'u1')));
        $this->assertSame(['expired', 'expired'], self::sr($this->ask('B', 'u1')), 'switching to B recalculates: A\'s licence does not follow');
        $this->assertSame(['unknown', 'no_entitlement'], self::sr($this->ask('C', 'u1', true)), 'no licence for C: nothing to inherit');
        $this->assertSame(['paid', 'paid'], self::sr($this->ask('A', 'u1')), 'and switching back is recalculated again');
    }

    public function testADocumentOfOneCompanyNeverAnswersForAnother(): void
    {
        // A's genuine, signed, paid member document filed (by mistake or by an attacker with write access to the cache) under B.
        $this->member('A', 'u1', true);
        $envelope = $this->cache->get('member', 'A|u1', 'chat')['envelope'];
        $this->cache->put('member', 'B|u1', 'chat', $envelope, self::NOW);

        $this->assertSame(['unknown', 'identity_mismatch'], self::sr($this->ask('B', 'u1')));

        // And company-level, and across users.
        $this->company('A');
        $this->cache->put('customer', 'B', 'chat', $this->cache->get('customer', 'A', 'chat')['envelope'], self::NOW);
        $this->assertSame('identity_mismatch', $this->ask('B', null)->reason);
        $this->cache->put('member', 'A|u9', 'chat', $envelope, self::NOW);
        $this->assertSame('identity_mismatch', $this->ask('A', 'u9')->reason, 'u1\'s seat document is not u9\'s');
    }

    public function testRevokingOneCompanyOrOneProductLeavesTheOthersAlone(): void
    {
        $this->company('A');
        $this->company('B');
        $this->cache->revoke('A', 'chat', 1, self::NOW);

        $this->assertSame(['suspended', 'revoked'], self::sr($this->ask('A', null)));
        $this->assertSame(['paid', 'paid'], self::sr($this->ask('B', null)));
        $this->assertSame(-1, $this->cache->revokedRevision('A', 'remote'), 'revocation is per product');
    }

    public function testALapsedOrSuspendedCompanyLicenceBeatsASeatDocumentThatHasNotCaughtUp(): void
    {
        $this->company('S', 'seat', ['state' => 'suspended']);
        $this->member('S', 'u1', true, 'seat', 'assigned');           // issued before the suspension reached it

        $this->assertSame(['suspended', 'suspended'], self::sr($this->ask('S', 'u1')));
    }

    // ---- expiry ------------------------------------------------------------------------------------------------

    public function testAnExpiredSubscriptionEndsEligibilityOnTheSecondAndAStoppedFeedAfterTheMaximumAge(): void
    {
        $this->member('A', 'u1', true, 'company_wide', 'not_applicable', ['valid_until' => self::iso(self::NOW + 100), 'issued_at' => self::iso(self::NOW)]);

        $this->clock = self::NOW + 99;
        $this->assertSame('paid', $this->ask('A', 'u1')->state->value);
        $this->clock = self::NOW + 100;
        $this->assertSame(['expired', 'valid_until_passed'], self::sr($this->ask('A', 'u1')), 'valid_until is the first moment it no longer applies');

        $this->member('A', 'u2', true, 'company_wide', 'not_applicable', ['valid_until' => self::iso(self::NOW + 30 * 86400), 'issued_at' => self::iso(self::NOW)]);
        $this->clock = self::NOW + 172800 + 1;
        $this->assertSame(['unknown', 'stale'], self::sr($this->ask('A', 'u2')), 'the feed stopped: nothing is trusted for ever');
    }

    // ---- personal licences -------------------------------------------------------------------------------------

    public function testAPersonalLicenceIsASeparateUserScopedEntitlementOfThePersonalContextOnly(): void
    {
        $this->personal('u1');
        $this->company('A');
        $this->member('A', 'u2', true);

        $this->assertSame(['paid', 'paid', 'personal'], [...self::sr($this->ask(null, 'u1')), $this->ask(null, 'u1')->basis], 'in a personal context the user\'s own licence applies');
        $this->assertSame(['unknown', 'no_entitlement'], self::sr($this->ask('Z', 'u1', true)), 'but it is not carried into a company\'s context (Z has no licence for u1)');
        $this->assertSame('company_wide', $this->ask('A', 'u1', true)->basis, 'inside A the user is paid by A\'s subscription, not by the personal licence');
        $this->assertSame(['unknown', 'no_entitlement'], self::sr($this->ask(null, 'u2')), 'and a company licence is not a personal one');
        $this->assertSame(['unknown', 'no_context'], self::sr($this->ask(null, null)));
    }

    public function testAPersonalLicenceIsRevokedAndExpiresOnItsOwn(): void
    {
        $this->personal('u1', ['revision' => 3]);
        $this->cache->revoke(null, 'chat', 3, self::NOW, 'u1');
        $this->assertSame(['suspended', 'revoked'], self::sr($this->ask(null, 'u1')));

        $this->personal('u2', ['valid_until' => self::iso(self::NOW - 1)]);
        $this->assertSame('expired', $this->ask(null, 'u2')->state->value);
    }

    // ---- the five states, once more, in context ----------------------------------------------------------------

    public function testOnlyPaidQualifiesForProtectedCapacity(): void
    {
        foreach (['free' => ['free', 'free'], 'expired' => ['expired', 'expired'], 'suspended' => ['suspended', 'suspended'], 'unknown' => ['unknown', 'central_unknown']] as $state => $expect) {
            $this->member('A', 'u1', true, 'company_wide', 'not_applicable', ['state' => $state] + ($state === 'free' ? ['licence_id' => null, 'plan_code' => null, 'plan_class' => 'free', 'kind' => 'free', 'valid_until' => null] : []));
            $r = $this->ask('A', 'u1', true);
            $this->assertSame($expect, self::sr($r), $state);
            $this->assertSame(Tier::Free, $r->tier(), $state);
        }
        $this->member('A', 'u1', true);
        $this->assertSame(Tier::Paid, $this->ask('A', 'u1', true)->tier());
    }

    // ---- fetch-through -----------------------------------------------------------------------------------------

    public function testAMissingMemberDocumentCanBeFetchedAndIsVerifiedBeforeUse(): void
    {
        $good   = EntitlementVerifier::sign($this->doc(['customer_id' => 'A', 'subject' => 'u1', 'eligibility' => ['model' => 'company_wide', 'member' => true, 'seat' => 'not_applicable']]), 'k', $this->key);
        $forged = EntitlementVerifier::sign($this->doc(['customer_id' => 'A', 'subject' => 'u2', 'eligibility' => ['model' => 'company_wide', 'member' => true, 'seat' => 'not_applicable']]), 'k', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $calls  = 0;
        $fetch  = function (string $c, string $s, string $p) use ($good, $forged, &$calls): array {
            $calls++;

            return match ($s) { 'u1' => $good, 'u2' => $forged, default => throw new \RuntimeException('down') };
        };
        $resolver = new LicenceResolver($this->cache, new EntitlementVerifier(['k' => $this->pub]), new LicencePolicy(172800, ['paid'], false, true), fn (): int => $this->clock, $fetch);
        $ask      = static fn (string $s): Resolution => $resolver->forContext(new LicenceContext('A', 'chat', $s));

        $this->assertSame('paid', $ask('u1')->state->value);
        $this->assertSame('paid', $ask('u1')->state->value);
        $this->assertSame(1, $calls, 'the second request is served from the cache');
        $this->assertSame(Tier::Free, $ask('u2')->tier(), 'a forged answer is never stored or used');
        $this->assertNull($this->cache->get('member', 'A|u2', 'chat'));
        $this->assertSame(Tier::Free, $ask('u3')->tier(), 'a failing backend is free, not an error');
    }

    // ---- sync: per-subject revocation --------------------------------------------------------------------------

    public function testTheChangesFeedRevokesOneMemberWithoutTouchingTheRestAndRefetchesThem(): void
    {
        $this->member('A', 'u1', true, 'company_wide', 'not_applicable', ['revision' => 2]);
        $this->member('A', 'u2', true, 'company_wide', 'not_applicable', ['revision' => 2]);
        $this->cache->saveSyncState('chat', 5, self::NOW - 60, true);

        $removed = EntitlementVerifier::sign($this->doc(['customer_id' => 'A', 'subject' => 'u1', 'revision' => 3, 'eligibility' => ['model' => 'company_wide', 'member' => false, 'seat' => 'not_applicable']]), 'k', $this->key);
        $client  = new CentralClient('https://licence.example.test/api', 'chat', 'chat-read', str_repeat('s', 40), 'sandbox', function (string $url) use ($removed): array {
            if (str_contains($url, '/changes')) {
                $body = str_contains($url, 'since=5') ? [['seq' => 6, 'customer_id' => 'A', 'subject' => 'u1', 'product_id' => 'chat', 'revision' => 2, 'revoked' => true]] : [];

                return ['status' => 200, 'body' => json_encode(['success' => true, 'data' => $body, 'page' => ['next_seq' => 6]])];
            }
            $this->assertStringContainsString('subject=u1', $url, 'the refetch asks for that member\'s document');

            return ['status' => 200, 'body' => json_encode(['success' => true, 'data' => $removed])];
        });
        $stats = (new EntitlementSync($client, $this->cache, new EntitlementVerifier(['k' => $this->pub]), 'chat', fn (): int => self::NOW))->run();

        $this->assertSame([1, 1], [$stats['stored'], $stats['revoked']]);
        $this->assertSame(['free', 'not_a_member'], self::sr($this->ask('A', 'u1')));
        $this->assertSame(['paid', 'paid'], self::sr($this->ask('A', 'u2')));
    }

    // ---- external meeting guests -------------------------------------------------------------------------------

    private function host(array $entitlements = []): Resolution
    {
        $this->member('A', 'host', true, 'company_wide', 'not_applicable', ['entitlements' => $entitlements]);

        return $this->ask('A', 'host');
    }

    public function testGuestsOfAPaidHostGetPaidQualityUpToTheMeetingLimitWithoutAnyAccountBeingUpgraded(): void
    {
        $guests = new MeetingGuests(3, true);
        $host   = $this->host([['code' => 'meeting.max_guests', 'value' => 2]]);
        $this->assertSame('paid', $host->state->value);

        $g0 = $guests->decide($host, 0, 1);
        $this->assertSame([LicenceState::Paid, 'host_meeting', 'host_meeting'], [$g0->state, $g0->reason, $g0->basis]);
        $this->assertSame(Tier::Paid, $guests->decide($host, 1, 2)->tier());
        $over = $guests->decide($host, 2, 3);
        $this->assertSame([Tier::Free, 'guest_limit'], [$over->tier(), $over->reason], 'the entitlement\'s limit (2) beats the default (3)');

        // The guest as an individual is unaffected: anywhere else they resolve on their own entitlement.
        $this->assertSame(['unknown', 'no_entitlement'], self::sr($this->ask('A', 'the-guest-as-a-user', true)));
        $this->assertSame(['unknown', 'no_entitlement'], self::sr($this->ask(null, 'the-guest-as-a-user')));
    }

    public function testGuestsGetNothingFromAHostWhoIsNotPaidInThatContext(): void
    {
        $guests = new MeetingGuests();
        $this->company('B', 'company_wide', ['state' => 'expired']);
        $this->member('B', 'host', true, 'company_wide', 'not_applicable', ['state' => 'expired']);
        $this->member('S', 'host2', true, 'seat', 'unassigned');
        $this->company('S', 'seat');

        foreach ([$this->ask('B', 'host'), $this->ask('S', 'host2'), $this->ask('A', 'nobody', true), $this->ask(null, 'host')] as $host) {
            $r = $guests->decide($host, 0, 1);
            $this->assertSame([Tier::Free, 'host_not_paid'], [$r->tier(), $r->reason]);
        }
    }

    public function testMeetingLimitsComeFromTheHostsEntitlementsWithConservativeDefaults(): void
    {
        $this->assertSame('guests_not_in_plan', (new MeetingGuests())->decide($this->host([['code' => 'meeting.guests_paid_quality', 'value' => false]]), 0, 1)->reason);
        $this->assertSame('participant_limit', (new MeetingGuests())->decide($this->host([['code' => 'meeting.max_participants', 'value' => 4]]), 3, 4)->reason);
        $this->assertSame('paid', (new MeetingGuests())->decide($this->host([['code' => 'meeting.max_participants', 'value' => 4]]), 2, 3)->state->value);

        $plain = $this->host();
        $this->assertSame('paid', (new MeetingGuests(5, true))->decide($plain, 4, 5)->state->value);
        $this->assertSame('guest_limit', (new MeetingGuests(5, true))->decide($plain, 5, 6)->reason, 'the product default applies when the plan states none');
        $this->assertSame('guests_not_in_plan', (new MeetingGuests(5, false))->decide($plain, 0, 1)->reason, 'and a product can decline guests by default');
        $this->assertSame('guest_limit', (new MeetingGuests(0, true))->decide($plain, 0, 1)->reason);
    }

    public function testGuestPolicyIsReadFromTheEnvironment(): void
    {
        $env = ['WEBRTC_LICENCE_DEFAULT_MAX_GUESTS' => '8', 'WEBRTC_LICENCE_GUESTS_PAID_QUALITY' => 'deny'];
        $g   = MeetingGuests::fromEnvironment(static fn (string $k): ?string => $env[$k] ?? null);
        $this->assertSame([8, false], [$g->defaultMaxGuests, $g->paidQualityByDefault]);
        $d = MeetingGuests::fromEnvironment(static fn (): ?string => null);
        $this->assertSame([5, true], [$d->defaultMaxGuests, $d->paidQualityByDefault]);
    }
}
