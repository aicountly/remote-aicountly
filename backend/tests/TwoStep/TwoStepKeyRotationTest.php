<?php

declare(strict_types=1);

namespace Tests\TwoStep;

use App\Domain\Audit\EventType;
use App\Domain\Support\ApiException;
use App\Domain\TwoStep\SecretCipher;
use Config\Remote as RemoteConfig;
use Config\Services;
use Tests\Support\RemoteTestCase;

/**
 * What happens to the sealed secrets when the key changes, and the operator's
 * way out when a person has lost everything.
 *
 * A rotation that is announced (the old secret listed as previous) loses
 * nothing and re-seals as it goes; one that is not leaves people their recovery
 * codes, which never depended on the key; and support can switch it off for
 * somebody, on the record.
 *
 * @internal
 */
final class TwoStepKeyRotationTest extends RemoteTestCase
{
    private const OLD = 'test-two-step-key-0123456789abcdef0123456789';
    private const NEW = 'the-next-two-step-key-9876543210fedcba9876543210';

    private function rotateTo(string $key, array $previous): void
    {
        $this->configureRemote(static function (RemoteConfig $config) use ($key, $previous): void {
            $config->twoStepKey          = $key;
            $config->twoStepPreviousKeys = $previous;
        });
    }

    private function keyIdOf(int $userId): string
    {
        return (string) $this->db->table('remote_user_two_step')->where('user_id', $userId)->get()->getRow()->key_id;
    }

    public function testAnAnnouncedRotationKeepsWorkingAndReSealsOnFirstUse(): void
    {
        $meera  = $this->makeIdentity('Meera');
        $secret = $this->turnOnTwoStep($meera)['secret'];
        $oldId  = $this->keyIdOf($meera->id);

        $this->rotateTo(self::NEW, [self::OLD]);
        $newId = (string) (new SecretCipher(Services::remoteConfig()))->currentKeyId();
        $this->assertNotSame($oldId, $newId);

        Services::twoStep()->verify($meera, $this->totpCode($secret, 1), null);

        $this->assertSame($newId, $this->keyIdOf($meera->id), 're-sealed under the current key once read');
    }

    public function testRekeyReSealsEverybodySoThePreviousKeyCanGo(): void
    {
        $meera = $this->makeIdentity('Meera');
        $ravi  = $this->makeIdentity('Ravi');
        $this->turnOnTwoStep($meera);
        $secret = $this->turnOnTwoStep($ravi)['secret'];

        $this->rotateTo(self::NEW, [self::OLD]);
        $this->assertSame(['resealed' => 2, 'current' => 0, 'unreadable' => 0], Services::twoStep()->rekeyAll());
        $this->assertSame(['resealed' => 0, 'current' => 2, 'unreadable' => 0], Services::twoStep()->rekeyAll());

        // Without the old key now, Ravi's code still checks out.
        $this->rotateTo(self::NEW, []);
        $this->assertSame('TOTP', Services::twoStep()->verify($ravi, $this->totpCode($secret, 1), null)['method']);
    }

    public function testALostKeyIsNotThePersonsFaultAndTheirRecoveryCodesStillLetThemIn(): void
    {
        $meera = $this->makeIdentity('Meera');
        $setup = $this->turnOnTwoStep($meera);

        $this->rotateTo(self::NEW, []);

        try {
            Services::twoStep()->verify($meera, $this->totpCode($setup['secret'], 1), null);
            $this->fail('A secret sealed under a key the server does not hold cannot be checked.');
        } catch (ApiException $exception) {
            $this->assertSame('TWO_STEP_KEY_UNAVAILABLE', $exception->errorCode());
            $this->assertSame(503, $exception->status());
            $this->assertStringContainsString('Use one of your recovery codes instead', $exception->getMessage());
        }
        $this->assertSame(0, (int) $this->db->table('remote_user_two_step')->where('user_id', $meera->id)->get()->getRow()->failed_attempts, 'not counted against her');

        $this->assertSame('RECOVERY_CODE', Services::twoStep()->verify($meera, null, $setup['recoveryCodes'][0])['method']);
    }

    public function testSupportCanSwitchItOffForSomebodyOnTheRecord(): void
    {
        $meera = $this->makeIdentity('Meera');
        $this->turnOnTwoStep($meera);

        $this->assertTrue(Services::twoStep()->reset($meera->id, 'Lost phone and codes; identity checked on a call, ticket 4411'));
        $this->assertFalse(Services::twoStep()->currentStanding($meera)->enabled);
        $this->assertSame(0, $this->db->table('remote_user_recovery_codes')->where('user_id', $meera->id)->countAllResults());

        $audit = $this->db->table('remote_audit_logs')->where('event', EventType::TWO_STEP_RESET)->get()->getRowArray();
        $this->assertSame('SYSTEM', $audit['actor_type']);
        $this->assertStringContainsString('ticket 4411', (string) $audit['metadata']);

        $this->assertFalse(Services::twoStep()->reset($meera->id, 'again'), 'nothing left to reset');
    }
}
