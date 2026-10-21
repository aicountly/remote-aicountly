<?php

declare(strict_types=1);

namespace Aicountly\Ice\Tests;

use Aicountly\Ice\LedgerConnection;
use Aicountly\Ice\Licence\AicS2s;
use Aicountly\Ice\Licence\Canonical;
use Aicountly\Ice\Licence\CentralClient;
use Aicountly\Ice\Licence\CentralUnavailable;
use Aicountly\Ice\Licence\Entitlement;
use Aicountly\Ice\Licence\EntitlementCache;
use Aicountly\Ice\Licence\EntitlementSync;
use Aicountly\Ice\Licence\EntitlementVerifier;
use Aicountly\Ice\Licence\FileEntitlementCache;
use Aicountly\Ice\Licence\LicencePolicy;
use Aicountly\Ice\Licence\LicenceReadiness;
use Aicountly\Ice\Licence\LicenceResolver;
use Aicountly\Ice\Licence\LicenceState;
use Aicountly\Ice\Licence\MemoryEntitlementCache;
use Aicountly\Ice\Licence\PdoEntitlementCache;
use Aicountly\Ice\Tier;
use PHPUnit\Framework\TestCase;

/**
 * The common licence-entitlement adapter (docs/LICENCE_ENTITLEMENT_CONTRACT.md): the signed document, who may
 * vouch for it, and every way it can fail to make a customer paid. A fake central backend signs with a throwaway
 * Ed25519 key; nothing here talks to a network.
 */
