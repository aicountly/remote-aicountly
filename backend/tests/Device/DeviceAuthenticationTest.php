<?php

declare(strict_types=1);

namespace Tests\Device;

use App\Domain\Audit\EventType;
use App\Domain\Device\DevicePrincipal;
use App\Domain\Device\DeviceSignature;
use App\Domain\Support\ApiException;
use App\Domain\Support\Clock;
use Config\Remote as RemoteConfig;
use Config\Services;
use Tests\Support\RemoteTestCase;

/**
 * Proof of possession: the challenge, the signature, and every replay.
 *
 * @internal
 */
final class DeviceAuthenticationTest extends RemoteTestCase
{
    /** @return array{0: \App\Domain\Auth\RemoteIdentity, 1: array<string, mixed>, 2: string} */
    private function enrolled(int $companyId = 800): array
    {
        $user = $this->makeIdentity('Device Owner');
        $this->makeDesktopCompany($companyId);
        $this->grantCompanyAccess($user, $companyId, 'MEMBER', true);

        ['device' => $device, 'secretKey' => $secretKey] = $this->enrolDevice($user, $companyId);

        return [$user, $device, $secretKey];
    }

    public function testADeviceAuthenticatesWithASignedChallenge(): void
    {
        [, $device, $secretKey] = $this->enrolled();
        $uuid = (string) $device['uuid'];

        $auth      = Services::deviceAuthenticationService();
        $challenge = $auth->challenge($uuid, '203.0.113.9');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $challenge['nonce']);
        $this->assertSame(DeviceSignature::AUDIENCE, $challenge['audience']);

        $result = $auth->verify(
            $uuid,
            $challenge['nonce'],
            $challenge['issuedAt'],
            $this->signChallenge($secretKey, $uuid, $challenge['nonce'], $challenge['issuedAt']),
        );

        $principal = DevicePrincipal::verify(Services::remoteConfig(), $result['token']);

        $this->assertNotNull($principal);
        $this->assertSame($uuid, $principal->deviceUuid);
        $this->assertSame(800, $principal->companyId);
        $this->assertTrue($principal->hasScope(DevicePrincipal::SCOPE_PRESENCE));
        $this->assertTrue($principal->hasScope(DevicePrincipal::SCOPE_SESSION));

        $this->assertHasAudit(EventType::DEVICE_AUTHENTICATED);

