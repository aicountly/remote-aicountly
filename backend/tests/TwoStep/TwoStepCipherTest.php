<?php

declare(strict_types=1);

namespace Tests\TwoStep;

use App\Domain\TwoStep\SecretCipher;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Remote as RemoteConfig;

/**
 * Sealing authenticator secrets at rest.
 *
 * The properties that matter: a round trip works; the key comes from a secret
 * production already holds, through HKDF with its own label; a sealed value
 * opens only on its own person's row; a rotated key keeps old values readable
 * when it is listed; and with no usable secret there is no key at all — never
 * a fallback to storing the secret as it is.
 *
 * @internal
 */
final class TwoStepCipherTest extends CIUnitTestCase
{
    private const SIGNALLING = 'production-signalling-secret-0123456789abcdefghijklmnop';

    private function config(string $signalling = self::SIGNALLING, string $twoStepKey = '', array $previous = []): RemoteConfig
    {
        $config                      = new RemoteConfig();
        $config->signallingSecret    = $signalling;
        $config->twoStepKey          = $twoStepKey;
        $config->twoStepPreviousKeys = $previous;

        return $config;
    }

    public function testASecretSurvivesARoundTrip(): void
    {
        $cipher = new SecretCipher($this->config());
        $secret = random_bytes(20);

        $sealed = $cipher->seal($secret, 42);

        $this->assertNotSame($secret, $sealed['ciphertext']);
        $this->assertStringNotContainsString(bin2hex($secret), bin2hex((string) base64_decode($sealed['ciphertext'], true)));
        $this->assertSame($secret, $cipher->open($sealed['ciphertext'], $sealed['keyId'], 42));
        $this->assertNotSame($sealed['ciphertext'], $cipher->seal($secret, 42)['ciphertext'], 'a fresh nonce every time');
    }

    public function testTheKeyIsDerivedFromTheSignallingSecretWithItsOwnLabel(): void
    {
        $cipher   = new SecretCipher($this->config());
        $expected = hash_hkdf('sha256', self::SIGNALLING, 32, 'remote-two-step-v1', 'aicountly-remote');

        $this->assertTrue($cipher->available());
        $this->assertSame(SecretCipher::keyId($expected), $cipher->currentKeyId());
        $this->assertNotSame(substr(hash('sha256', self::SIGNALLING, true), 0, 32), $expected, 'not merely a hash of the secret');
        $this->assertStringNotContainsString(self::SIGNALLING, (string) $cipher->currentKeyId());
    }

    public function testADedicatedKeyWinsAndTheSignallingDerivedOneStaysReadable(): void
    {
        $before = new SecretCipher($this->config());
        $sealed = $before->seal('the old secret', 7);

        $after = new SecretCipher($this->config(twoStepKey: 'a-dedicated-two-step-key-0123456789abcdef'));

        $this->assertNotSame($before->currentKeyId(), $after->currentKeyId());
        $this->assertSame('the old secret', $after->open($sealed['ciphertext'], $sealed['keyId'], 7));
    }

    public function testARotatedSecretListedAsPreviousStillOpensWhatItSealed(): void
    {
        $old    = new SecretCipher($this->config());
        $sealed = $old->seal('sealed before the rotation', 7);

        $rotated = new SecretCipher($this->config('a-brand-new-signalling-secret-0123456789abcdef'));
        $this->assertFalse($rotated->canOpen($sealed['keyId']), 'unlisted, it is gone');
        $this->assertNull($rotated->open($sealed['ciphertext'], $sealed['keyId'], 7));

        $listed = new SecretCipher($this->config('a-brand-new-signalling-secret-0123456789abcdef', '', [self::SIGNALLING]));
        $this->assertSame('sealed before the rotation', $listed->open($sealed['ciphertext'], $sealed['keyId'], 7));
    }

    public function testASealedSecretOpensOnlyOnItsOwnPersonsRow(): void
    {
        $cipher = new SecretCipher($this->config());
        $sealed = $cipher->seal('mine', 7);

        $this->assertNull($cipher->open($sealed['ciphertext'], $sealed['keyId'], 8), 'copied onto somebody else\'s row, it does not open');
    }

    public function testATamperedValueDoesNotOpen(): void
    {
        $cipher = new SecretCipher($this->config());
        $sealed = $cipher->seal('mine', 7);
        $raw    = (string) base64_decode($sealed['ciphertext'], true);
        $raw[30] = chr(ord($raw[30]) ^ 1);

        $this->assertNull($cipher->open(base64_encode($raw), $sealed['keyId'], 7));
        $this->assertNull($cipher->open('not base64 at all!', $sealed['keyId'], 7));
    }

    public function testWithNoUsableSecretThereIsNoKeyAndNothingIsSealed(): void
    {
        foreach (['', 'short'] as $signalling) {
            $cipher = new SecretCipher($this->config($signalling));

            $this->assertFalse($cipher->available(), "signalling secret '{$signalling}'");
            $this->assertNull($cipher->currentKeyId());
        }

        $this->expectException(\LogicException::class);
        (new SecretCipher($this->config('')))->seal('anything', 1);
    }
}
