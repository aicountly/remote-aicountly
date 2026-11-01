<?php

declare(strict_types=1);

namespace Tests\TwoStep;

use App\Domain\Audit\EventType;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Remote as RemoteConfig;
use Config\Services;
use Tests\Support\RemoteTestCase;

/**
 * `php spark remote:two-step` — what an operator runs on the server: whether
 * it can seal secrets (and with which key, by id only), re-sealing after a
 * rotation, and support's reset for somebody who has lost everything.
 *
 * @internal
 */
final class TwoStepCommandTest extends RemoteTestCase
{
    use StreamFilterTrait;

    public function testStatusSaysWhetherSecretsCanBeSealedAndNeverPrintsAKey(): void
    {
        $this->turnOnTwoStep($this->makeIdentity('Meera'));

        command('remote:two-step status');
        $out = $this->getStreamFilterBuffer();

        $this->assertStringContainsString('remote.twoStepKey', $out);
        $this->assertStringContainsString('yes', $out);
        $this->assertStringContainsString('current key', $out);
        $this->assertMatchesRegularExpression('/people with it on\s+1/', $out);
        $this->assertStringNotContainsString(Services::remoteConfig()->twoStepKey, $out);
    }

    public function testStatusFailsWhenASecretIsSealedUnderAKeyTheServerNoLongerHolds(): void
    {
        $this->turnOnTwoStep($this->makeIdentity('Meera'));
        $this->configureRemote(static function (RemoteConfig $config): void {
            $config->twoStepKey = 'an-entirely-different-key-0123456789abcdef';
        });

        command('remote:two-step status');
        $this->assertStringContainsString('a key this server does not hold', $this->getStreamFilterBuffer());
        $this->assertStringContainsString('cannot be opened', $this->getStreamFilterBuffer());
    }

    public function testResetSwitchesItOffForOnePersonOnTheRecord(): void
    {
        $meera = $this->makeIdentity('Meera', 'meera@example.test');
        $this->turnOnTwoStep($meera);

        command('remote:two-step reset meera@example.test');
        $this->assertStringContainsString('Usage:', $this->getStreamFilterBuffer(), 'a reason is required');
        $this->assertTrue(Services::twoStep()->currentStanding($meera)->enabled);

        command('remote:two-step reset meera@example.test --reason "Lost phone, ticket 4411"');
        $this->assertStringContainsString('Two-step verification is off for Meera', $this->getStreamFilterBuffer());
        $this->assertFalse(Services::twoStep()->currentStanding($meera)->enabled);
        $this->assertHasAudit(EventType::TWO_STEP_RESET);

        command('remote:two-step reset nobody@example.test --reason "x"');
        $this->assertStringContainsString('Nobody with that uuid or e-mail.', $this->getStreamFilterBuffer());
    }

    public function testRekeyMovesEverySecretToTheCurrentKey(): void
    {
        $this->turnOnTwoStep($this->makeIdentity('Meera'));
        $old = Services::remoteConfig()->twoStepKey;
        $this->configureRemote(static function (RemoteConfig $config) use ($old): void {
            $config->twoStepKey          = 'the-next-two-step-key-9876543210fedcba9876543210';
            $config->twoStepPreviousKeys = [$old];
        });

        command('remote:two-step rekey');
        $this->assertStringContainsString('Re-sealed 1, already current 0, unreadable 0.', $this->getStreamFilterBuffer());
    }
}