        $row = $this->db->table('remote_devices')->where('uuid', $uuid)->get()->getRowArray();
        $this->assertNotNull($row['last_authenticated_at']);
    }

    /**
     * The single-use property. Presenting the same nonce and signature again
     * is exactly the replay this is here to stop.
     */
    public function testAChallengeCannotBeReplayed(): void
    {
        [, $device, $secretKey] = $this->enrolled(801);
        $uuid = (string) $device['uuid'];

        $auth      = Services::deviceAuthenticationService();
        $challenge = $auth->challenge($uuid, null);
        $signature = $this->signChallenge($secretKey, $uuid, $challenge['nonce'], $challenge['issuedAt']);

        $auth->verify($uuid, $challenge['nonce'], $challenge['issuedAt'], $signature);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('could not be verified');

        $auth->verify($uuid, $challenge['nonce'], $challenge['issuedAt'], $signature);
    }

    public function testAnExpiredChallengeIsRefused(): void
    {
        [, $device, $secretKey] = $this->enrolled(802);
        $uuid = (string) $device['uuid'];

        $auth      = Services::deviceAuthenticationService();
        $challenge = $auth->challenge($uuid, null);

        // Age the row rather than the clock: the expiry that matters is the
        // one stored beside the nonce.
        $this->db->table('remote_device_challenges')
            ->where('nonce', $challenge['nonce'])
            ->update(['expires_at' => Clock::in(-10)]);

        $this->expectException(ApiException::class);

        $auth->verify(
            $uuid,
            $challenge['nonce'],
            $challenge['issuedAt'],
            $this->signChallenge($secretKey, $uuid, $challenge['nonce'], $challenge['issuedAt']),
        );
    }

    public function testAWrongSignatureIsRefused(): void
    {
        [, $device] = $this->enrolled(803);
        $uuid = (string) $device['uuid'];

        // A different keypair entirely — the shape is right, the key is not.
        $impostor  = $this->makeDeviceKeypair();
        $auth      = Services::deviceAuthenticationService();
        $challenge = $auth->challenge($uuid, null);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('could not be verified');

        $auth->verify(
            $uuid,
            $challenge['nonce'],
            $challenge['issuedAt'],
            $this->signChallenge($impostor['secretKey'], $uuid, $challenge['nonce'], $challenge['issuedAt']),
        );
    }

    /**
     * A challenge issued for one device must not be answerable by another —
     * the device is named in both the row and the signed payload.
     */
    public function testAChallengeIssuedForOneDeviceCannotBeUsedByAnother(): void
    {
        $user = $this->makeIdentity('Two Machines');
        $company = $this->makeDesktopCompany(804);
        $this->grantCompanyAccess($user, $company, 'MEMBER', true);

        ['device' => $first]  = $this->enrolDevice($user, $company, ['deviceName' => 'First']);
        ['device' => $second, 'secretKey' => $secondKey] = $this->enrolDevice($user, $company, ['deviceName' => 'Second']);

        $auth      = Services::deviceAuthenticationService();
        $challenge = $auth->challenge((string) $first['uuid'], null);

        $this->expectException(ApiException::class);

        // The second device signs the first device's nonce with its own key.
        $auth->verify(
            (string) $second['uuid'],
            $challenge['nonce'],
            $challenge['issuedAt'],
            $this->signChallenge($secondKey, (string) $second['uuid'], $challenge['nonce'], $challenge['issuedAt']),
        );
    }

    public function testARevokedDeviceCannotAuthenticate(): void
    {
        [$user, $device] = $this->enrolled(805);
        $uuid = (string) $device['uuid'];

        Services::deviceService()->revoke($user, $uuid);

        try {
            Services::deviceAuthenticationService()->challenge($uuid, null);
            $this->fail('A revoked device should not receive a challenge.');
        } catch (ApiException $exception) {
            $this->assertSame('DEVICE_NOT_ACTIVE', $exception->errorCode());
        }

        $this->assertHasAudit(EventType::DEVICE_AUTH_FAILED);
    }

    /**
     * Revocation is immediate, not "within the token's lifetime": a credential
     * minted a second before an administrator revoked the device stops working
     * on its very next call, because every device-authenticated request
     * re-reads the row.
     */
    public function testAnUnexpiredTokenStopsWorkingTheMomentTheDeviceIsRevoked(): void
    {
        [$user, $device, $secretKey] = $this->enrolled(806);
        $uuid = (string) $device['uuid'];

        $auth      = Services::deviceAuthenticationService();
        $challenge = $auth->challenge($uuid, null);
        $result    = $auth->verify(
            $uuid,
            $challenge['nonce'],
            $challenge['issuedAt'],
            $this->signChallenge($secretKey, $uuid, $challenge['nonce'], $challenge['issuedAt']),
        );

        $principal = DevicePrincipal::verify(Services::remoteConfig(), $result['token']);
        $this->assertNotNull($principal);

        // Still valid as a token…
        $this->assertGreaterThan(time(), $principal->expiresAt);
        $auth->requireActiveDevice($principal);

        Services::deviceService()->revoke($user, $uuid);

        // …and worthless as a credential.
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('no longer registered');

        $auth->requireActiveDevice($principal);
    }

    public function testATokenSignedWithAnotherSecretIsRefused(): void
    {
        [, $device, $secretKey] = $this->enrolled(807);
        $uuid = (string) $device['uuid'];

        $auth      = Services::deviceAuthenticationService();
        $challenge = $auth->challenge($uuid, null);
        $result    = $auth->verify(
            $uuid,
            $challenge['nonce'],
            $challenge['issuedAt'],
            $this->signChallenge($secretKey, $uuid, $challenge['nonce'], $challenge['issuedAt']),
        );

        $other = new RemoteConfig();
        $other->signallingSecret = 'a-completely-different-secret';

        $this->assertNull(DevicePrincipal::verify($other, $result['token']));
    }

    public function testAnExpiredDeviceTokenIsRefused(): void
    {
        $config = Services::remoteConfig();

        $token = DevicePrincipal::issue($config, 'a-device', 42, [DevicePrincipal::SCOPE_PRESENCE], time() - 1);

        $this->assertNull(DevicePrincipal::verify($config, $token));
    }

    /**
     * The canonical payload is the security property: if the agent and the API
     * disagree by one byte, either nothing authenticates or — far worse — a
     * signature over one thing is accepted for another. These are the exact
     * bytes `remote-security::challenge_payload()` produces.
     */
    public function testTheCanonicalChallengePayloadIsExactlyThisShape(): void
    {
        $payload = DeviceSignature::challengePayload(
            '2f1d6b2e-1d3f-4a54-9d0b-6f0c3f5f6a71',
            str_repeat('ab', 32),
            1770000000,
        );

        $this->assertSame(
            "AICOUNTLY-REMOTE-DEVICE-AUTH-v1\n"
            . "2f1d6b2e-1d3f-4a54-9d0b-6f0c3f5f6a71\n"
            . str_repeat('ab', 32) . "\n"
            . "1770000000\n"
            . "aicountly-remote-api\n",
            $payload,
        );
    }

    public function testTheNonceIsLowercasedBeforeSigningSoCaseCannotSplitTheTwoSides(): void
    {
        $lower = DeviceSignature::challengePayload('d', str_repeat('ab', 32), 1);
        $upper = DeviceSignature::challengePayload('d', strtoupper(str_repeat('ab', 32)), 1);

        $this->assertSame($lower, $upper);
    }

    public function testAsecondChallengeInvalidatesTheFirst(): void
    {
        [, $device, $secretKey] = $this->enrolled(808);
        $uuid = (string) $device['uuid'];

        $auth  = Services::deviceAuthenticationService();
        $first = $auth->challenge($uuid, null);
        $auth->challenge($uuid, null);

        $this->expectException(ApiException::class);

        $auth->verify(
            $uuid,
            $first['nonce'],
            $first['issuedAt'],
            $this->signChallenge($secretKey, $uuid, $first['nonce'], $first['issuedAt']),
        );
    }
}
