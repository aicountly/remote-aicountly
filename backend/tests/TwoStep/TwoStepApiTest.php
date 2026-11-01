<?php

declare(strict_types=1);

namespace Tests\TwoStep;

use App\Domain\Audit\EventType;
use App\Domain\Auth\RemoteIdentity;
use App\Domain\TwoStep\SecretCipher;
use App\Domain\TwoStep\Totp;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Remote as RemoteConfig;
use Config\Services;
use Tests\Support\DeviceApiRequests;
use Tests\Support\RemoteTestCase;

/**
 * Remote's own two-step verification, over HTTP: the whole of `me/two-step`,
 * and the rule that a person who has it on gets nothing but the two-step
 * routes and bootstrap until this browser has passed it.
 *
 * Through the real router, filters and controllers; only the portal round trip
 * is stood in for (FakeIdentityResolver), as everywhere else.
 *
 * @internal
 */
final class TwoStepApiTest extends RemoteTestCase
{
    use FeatureTestTrait;
    use DeviceApiRequests;

    /** @param array<string, string> $headers */
    private function withToken(array $headers, ?string $token): array
    {
        unset($headers['X-Remote-Two-Step']);

        return $token === null ? $headers : $headers + ['X-Remote-Two-Step' => $token];
    }

    /** @return array<string, mixed> */
    private function body(TestResponse $response): array
    {
        return json_decode((string) $response->getJSON(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** As if the next code were shown 30 seconds later: the last accepted step is forgotten. */
    private function waitForTheNextCode(RemoteIdentity $identity): void
    {
        $this->db->table('remote_user_two_step')->where('user_id', $identity->id)->update(['last_used_step' => null]);
    }

    // ------------------------------------------------------------- the flow

    public function testEnrolConfirmVerifyAndDisableThroughTheApi(): void
    {
        $meera   = $this->makeIdentity('Meera', 'meera@example.test');
        $headers = $this->asPerson($meera);

        $status = $this->dataOf($this->getJson($headers, 'me/two-step'));
        $this->assertSame(
            ['enabled' => false, 'pending' => false, 'available' => true, 'confirmedAt' => null, 'recoveryCodesLeft' => 0,
             'sessionVerified' => false, 'verifiedAt' => null, 'verifiedUntil' => null, 'lockedUntil' => null],
            $status,
        );

        // Start: a secret to type and the URI to scan, labelled with the e-mail.
        $enrol = $this->dataOf($this->postJson($headers, 'me/two-step/enrol'), 201);
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $enrol['secret']);
        $this->assertStringStartsWith('otpauth://totp/AICOUNTLY%20Remote:meera%40example.test?secret=' . $enrol['secret'], $enrol['otpauthUri']);
        $this->assertStringContainsString('issuer=AICOUNTLY%20Remote', $enrol['otpauthUri']);
        $this->assertTrue($this->dataOf($this->getJson($headers, 'me/two-step'))['pending']);
        $this->assertFalse($this->dataOf($this->getJson($headers, 'me/two-step'))['enabled'], 'pending is not on');

        // Confirm with the code the app shows: ten recovery codes, once, and this browser is verified.
        $this->assertSame('TWO_STEP_CODE_INVALID', $this->errorOf($this->postJson($headers, 'me/two-step/confirm', ['code' => '000000' === $this->totpCode($enrol['secret']) ? '111111' : '000000']), 400));
        $confirmed = $this->dataOf($this->postJson($headers, 'me/two-step/confirm', ['code' => $this->totpCode($enrol['secret'])]));
        $this->assertCount(10, $confirmed['recoveryCodes']);
        $this->assertCount(10, array_unique($confirmed['recoveryCodes']));
        $this->assertMatchesRegularExpression('/^[2-9A-HJ-NP-Z]{5}-[2-9A-HJ-NP-Z]{5}$/', $confirmed['recoveryCodes'][0]);
        $this->assertNotEmpty($confirmed['verificationToken']);
        $this->assertSame(43200, strtotime($confirmed['verifiedUntil']) - strtotime($confirmed['verifiedAt']), 'twelve hours');
        $this->assertHasAudit(EventType::TWO_STEP_ENROLMENT_STARTED);
        $this->assertHasAudit(EventType::TWO_STEP_ENABLED);

        $verified = $this->withToken($headers, $confirmed['verificationToken']);
        $status   = $this->dataOf($this->getJson($verified, 'me/two-step'));
        $this->assertTrue($status['enabled']);
        $this->assertTrue($status['sessionVerified']);
        $this->assertSame(10, $status['recoveryCodesLeft']);
        $this->assertNotNull($status['confirmedAt']);
        $this->assertSame($confirmed['verifiedUntil'], $status['verifiedUntil']);

        // Another browser — no token — is asked, and gets nothing else.
        $this->assertSame('TWO_STEP_REQUIRED', $this->errorOf($this->getJson($headers, 'sessions/history'), 403));
        $this->assertFalse($this->dataOf($this->getJson($headers, 'me/two-step'))['sessionVerified']);

        // It verifies with the next code, and its token works; the first browser's still does too.
        $this->waitForTheNextCode($meera);
        $passed = $this->dataOf($this->postJson($headers, 'me/two-step/verify', ['code' => $this->totpCode($enrol['secret'])]));
        $this->assertSame('TOTP', $passed['method']);
        $this->assertSame(10, $passed['recoveryCodesLeft']);
        $this->assertNotSame($confirmed['verificationToken'], $passed['verificationToken']);
        $this->dataOf($this->getJson($this->withToken($headers, $passed['verificationToken']), 'sessions/history'));
        $this->dataOf($this->getJson($verified, 'sessions/history'));
        $this->assertHasAudit(EventType::TWO_STEP_VERIFIED);

        // Off — with a code, never without one — and everything that hung off it goes.
        $this->assertSame('VALIDATION_FAILED', $this->errorOf($this->postJson($verified, 'me/two-step/disable'), 400));
        $this->waitForTheNextCode($meera);
        $off = $this->dataOf($this->postJson($verified, 'me/two-step/disable', ['code' => $this->totpCode($enrol['secret'])]));
        $this->assertFalse($off['enabled']);
        $this->assertFalse($off['sessionVerified']);
        $this->assertHasAudit(EventType::TWO_STEP_DISABLED);
        foreach (['remote_user_two_step', 'remote_user_recovery_codes', 'remote_two_step_verifications'] as $table) {
            $this->assertSame(0, $this->db->table($table)->where('user_id', $meera->id)->countAllResults(), $table);
        }

        // And an ordinary route answers without a token again.
        $this->dataOf($this->getJson($headers, 'sessions/history'));
    }

    public function testTheSecretIsSealedAtRestAndNoAuditRowCarriesASecretOrACode(): void
    {
        $meera  = $this->makeIdentity('Meera');
        $result = $this->turnOnTwoStep($meera);

        $row = $this->db->table('remote_user_two_step')->where('user_id', $meera->id)->get()->getRowArray();
        $raw = (string) Totp::base32Decode($result['secret']);

        $this->assertStringNotContainsString($result['secret'], (string) $row['secret_ciphertext']);
        $this->assertStringNotContainsString(bin2hex($raw), bin2hex((string) base64_decode((string) $row['secret_ciphertext'], true)));
        $this->assertSame((new SecretCipher(Services::remoteConfig()))->currentKeyId(), $row['key_id']);

        foreach ($this->db->table('remote_user_recovery_codes')->where('user_id', $meera->id)->get()->getResultArray() as $code) {
            $this->assertStringStartsWith('$', (string) $code['code_hash'], 'a password_hash() value, not the code');
        }
        $this->assertSame(0, $this->db->table('remote_two_step_verifications')->where('token_hash', $result['token'])->countAllResults(), 'the token is not stored, only its hash');
        $this->assertSame(1, $this->db->table('remote_two_step_verifications')->where('token_hash', hash('sha256', $result['token']))->countAllResults());

        $trail = json_encode($this->db->table('remote_audit_logs')->get()->getResultArray());
        $this->assertStringNotContainsString($result['secret'], (string) $trail);
        $this->assertStringNotContainsString($result['token'], (string) $trail);
        $this->assertStringNotContainsString(str_replace('-', '', $result['recoveryCodes'][0]), str_replace('-', '', (string) $trail));
    }

    public function testRestartingAPendingEnrolmentReplacesItAndAConfirmedOneCannotBeReplaced(): void
    {
        $meera   = $this->makeIdentity('Meera');
        $headers = $this->asPerson($meera);

        $first  = $this->dataOf($this->postJson($headers, 'me/two-step/enrol'), 201);
        $second = $this->dataOf($this->postJson($headers, 'me/two-step/enrol'), 201);
        $this->assertNotSame($first['secret'], $second['secret']);
        $this->assertSame(1, $this->db->table('remote_user_two_step')->where('user_id', $meera->id)->countAllResults());

        // The secret scanned first is no longer the one being confirmed.
        $this->assertSame('TWO_STEP_CODE_INVALID', $this->errorOf($this->postJson($headers, 'me/two-step/confirm', ['code' => $this->totpCode($first['secret'])]), 400));
        $token = $this->dataOf($this->postJson($headers, 'me/two-step/confirm', ['code' => $this->totpCode($second['secret'])]))['verificationToken'];

        $this->assertSame('TWO_STEP_ALREADY_ENABLED', $this->errorOf($this->postJson($this->withToken($headers, $token), 'me/two-step/enrol'), 409));
        $this->assertSame('TWO_STEP_ALREADY_ENABLED', $this->errorOf($this->postJson($headers, 'me/two-step/enrol'), 409), 'nor by a browser that has not passed it');
        $this->assertSame('TWO_STEP_ALREADY_ENABLED', $this->errorOf($this->postJson($headers, 'me/two-step/confirm', ['code' => '123456']), 409));
    }

    public function testConfirmingBeforeStartingSaysSo(): void
    {
        $meera = $this->makeIdentity('Meera');

        $this->assertSame('TWO_STEP_NOT_STARTED', $this->errorOf($this->postJson($this->asPerson($meera), 'me/two-step/confirm', ['code' => '123456']), 409));
        $this->assertSame('TWO_STEP_NOT_ENABLED', $this->errorOf($this->postJson($this->asPerson($meera), 'me/two-step/verify', ['code' => '123456']), 409));
        $this->assertSame('TWO_STEP_NOT_ENABLED', $this->errorOf($this->postJson($this->asPerson($meera), 'me/two-step/disable', ['code' => '123456']), 409));
    }

    public function testWithNoKeyEnrolmentIsRefusedWith503AndNothingIsStored(): void
    {
        $this->configureRemote(static function (RemoteConfig $config): void {
            $config->twoStepKey       = '';
            $config->signallingSecret = '';
        });
        $meera = $this->makeIdentity('Meera');

        $response = $this->postJson($this->asPerson($meera), 'me/two-step/enrol');
        $this->assertSame('TWO_STEP_UNAVAILABLE', $this->errorOf($response, 503));
        $this->assertStringContainsString('no key to protect it with', (string) $this->body($response)['error']['message']);
        $this->assertSame(0, $this->db->table('remote_user_two_step')->countAllResults(), 'never stored unsealed instead');
        $this->assertFalse($this->dataOf($this->getJson($this->asPerson($meera), 'me/two-step'))['available']);
    }

    public function testTheKeyIsDerivedFromTheSignallingSecretWhenNoDedicatedKeyIsSet(): void
    {
        $this->configureRemote(static function (RemoteConfig $config): void {
            $config->twoStepKey       = '';
            $config->signallingSecret = 'a-long-random-signalling-secret-0123456789abcdef';
        });
        $meera = $this->makeIdentity('Meera');

        $this->dataOf($this->postJson($this->asPerson($meera), 'me/two-step/enrol'), 201);

        $this->assertSame(
            SecretCipher::keyId(hash_hkdf('sha256', 'a-long-random-signalling-secret-0123456789abcdef', 32, 'remote-two-step-v1', 'aicountly-remote')),
            $this->db->table('remote_user_two_step')->where('user_id', $meera->id)->get()->getRow()->key_id,
        );
    }

    // ----------------------------------------------------------- refusals

    public function testAReplayedCodeIsRefusedAndNotCountedAsAWrongOne(): void
    {
        $meera   = $this->makeIdentity('Meera');
        $secret  = $this->turnOnTwoStep($meera)['secret'];
        $headers = $this->withToken($this->asPerson($meera), null);

        // The code that confirmed it was accepted once; it is not accepted again.
        $replay = $this->postJson($headers, 'me/two-step/verify', ['code' => $this->totpCode($secret)]);
        $this->assertSame('TWO_STEP_CODE_USED', $this->errorOf($replay, 409));
        $this->assertStringContainsString('Wait for your authenticator app to show the next one', (string) $this->body($replay)['error']['message']);

        // Nor is an older one from the window.
        $this->assertSame('TWO_STEP_CODE_USED', $this->errorOf($this->postJson($headers, 'me/two-step/verify', ['code' => $this->totpCode($secret, -1)]), 409));
        $this->assertSame(0, (int) $this->db->table('remote_user_two_step')->where('user_id', $meera->id)->get()->getRow()->failed_attempts);

        // The next code is.
        $this->dataOf($this->postJson($headers, 'me/two-step/verify', ['code' => $this->totpCode($secret, 1)]));
        $this->assertSame('TWO_STEP_CODE_USED', $this->errorOf($this->postJson($headers, 'me/two-step/verify', ['code' => $this->totpCode($secret, 1)]), 409));
    }

    public function testFiveWrongCodesLockVerificationForFifteenMinutesWhateverIsTriedThen(): void
    {
        $meera   = $this->makeIdentity('Meera');
        $twoStep = $this->turnOnTwoStep($meera);
        $headers = $this->withToken($this->asPerson($meera), null);
        $wrong   = $this->totpCode($twoStep['secret'], 1) === '000000' ? '999999' : '000000';
        $this->waitForTheNextCode($meera);

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $response = $this->postJson($headers, 'me/two-step/verify', ['code' => $wrong]);
            $this->assertSame('TWO_STEP_CODE_INVALID', $this->errorOf($response, 400));
            $this->assertSame(5 - $attempt, $this->body($response)['error']['details']['attemptsLeft']);
        }

        $locked = $this->postJson($headers, 'me/two-step/verify', ['code' => $wrong]);
        $this->assertSame('TWO_STEP_LOCKED', $this->errorOf($locked, 429));
        $details = $this->body($locked)['error']['details'];
        $this->assertEqualsWithDelta(900, $details['retryAfterSeconds'], 5);
        $this->assertStringContainsString('locked for 15 more minutes', (string) $this->body($locked)['error']['message']);
        $this->assertHasAudit(EventType::TWO_STEP_LOCKED);
        $this->assertSame(5, $this->db->table('remote_audit_logs')->where('event', EventType::TWO_STEP_VERIFY_FAILED)->countAllResults());

        // Locked means locked: the right code and a recovery code are refused too, and not counted.
        $this->assertSame('TWO_STEP_LOCKED', $this->errorOf($this->postJson($headers, 'me/two-step/verify', ['code' => $this->totpCode($twoStep['secret'])]), 429));
        $this->assertSame('TWO_STEP_LOCKED', $this->errorOf($this->postJson($headers, 'me/two-step/verify', ['recoveryCode' => $twoStep['recoveryCodes'][0]]), 429));
        $this->assertNotNull($this->dataOf($this->getJson($headers, 'me/two-step'))['lockedUntil']);

        // Fifteen minutes later, the right code works and the count starts again.
        $this->db->table('remote_user_two_step')->where('user_id', $meera->id)->update(['locked_until' => gmdate('Y-m-d H:i:s', time() - 1) . '+00']);
        $this->dataOf($this->postJson($headers, 'me/two-step/verify', ['code' => $this->totpCode($twoStep['secret'])]));
        $this->assertSame(0, (int) $this->db->table('remote_user_two_step')->where('user_id', $meera->id)->get()->getRow()->failed_attempts);
    }

    public function testARecoveryCodeWorksOnceInAnyCaseAndWithOrWithoutItsDash(): void
    {
        $meera   = $this->makeIdentity('Meera');
        $codes   = $this->turnOnTwoStep($meera)['recoveryCodes'];
        $headers = $this->withToken($this->asPerson($meera), null);

        $typed  = strtolower(str_replace('-', ' ', $codes[3]));
        $passed = $this->dataOf($this->postJson($headers, 'me/two-step/verify', ['recoveryCode' => $typed]));

        $this->assertSame('RECOVERY_CODE', $passed['method']);
        $this->assertSame(9, $passed['recoveryCodesLeft']);
        $this->assertHasAudit(EventType::TWO_STEP_RECOVERY_CODE_USED);
        $this->dataOf($this->getJson($this->withToken($headers, $passed['verificationToken']), 'sessions/history'));

        $again = $this->postJson($headers, 'me/two-step/verify', ['recoveryCode' => $codes[3]]);
        $this->assertSame('TWO_STEP_CODE_INVALID', $this->errorOf($again, 400));
        $this->assertStringContainsString('or it has been used', (string) $this->body($again)['error']['message']);
        $this->assertSame(9, $this->dataOf($this->getJson($this->withToken($headers, $passed['verificationToken']), 'me/two-step'))['recoveryCodesLeft']);

        // Not a code at all is a typing mistake, not a guess: refused, not counted.
        $this->assertSame('VALIDATION_FAILED', $this->errorOf($this->postJson($headers, 'me/two-step/verify', ['recoveryCode' => 'nope']), 400));
        $this->assertSame('VALIDATION_FAILED', $this->errorOf($this->postJson($headers, 'me/two-step/verify', ['code' => '12345', 'recoveryCode' => $codes[4]]), 400));
    }

    public function testNewRecoveryCodesNeedAFreshVerificationAndVoidTheOldOnes(): void
    {
        $meera    = $this->makeIdentity('Meera');
        $twoStep  = $this->turnOnTwoStep($meera);
        $verified = $this->asPerson($meera);

        $fresh = $this->dataOf($this->postJson($verified, 'me/two-step/recovery-codes'))['recoveryCodes'];
        $this->assertCount(10, $fresh);
        $this->assertSame([], array_intersect($fresh, $twoStep['recoveryCodes']));
        $this->assertHasAudit(EventType::TWO_STEP_RECOVERY_CODES_REGENERATED);

        $headers = $this->withToken($verified, null);
        $this->assertSame('TWO_STEP_CODE_INVALID', $this->errorOf($this->postJson($headers, 'me/two-step/verify', ['recoveryCode' => $twoStep['recoveryCodes'][0]]), 400), 'the old set is void');
        $this->dataOf($this->postJson($headers, 'me/two-step/verify', ['recoveryCode' => $fresh[0]]));

        // Eleven minutes after the code was entered, it is not fresh enough any more.
        $this->db->table('remote_two_step_verifications')->update(['verified_at' => gmdate('Y-m-d H:i:s', time() - 660) . '+00']);
        $stale = $this->postJson($verified, 'me/two-step/recovery-codes');
        $this->assertSame('TWO_STEP_REQUIRED', $this->errorOf($stale, 403));
        $this->assertSame('STEP_UP', $this->body($stale)['error']['details']['reason']);
        $this->assertStringContainsString('in the last 10 minutes', (string) $this->body($stale)['error']['message']);

        // And without two-step verification on at all, there is nothing to regenerate.
        $other = $this->makeIdentity('Other');
        $this->assertSame('TWO_STEP_NOT_ENABLED', $this->errorOf($this->postJson($this->asPerson($other), 'me/two-step/recovery-codes'), 409));
    }

    public function testSwitchingOffWithAWrongCodeIsCountedAndChangesNothing(): void
    {
        $meera   = $this->makeIdentity('Meera');
        $secret  = $this->turnOnTwoStep($meera)['secret'];
        $wrong   = $this->totpCode($secret, 1) === '000000' ? '999999' : '000000';
        $this->waitForTheNextCode($meera);

        $this->assertSame('TWO_STEP_CODE_INVALID', $this->errorOf($this->postJson($this->asPerson($meera), 'me/two-step/disable', ['code' => $wrong]), 400));
        $this->assertSame(1, (int) $this->db->table('remote_user_two_step')->where('user_id', $meera->id)->get()->getRow()->failed_attempts);
        $this->assertTrue($this->dataOf($this->getJson($this->asPerson($meera), 'me/two-step'))['enabled']);
    }

    // -------------------------------------------------------- enforcement

    public function testAPersonWithItOnGetsOnlyTheTwoStepRoutesAndBootstrapUntilThisBrowserPassesIt(): void
    {
        $meera   = $this->makeIdentity('Meera');
        $company = $this->makeCompany(481, 'ABC Private Limited');
        $this->grantCompanyAccess($meera, $company);
        $this->makeSession($meera, 'PERSONAL', null, ['issueSummary' => 'The March figures']);
        $token   = $this->turnOnTwoStep($meera)['token'];
        $headers = $this->withToken($this->asPerson($meera), null);

        // Refused: an ordinary read, an ordinary write, a guest-or-user route and an optional one.
        foreach ([
            fn () => $this->getJson($headers, 'sessions/history'),
            fn () => $this->getJson($headers, 'devices'),
            fn () => $this->postJson($headers, 'sessions', ['scopeType' => 'PERSONAL']),
            fn () => $this->postJson($headers, 'join/redeem', ['token' => 'whatever']),
            fn () => $this->postJson($headers, 'desktop-signin/confirm', ['userCode' => 'ABCD-EFGH']),
        ] as $call) {
            $response = $call();
            $this->assertSame('TWO_STEP_REQUIRED', $this->errorOf($response, 403));
            $this->assertSame('SESSION', $this->body($response)['error']['details']['reason']);
            $this->assertStringContainsString('Enter the code from your authenticator app', (string) $this->body($response)['error']['message']);
            $response->assertHeader('X-Content-Type-Options', 'nosniff');
        }

        // Let through: bootstrap — saying it must ask, and nothing of hers — the two-step routes, the sign-in check.
        $locked = $this->dataOf($this->getJson($headers, 'bootstrap'));
        $this->assertSame(['enabled' => true, 'sessionVerified' => false, 'verifiedUntil' => null], $locked['twoStep']);
        $this->assertSame('Meera', $locked['user']['displayName']);
        $this->assertSame([], $locked['companies']);
        $this->assertSame([], $locked['recentSessions']);
        $this->assertSame(0, $locked['metrics']['sessionsThisMonth']);
        $this->assertArrayHasKey('policy', $locked);
        $this->dataOf($this->getJson($headers, 'me/two-step'));
        $this->withHeaders($headers)->get('session')->assertStatus(200);

        // With the token: everything, and bootstrap carries her data and says she has passed.
        $verified = $this->withToken($headers, $token);
        $full     = $this->dataOf($this->getJson($verified, 'bootstrap'));
        $this->assertTrue($full['twoStep']['sessionVerified']);
        $this->assertNotNull($full['twoStep']['verifiedUntil']);
        $this->assertCount(1, $full['companies']);
        $this->assertCount(1, $full['recentSessions']);
        $this->dataOf($this->getJson($verified, 'sessions/history'));

        // A token past its lifetime is no token.
        $this->db->table('remote_two_step_verifications')->update([
            'verified_at' => gmdate('Y-m-d H:i:s', time() - 50000) . '+00',
            'expires_at'  => gmdate('Y-m-d H:i:s', time() - 1) . '+00',
        ]);
        $this->assertSame('TWO_STEP_REQUIRED', $this->errorOf($this->getJson($verified, 'sessions/history'), 403));
        $this->assertFalse($this->dataOf($this->getJson($verified, 'bootstrap'))['twoStep']['sessionVerified']);
    }

    public function testSomebodyWithoutItIsUnaffectedAndBootstrapSaysItIsOff(): void
    {
        $sam     = $this->makeIdentity('Sam');
        $headers = $this->asPerson($sam);

        $this->dataOf($this->getJson($headers, 'sessions/history'));
        $this->assertSame(['enabled' => false, 'sessionVerified' => false, 'verifiedUntil' => null], $this->dataOf($this->getJson($headers, 'bootstrap'))['twoStep']);
    }

    public function testNothingAboutAnotherPersonIsReachable(): void
    {
        $meera = $this->makeIdentity('Meera');
        $ravi  = $this->makeIdentity('Ravi');
        $mine  = $this->turnOnTwoStep($meera);

        // Ravi's own status is his, not hers.
        $this->assertFalse($this->dataOf($this->getJson($this->asPerson($ravi), 'me/two-step'))['enabled']);

        // Her codes do nothing for him: he has no two-step to pass.
        $this->assertSame('TWO_STEP_NOT_ENABLED', $this->errorOf($this->postJson($this->asPerson($ravi), 'me/two-step/verify', ['recoveryCode' => $mine['recoveryCodes'][0]]), 409));

        // With his own on, her verification token is not his, and her recovery codes are not his either.
        $his         = $this->turnOnTwoStep($ravi);
        $raviWithHer = $this->withToken($this->asPerson($ravi), $mine['token']);
        $this->assertSame('TWO_STEP_REQUIRED', $this->errorOf($this->getJson($raviWithHer, 'sessions/history'), 403));
        $this->assertFalse($this->dataOf($this->getJson($raviWithHer, 'me/two-step'))['sessionVerified']);
        $this->assertSame('TWO_STEP_CODE_INVALID', $this->errorOf($this->postJson($raviWithHer, 'me/two-step/verify', ['recoveryCode' => $mine['recoveryCodes'][0]]), 400));

        // His wrong guesses are counted against him alone, and none of it touched her.
        $this->assertSame(1, (int) $this->db->table('remote_user_two_step')->where('user_id', $ravi->id)->get()->getRow()->failed_attempts);
        $this->assertSame(0, (int) $this->db->table('remote_user_two_step')->where('user_id', $meera->id)->get()->getRow()->failed_attempts);
        $this->assertSame(10, $this->dataOf($this->getJson($this->asPerson($meera), 'me/two-step'))['recoveryCodesLeft']);
        $this->assertNotSame($mine['secret'], $his['secret']);
    }

    public function testGuestsAndDevicesAreUnaffected(): void
    {
        $host    = $this->makeIdentity('Host');
        $company = $this->makeCompany(481, 'ABC Private Limited', ['allow_external_guest' => true]);
        $this->setEntitlement($company, ['external_guests' => true]);
        $this->grantCompanyAccess($host, $company, 'COMPANY_ADMIN', true);
        $session    = $this->makeSession($host, 'COMPANY', $company);
        $policy     = Services::policyResolver()->resolve($host, 'COMPANY', $company);
        $invitation = Services::invitationService()->create($session, $host, $policy, 'EXTERNAL_GUEST', 'amit@example.com', null);
        $guest      = Services::joinService()->redeemInvitation($invitation['secret'], null, 'Amit Shah', 'amit@example.com', null, null);
        $computer   = $this->enrolDevice($host, null, ['deviceName' => 'Host laptop']);

        $this->turnOnTwoStep($host);

        // A guest has no account to protect: their token works exactly as before.
        $this->withHeaders(['Authorization' => 'Bearer guest.' . $guest['guestToken']])
            ->get('v1/remote/sessions/' . $session['uuid'])
            ->assertStatus(200);

        // A device is the machine, proven by its own key, not by its owner's browser.
        $this->dataOf($this->getJson($this->asDevice($computer), 'devices/me'));
        $this->dataOf($this->getJson($this->asDevice($computer), 'devices/me/computers'));
    }

    public function testTheBrowserVerificationIsNotTiedToThePortalKeyWhichChangesEveryFewMinutes(): void
    {
        $meera = $this->makeIdentity('Meera');
        $token = $this->turnOnTwoStep($meera)['token'];

        // Two different ses_keys for the same person (a reload, a re-mint): the same token verifies both.
        $resolver = new \Tests\Support\FakeIdentityResolver(Services::portalClient(), $this->db);
        $resolver->register('first-ses-key', $meera);
        $resolver->register('second-ses-key', $meera);
        Services::injectMock('identityResolver', $resolver);

        foreach (['first-ses-key', 'second-ses-key'] as $sesKey) {
            $this->withHeaders(['Authorization' => 'Bearer ' . $sesKey, 'X-Remote-Two-Step' => $token])
                ->get('v1/remote/sessions/history')
                ->assertStatus(200);
        }

        // And without a portal session the token is not even looked at.
        $this->withHeaders(['X-Remote-Two-Step' => $token])->get('v1/remote/sessions/history')->assertStatus(401);
    }

    public function testBeforeTheMigrationHasRunNobodyIsAskedAndNothingFails(): void
    {
        $meera = $this->makeIdentity('Meera');

        // DDL is transactional in PostgreSQL: the table is gone for this block only.
        $this->db->query('BEGIN');
        $this->db->query('ALTER TABLE remote_user_two_step RENAME TO remote_user_two_step_hidden');

        try {
            $standing = Services::twoStep()->standing($meera, null);
        } finally {
            $this->db->query('ROLLBACK');
        }

        $this->assertFalse($standing->enabled);
    }
}