final class LicenceTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private string $secretKey = '';
    private string $publicKey = '';
    private string $otherSecretKey = '';

    protected function setUp(): void
    {
        $pair            = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($pair);
        $this->publicKey = sodium_crypto_sign_publickey($pair);
        $this->otherSecretKey = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    }

    // ---- helpers ---------------------------------------------------------------------------------------------

    private static function iso(int $t): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $t);
    }

    /** @param array<string, mixed> $over */
    private function doc(array $over = []): array
    {
        return $over + [
            'contract' => 'aicountly.licence/1', 'customer_id' => '42', 'product_id' => 'remote', 'subject' => null,
            'licence_id' => 'LIC-000123', 'plan_code' => 'REMOTE_BUSINESS', 'plan_class' => 'paid', 'kind' => 'paid', 'state' => 'paid',
            'valid_from' => self::iso(self::NOW - 86400), 'valid_until' => self::iso(self::NOW + 30 * 86400),
            'entitlements' => [['code' => 'max_session_minutes', 'value' => 480], ['code' => 'recording', 'value' => true]],
            'revision' => 5, 'issued_at' => self::iso(self::NOW - 600),
        ];
    }

    /** @param array<string, mixed> $over */
    private function envelope(array $over = [], ?string $key = null, string $kid = 'k1'): array
    {
        return EntitlementVerifier::sign($this->doc($over), $kid, $key ?? $this->secretKey);
    }

    private function verifier(): EntitlementVerifier
    {
        return new EntitlementVerifier(['k1' => $this->publicKey]);
    }

    private function resolver(?MemoryEntitlementCache $cache = null, ?LicencePolicy $policy = null, int $now = self::NOW): LicenceResolver
    {
        return new LicenceResolver($cache ?? new MemoryEntitlementCache(), $this->verifier(), $policy ?? new LicencePolicy(172800, ['paid'], false, true), static fn (): int => $now);
    }

    private function cacheWith(array $envelope, string $scope = 'customer', string $ref = '42'): MemoryEntitlementCache
    {
        $c = new MemoryEntitlementCache();
        $c->put($scope, $ref, 'remote', $envelope, self::NOW);

        return $c;
    }

    // ---- wire format -----------------------------------------------------------------------------------------

    public function testAicS2sMatchesFinancesPublishedWorkedExample(): void
    {
        $canonical = AicS2s::canonical('GET', '/v1/inbound/manage/documents', 'limit=25&cmp_id=42', '1760781600', '3f1c2a9e-7b4d-4e8a-9c61-5d2e8f0a1b37', '');
        $this->assertSame("GET\n/v1/inbound/manage/documents\ncmp_id=42&limit=25\n1760781600\n3f1c2a9e-7b4d-4e8a-9c61-5d2e8f0a1b37\ne3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855", $canonical);
        $this->assertSame('v1=2c93e407965eea0f40532c26a36b978912a090d845d7a662983489139110684b', AicS2s::signature('manage-to-finance-example-secret-0123456789', $canonical));
    }

    public function testCanonicalJsonIsOrderIndependentAndRefusesWhatCannotBeSignedUnambiguously(): void
    {
        $this->assertSame(Canonical::json(['b' => 1, 'a' => ['y' => 'é/', 'x' => [3, 1]]]), Canonical::json(['a' => ['x' => [3, 1], 'y' => 'é/'], 'b' => 1]));
        $this->assertSame('{"a":{"x":[3,1],"y":"é/"},"b":1}', Canonical::json(['b' => 1, 'a' => ['y' => 'é/', 'x' => [3, 1]]]));
        $this->expectException(\InvalidArgumentException::class);
        Canonical::json(['n' => 1.5]);
    }

    public function testAnEmptyCollectionIsAListSoItSignsTheSameEverywhere(): void
    {
        $this->assertSame('{"entitlements":[],"x":1}', Canonical::json(['x' => 1, 'entitlements' => []]));
    }

    // ---- the document ----------------------------------------------------------------------------------------

    public function testAWellFormedDocumentIsReadAndMalformedOnesAreRefused(): void
    {
        $e = Entitlement::fromDocument($this->doc());
        $this->assertSame(LicenceState::Paid, $e->state);
        $this->assertSame('REMOTE_BUSINESS', $e->planCode);
        $this->assertSame(480, $e->entitlement('max_session_minutes'));
        $this->assertTrue($e->entitlement('recording'));
        $this->assertNull($e->entitlement('nope'));

        $bad = [
            'wrong contract' => ['contract' => 'aicountly.licence/2'],
            'bad customer' => ['customer_id' => '4 2'],
            'bad state' => ['state' => 'gold'],
            'paid without licence' => ['licence_id' => null],
            'paid without plan' => ['plan_code' => null],
            'paid but free class' => ['plan_class' => 'free'],
            'offset time' => ['valid_until' => '2027-01-01T00:00:00+05:30'],
            'until before from' => ['valid_until' => self::iso(self::NOW - 90000)],
            'entitlements not a list' => ['entitlements' => ['a' => 1]],
            'float value' => ['entitlements' => [['code' => 'x', 'value' => 1.5]]],
            'duplicate code' => ['entitlements' => [['code' => 'x', 'value' => 1], ['code' => 'x', 'value' => 2]]],
            'negative revision' => ['revision' => -1],
            'no issued_at' => ['issued_at' => null],
        ];
        foreach ($bad as $label => $over) {
            try {
                Entitlement::fromDocument($over + $this->doc());
                $this->fail("{$label} must be refused");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSignaturesVerifyOnlyForTheCentralKey(): void
    {
        $v = $this->verifier();
        $this->assertTrue($v->verify($this->envelope()));
        $this->assertFalse($v->verify($this->envelope([], $this->otherSecretKey)), 'a different private key');
        $this->assertFalse($v->verify($this->envelope([], null, 'unknown-kid')), 'a key id we do not hold');

        $tampered = $this->envelope();
        $tampered['document']['state'] = 'paid';
        $tampered['document']['plan_code'] = 'REMOTE_ENTERPRISE';
        $this->assertFalse($v->verify($tampered), 'any edited field');

        $reordered = $this->envelope();
        $reordered['document'] = array_reverse($reordered['document'], true);
        $this->assertTrue($v->verify($reordered), 'key order is not part of the signature');

        $this->assertFalse((new EntitlementVerifier([]))->verify($this->envelope()), 'no key configured verifies nothing');
        $this->assertFalse($v->verify(['document' => $this->doc()]), 'unsigned');
    }

    public function testPublicKeysAreReadFromTheEnvironmentAndMalformedOnesIgnored(): void
    {
        $env = ['WEBRTC_LICENCE_PUBLIC_KEYS' => 'good=' . base64_encode($this->publicKey) . ', short=' . base64_encode('abc') . ',nokey,bad kid=' . base64_encode($this->publicKey)];
        $v   = EntitlementVerifier::fromEnvironment(static fn (string $k): ?string => $env[$k] ?? null);

        $this->assertSame(['good'], $v->keyIds());
    }

    // ---- resolution ------------------------------------------------------------------------------------------

    public function testAValidSignedFreshPaidDocumentIsPaid(): void
    {
        $r = $this->resolver($this->cacheWith($this->envelope()))->forCustomer('42', 'remote');

        $this->assertSame(LicenceState::Paid, $r->state);
        $this->assertSame(Tier::Paid, $r->tier());
        $this->assertSame('central', $r->evidence);
        $this->assertSame('REMOTE_BUSINESS', $r->planCode);
        $this->assertArrayNotHasKey('customer', $r->audit());
        $this->assertStringNotContainsString('42', json_encode($r->audit()), 'no customer id in the audit data');
    }

    public function testEveryStateButPaidIsFreeCapacity(): void
    {
        $cases = [
            'free'      => [['state' => 'free', 'licence_id' => null, 'plan_code' => null, 'plan_class' => 'free', 'kind' => 'free', 'valid_until' => null], LicenceState::Free],
            'expired'   => [['state' => 'expired'], LicenceState::Expired],
            'suspended' => [['state' => 'suspended'], LicenceState::Suspended],
            'unknown'   => [['state' => 'unknown'], LicenceState::Unknown],
        ];
        foreach ($cases as $label => [$over, $state]) {
            $r = $this->resolver($this->cacheWith($this->envelope($over)))->forCustomer('42', 'remote');
            $this->assertSame($state, $r->state, $label);
            $this->assertSame(Tier::Free, $r->tier(), $label);
        }
    }

    public function testEveryDoubtResolvesToFreeNotPaid(): void
    {
        $paid = $this->envelope();
        $now  = self::NOW;
        $doubts = [
            'nothing cached'        => [new MemoryEntitlementCache(), $now, LicenceState::Unknown, 'no_entitlement'],
            'tampered row'          => [$this->cacheWith(['document' => ['plan_code' => 'X'] + $paid['document'], 'signature' => $paid['signature']]), $now, LicenceState::Unknown, 'signature_invalid'],
            'signed by someone else'=> [$this->cacheWith($this->envelope([], $this->otherSecretKey)), $now, LicenceState::Unknown, 'signature_invalid'],
            'stale'                 => [$this->cacheWith($paid), $now + 172800 + 601, LicenceState::Unknown, 'stale'],
            'issued in the future'  => [$this->cacheWith($this->envelope(['issued_at' => self::iso($now + 3600)])), $now, LicenceState::Unknown, 'issued_in_future'],
            'not yet valid'         => [$this->cacheWith($this->envelope(['valid_from' => self::iso($now + 3600)])), $now, LicenceState::Unknown, 'not_yet_valid'],
            'valid_until passed'    => [$this->cacheWith($paid), $now + 31 * 86400, LicenceState::Expired, 'stale'],
        ];
        foreach ($doubts as $label => [$cache, $at, $state, $reason]) {
            $r = $this->resolver($cache, null, $at)->forCustomer('42', 'remote');
            $this->assertSame(Tier::Free, $r->tier(), $label);
            $this->assertNotSame(LicenceState::Paid, $r->state, $label);
            $this->assertSame($reason, $r->reason, $label);
        }

        // Within the freshness window, a document whose own valid_until has passed is EXPIRED, not merely stale.
        $short = $this->envelope(['valid_until' => self::iso($now - 60), 'valid_from' => self::iso($now - 86400)]);
        $r     = $this->resolver($this->cacheWith($short))->forCustomer('42', 'remote');
        $this->assertSame([LicenceState::Expired, 'valid_until_passed'], [$r->state, $r->reason]);
    }

    public function testTenantIsolation(): void
    {
        // A signed paid document for customer 99 filed under customer 42, or for another product, is not 42's.
        $other = $this->envelope(['customer_id' => '99']);
        $this->assertSame('identity_mismatch', $this->resolver($this->cacheWith($other))->forCustomer('42', 'remote')->reason);

        $chat = $this->envelope(['product_id' => 'chat']);
        $this->assertSame('identity_mismatch', $this->resolver($this->cacheWith($chat))->forCustomer('42', 'remote')->reason);

        // A subject-scoped document does not answer a customer lookup, and the reverse.
        $sub = $this->envelope(['subject' => 'u-7']);
        $this->assertSame('identity_mismatch', $this->resolver($this->cacheWith($sub))->forCustomer('42', 'remote')->reason);
        $this->assertSame(LicenceState::Paid, $this->resolver($this->cacheWith($sub, 'subject', 'u-7'))->forSubject('u-7', 'remote')->state);
        $this->assertSame('identity_mismatch', $this->resolver($this->cacheWith($this->envelope(), 'subject', 'u-7'))->forSubject('u-7', 'remote')->reason);
        $this->assertSame('no_entitlement', $this->resolver($this->cacheWith($sub, 'subject', 'u-7'))->forSubject('u-8', 'remote')->reason);
    }

    public function testRevocationBeatsAValidLookingDocumentUntilANewerOneArrives(): void
    {
        $cache = $this->cacheWith($this->envelope(['revision' => 5]));
        $this->assertSame(LicenceState::Paid, $this->resolver($cache)->forCustomer('42', 'remote')->state);

        $cache->revoke('42', 'remote', 5, self::NOW);
        $r = $this->resolver($cache)->forCustomer('42', 'remote');
        $this->assertSame([LicenceState::Suspended, 'revoked', Tier::Free], [$r->state, $r->reason, $r->tier()]);

        // A replay of the old revision cannot undo it, and cannot even replace a newer document.
        $cache->put('customer', '42', 'remote', $this->envelope(['revision' => 4]), self::NOW);
        $this->assertSame('revoked', $this->resolver($cache)->forCustomer('42', 'remote')->reason);

        $cache->put('customer', '42', 'remote', $this->envelope(['revision' => 6]), self::NOW);
        $this->assertSame(LicenceState::Paid, $this->resolver($cache)->forCustomer('42', 'remote')->state, 'a newer central document lifts it');

        $cache->put('customer', '42', 'remote', $this->envelope(['revision' => 2, 'state' => 'suspended']), self::NOW);
        $this->assertSame(LicenceState::Paid, $this->resolver($cache)->forCustomer('42', 'remote')->state, 'an older document never replaces a newer');
    }

    public function testTrialAndComplimentaryAreNotPaidCapacityUnlessThePolicySaysSo(): void
    {
        $trial = $this->envelope(['kind' => 'trial']);
        $r     = $this->resolver($this->cacheWith($trial))->forCustomer('42', 'remote');
        $this->assertSame([LicenceState::Free, 'kind_not_paid', Tier::Free], [$r->state, $r->reason, $r->tier()]);

        $widened = new LicencePolicy(172800, ['paid', 'trial'], false, true);
        $this->assertSame(Tier::Paid, $this->resolver($this->cacheWith($trial), $widened)->forCustomer('42', 'remote')->tier());
    }

    public function testLegacyCompatStandsInOnlyWhereCentralSaidNothingAndOnlyWhileAllowed(): void
    {
        $legacyPaid = static fn (): \Aicountly\Ice\Licence\Resolution => LicenceResolver::compat(LicenceState::Paid, 'legacy_plan', 'REMOTE_BUSINESS');
        $allow = new LicencePolicy(172800, ['paid'], true, true);

        $r = $this->resolver(new MemoryEntitlementCache(), $allow)->forCustomer('42', 'remote', $legacyPaid);
        $this->assertSame([LicenceState::Paid, 'compat'], [$r->state, $r->evidence]);

        $deny = $this->resolver(new MemoryEntitlementCache())->forCustomer('42', 'remote', $legacyPaid);
        $this->assertSame([LicenceState::Unknown, Tier::Free], [$deny->state, $deny->tier()], 'compat denied: central silence is unknown');

        // Central evidence — even unfavourable or stale — is never overridden by a legacy "paid".
        $suspended = $this->cacheWith($this->envelope(['state' => 'suspended']));
        $this->assertSame(LicenceState::Suspended, $this->resolver($suspended, $allow)->forCustomer('42', 'remote', $legacyPaid)->state);
        $stale = $this->resolver($this->cacheWith($this->envelope()), $allow, self::NOW + 10 * 86400)->forCustomer('42', 'remote', $legacyPaid);
        $this->assertSame([LicenceState::Unknown, Tier::Free], [$stale->state, $stale->tier()]);

        // A failing legacy lookup is no answer, not an error.
        $boom = $this->resolver(new MemoryEntitlementCache(), $allow)->forCustomer('42', 'remote', static function (): never {
            throw new \RuntimeException('db down');
        });
        $this->assertSame(Tier::Free, $boom->tier());
    }

    public function testWithoutLibsodiumOrKeysNothingIsEverCentralPaid(): void
    {
        $r = (new LicenceResolver($this->cacheWith($this->envelope()), new EntitlementVerifier([]), new LicencePolicy(), static fn (): int => self::NOW))->forCustomer('42', 'remote');
        $this->assertSame([LicenceState::Unknown, 'verifier_unavailable', Tier::Free], [$r->state, $r->reason, $r->tier()]);
    }

    // ---- central client and sync -----------------------------------------------------------------------------

    /** @return array{0: CentralClient, 1: \ArrayObject<int, array<string, mixed>>} */
    private function client(\Closure $respond): array
    {
        $seen   = new \ArrayObject();
        $client = new CentralClient('https://licence.example.test/api', 'remote', 'remote-read-2026-10', str_repeat('s', 40), 'sandbox', function (string $url, array $headers, int $t) use ($respond, $seen): array {
            $seen[] = ['url' => $url, 'headers' => $headers];

            return $respond($url);
        });

        return [$client, $seen];
    }

    private static function ok(mixed $data, array $page = []): array
    {
        return ['status' => 200, 'body' => json_encode(['success' => true, 'data' => $data, 'page' => $page])];
    }

    public function testRequestsAreAicS2sSignedForThisProductAndCarryNoBody(): void
    {
        [$client, $seen] = $this->client(fn () => self::ok($this->envelope()));
        $client->customer('42', 'remote');

        $h = $seen[0]['headers'];
        $this->assertSame('https://licence.example.test/api/v1/entitlements/42?product_id=remote', $seen[0]['url']);
        $this->assertSame(['remote', 'remote-read-2026-10', 'sandbox'], [$h['X-AIC-Client'], $h['X-AIC-Key-Id'], $h['X-AIC-Environment']]);
        $expected = AicS2s::signature(str_repeat('s', 40), AicS2s::canonical('GET', '/v1/entitlements/42', 'product_id=remote', $h['X-AIC-Timestamp'], $h['X-AIC-Nonce'], ''));
        $this->assertSame($expected, $h['X-AIC-Signature'], 'what is signed is exactly the path and query sent');
        $this->assertStringNotContainsString(str_repeat('s', 40), json_encode($seen[0]));
    }

    public function testTheClientRefusesInsecureOrIncompleteConfiguration(): void
    {
        $env = static fn (array $over): \Closure => static fn (string $k): ?string => ($over + [
            'WEBRTC_LICENCE_API_URL' => 'https://licence.example.test/api', 'WEBRTC_LICENCE_S2S_SECRET' => str_repeat('s', 40),
            'WEBRTC_LICENCE_KEY_ID' => 'k', 'WEBRTC_LICENCE_ENVIRONMENT' => 'sandbox',
        ])[$k] ?? null;

        $this->assertNotNull(CentralClient::fromEnvironment($env([]), 'remote'));
        foreach ([['WEBRTC_LICENCE_API_URL' => 'http://licence.example.test/api'], ['WEBRTC_LICENCE_API_URL' => ''], ['WEBRTC_LICENCE_S2S_SECRET' => 'short'], ['WEBRTC_LICENCE_KEY_ID' => ''], ['WEBRTC_LICENCE_ENVIRONMENT' => 'prod']] as $over) {
            $this->assertNull(CentralClient::fromEnvironment($env($over), 'remote'), json_encode($over));
        }
    }

    public function testAnyBackendFailureIsAnUnavailableNotAnAnswer(): void
    {
        foreach ([['status' => 500, 'body' => ''], ['status' => 200, 'body' => 'not json'], ['status' => 200, 'body' => '{"success":false}'], ['status' => 404, 'body' => '{"success":true,"data":[]}']] as $bad) {
            [$client] = $this->client(fn () => $bad);
            try {
                $client->customer('42', 'remote');
                $this->fail('must throw');
            } catch (CentralUnavailable $e) {
                $this->assertStringNotContainsString('sssss', $e->getMessage());
            }
        }
        [$client] = $this->client(static function (): never {
            throw new \RuntimeException('connection refused to https://licence.example.test with secret');
        });
        $this->expectException(CentralUnavailable::class);
        $client->customer('42', 'remote');
    }

    public function testSyncStoresOnlyVerifiedEnvelopesForThisProductAndAdvancesTheHeartbeatOnlyAfterAFullPass(): void
    {
        $good   = $this->envelope(['customer_id' => '1']);
        $forged = $this->envelope(['customer_id' => '2'], $this->otherSecretKey);
        $chat   = $this->envelope(['customer_id' => '3', 'product_id' => 'chat']);
        $junk   = ['document' => ['contract' => 'nope'], 'signature' => []];

        [$client] = $this->client(function (string $url) use ($good, $forged, $chat, $junk): array {
            if (str_contains($url, '/changes')) {
                return self::ok([], ['next_seq' => 9]);
            }

            return self::ok([$good, $forged, $chat, $junk], ['next_cursor' => null, 'as_of_seq' => 9]);
        });
        $cache = new MemoryEntitlementCache();
        $t     = self::NOW;
        $stats = (new EntitlementSync($client, $cache, $this->verifier(), 'remote', static fn (): int => $t))->run();

        $this->assertSame(['full' => true, 'stored' => 1, 'rejected' => 3, 'revoked' => 0, 'cursor' => 9], $stats);
        $this->assertNotNull($cache->get('customer', '1', 'remote'));
        $this->assertNull($cache->get('customer', '2', 'remote'), 'a forged envelope is never stored');
        $this->assertNull($cache->get('customer', '3', 'remote'), 'another product\'s envelope is never stored');
        $this->assertSame($t, $cache->syncState('remote')['lastSyncAt']);

        // The backend goes away: nothing changes, and the heartbeat stays where it was.
        [$down] = $this->client(fn () => ['status' => 503, 'body' => '']);
        try {
            (new EntitlementSync($down, $cache, $this->verifier(), 'remote', static fn (): int => $t + 500))->run();
            $this->fail('must throw');
        } catch (CentralUnavailable) {
            $this->assertSame($t, $cache->syncState('remote')['lastSyncAt']);
        }
    }

    public function testTheChangesFeedRevokesBeforeItRefetches(): void
    {
        $cache = $this->cacheWith($this->envelope(['revision' => 5]), 'customer', '42');
        $cache->saveSyncState('remote', 9, self::NOW - 100, true);

        // The feed says "42 revoked at revision 5", then the refetch FAILS. The customer must already be off the paid reserve.
        [$client] = $this->client(function (string $url): array {
            if (str_contains($url, '/changes')) {
                return self::ok([['seq' => 10, 'customer_id' => '42', 'product_id' => 'remote', 'revision' => 5, 'revoked' => true]], ['next_seq' => 10]);
            }

            return ['status' => 503, 'body' => ''];
        });
        try {
            (new EntitlementSync($client, $cache, $this->verifier(), 'remote', static fn (): int => self::NOW))->run();
            $this->fail('the refetch failed');
        } catch (CentralUnavailable) {
        }
        $this->assertSame(LicenceState::Suspended, $this->resolver($cache)->forCustomer('42', 'remote')->state);
        $this->assertSame(9, $cache->syncState('remote')['cursor'], 'the cursor did not advance past a change that was not fully applied');

        // Next run the refetch works and returns the suspended document at a newer revision: still not paid, and now explained by the document.
        [$ok] = $this->client(function (string $url): array {
            return str_contains($url, '/changes')
                ? (str_contains($url, 'since=9') ? self::ok([['seq' => 10, 'customer_id' => '42', 'product_id' => 'remote', 'revision' => 5, 'revoked' => true]], ['next_seq' => 10]) : self::ok([], ['next_seq' => 10]))
                : self::ok($this->envelope(['revision' => 6, 'state' => 'suspended']));
        });
        $stats = (new EntitlementSync($ok, $cache, $this->verifier(), 'remote', static fn (): int => self::NOW))->run();
        $this->assertSame([1, 1, 10], [$stats['stored'], $stats['revoked'], $stats['cursor']]);
        $this->assertSame('suspended', $this->resolver($cache)->forCustomer('42', 'remote')->reason);
    }

    // ---- readiness -------------------------------------------------------------------------------------------

    public function testReadinessNamesEveryGapAndIsEmptyOnlyWhenTheCentralSourceIsReal(): void
    {
        $envFull = [
            'WEBRTC_LICENCE_API_URL' => 'https://licence.example.test/api', 'WEBRTC_LICENCE_S2S_SECRET' => str_repeat('s', 40), 'WEBRTC_LICENCE_KEY_ID' => 'k',
            'WEBRTC_LICENCE_ENVIRONMENT' => 'sandbox', 'WEBRTC_TURN_LICENCE_FEED' => 'confirmed',
        ];
        $read = static fn (array $env): \Closure => static fn (string $k): ?string => $env[$k] ?? null;
        $cache = new MemoryEntitlementCache();
        $cache->saveSyncState('remote', 3, self::NOW - 60, true);
        $strict = new LicencePolicy(172800, ['paid'], false, true);

        $this->assertSame([], LicenceReadiness::blockers($read($envFull), $cache, $this->verifier(), $strict, 'remote', self::NOW));

        $none = LicenceReadiness::blockers($read([]), new MemoryEntitlementCache(), new EntitlementVerifier([]), new LicencePolicy(), 'remote', self::NOW);
        $this->assertCount(6, $none, implode("\n", $none));

        $stopped = LicenceReadiness::blockers($read($envFull), $cache, $this->verifier(), $strict, 'remote', self::NOW + 172800 + 61);
        $this->assertCount(1, $stopped);
        $this->assertStringContainsString('stopped', $stopped[0]);

        $unattested = LicenceReadiness::blockers($read(['WEBRTC_TURN_LICENCE_FEED' => 'yes'] + $envFull), $cache, $this->verifier(), $strict, 'remote', self::NOW);
        $this->assertStringContainsString('attested', $unattested[0]);

        $compat = LicenceReadiness::blockers($read($envFull), $cache, $this->verifier(), new LicencePolicy(172800, ['paid'], true, true), 'remote', self::NOW);
        $this->assertStringContainsString('legacy compatibility', $compat[0]);
    }

    // ---- caches ----------------------------------------------------------------------------------------------

    /** @return array<string, array{0: \Closure(): EntitlementCache}> */
    public static function caches(): array
    {
        $dir = sys_get_temp_dir() . '/ice-licence-' . bin2hex(random_bytes(4));

        $out = [
            'memory' => [static fn (): EntitlementCache => new MemoryEntitlementCache()],
            'file'   => [static fn (): EntitlementCache => new FileEntitlementCache($dir . '/cache.json')],
        ];
        if (is_string(getenv('ICE_KIT_TEST_PG_DSN')) && getenv('ICE_KIT_TEST_PG_DSN') !== '') {
            $out['postgres'] = [static function (): EntitlementCache {
                $c = new LedgerConnection((string) getenv('ICE_KIT_TEST_PG_DSN'), (string) getenv('ICE_KIT_TEST_PG_USER'), (string) getenv('ICE_KIT_TEST_PG_PASSWORD'));
                $c->pdo()->exec((string) file_get_contents(dirname(__DIR__) . '/sql/turn_capacity.sql'));
                $c->pdo()->exec('TRUNCATE aic_entitlement_cache, aic_entitlement_revoked, aic_entitlement_sync');

                return new PdoEntitlementCache($c);
            }];
        }

        return $out;
    }

    /** @dataProvider caches */
    #[\PHPUnit\Framework\Attributes\DataProvider('caches')]
    public function testEveryCacheKeepsTheSameRules(\Closure $make): void
    {
        $cache = $make();
        $this->assertNull($cache->get('customer', '42', 'remote'));

        $cache->put('customer', '42', 'remote', $this->envelope(['revision' => 5]), 100);
        $cache->put('customer', '42', 'remote', $this->envelope(['revision' => 3]), 200);
        $got = $cache->get('customer', '42', 'remote');
        $this->assertSame(5, $got['envelope']['document']['revision'], 'an older revision never replaces a newer');
        $this->assertSame(100, $got['fetchedAt']);
        $this->assertTrue($this->verifier()->verify($got['envelope']), 'the envelope survives storage byte for byte');

        $this->assertNull($cache->get('customer', '42', 'chat'), 'keys carry the product');
        $this->assertNull($cache->get('subject', '42', 'remote'), 'and the scope');

        $this->assertSame(-1, $cache->revokedRevision('42', 'remote'));
        $cache->revoke('42', 'remote', 7, 100);
        $cache->revoke('42', 'remote', 4, 100);
        $this->assertSame(7, $cache->revokedRevision('42', 'remote'));
        $this->assertSame(-1, $cache->revokedRevision('42', 'chat'));

        $this->assertSame(['cursor' => 0, 'lastSyncAt' => null, 'lastFullAt' => null], $cache->syncState('remote'));
        $cache->saveSyncState('remote', 12, 300, true);
        $cache->saveSyncState('remote', 15, 400, false);
        $this->assertSame(['cursor' => 15, 'lastSyncAt' => 400, 'lastFullAt' => 300], $cache->syncState('remote'));
    }
}
