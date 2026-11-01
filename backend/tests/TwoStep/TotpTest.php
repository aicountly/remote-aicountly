<?php

declare(strict_types=1);

namespace Tests\TwoStep;

use App\Domain\TwoStep\Totp;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * TOTP against the RFCs' own test vectors.
 *
 * The algorithm is implemented in the product rather than taken from a
 * package, so these are what say it is the algorithm: RFC 4226 appendix D for
 * HOTP, RFC 6238 appendix B for TOTP (the SHA-1 column, which is the one every
 * authenticator app uses), RFC 4648 section 10 for base32.
 *
 * @internal
 */
final class TotpTest extends CIUnitTestCase
{
    /** RFC 4226 and RFC 6238 both use this ASCII secret for their SHA-1 vectors. */
    private const RFC_SECRET = '12345678901234567890';

    public function testHotpMatchesRfc4226AppendixD(): void
    {
        $expected = ['755224', '287082', '359152', '969429', '338314', '254676', '287922', '162583', '399871', '520489'];

        foreach ($expected as $counter => $code) {
            $this->assertSame($code, Totp::hotp(self::RFC_SECRET, $counter), "HOTP counter {$counter}");
        }
    }

    public function testTotpMatchesRfc6238AppendixBForSha1(): void
    {
        // The RFC's table is eight digits; the same truncation gives the six an app shows.
        $vectors = [
            59          => '94287082',
            1111111109  => '07081804',
            1111111111  => '14050471',
            1234567890  => '89005924',
            2000000000  => '69279037',
            20000000000 => '65353130',
        ];

        foreach ($vectors as $time => $code) {
            $this->assertSame($code, Totp::at(self::RFC_SECRET, $time, 8), "TOTP at T={$time}");
            $this->assertSame(substr($code, -6), Totp::at(self::RFC_SECRET, $time), "six digits at T={$time}");
        }
    }

    public function testTheStepIsThirtySeconds(): void
    {
        $this->assertSame(1, Totp::step(59));
        $this->assertSame(37037036, Totp::step(1111111109));
        $this->assertSame(0x23523EC, Totp::step(1111111109), 'the RFC gives the step in hex too');
    }

    public function testAMatchIsAllowedOneStepEitherSideAndNoFurther(): void
    {
        $now = 1_700_000_000;

        $this->assertSame(Totp::step($now), Totp::match(self::RFC_SECRET, Totp::at(self::RFC_SECRET, $now), $now));
        $this->assertSame(Totp::step($now) - 1, Totp::match(self::RFC_SECRET, Totp::at(self::RFC_SECRET, $now - 30), $now));
        $this->assertSame(Totp::step($now) + 1, Totp::match(self::RFC_SECRET, Totp::at(self::RFC_SECRET, $now + 30), $now));

        $this->assertNull(Totp::match(self::RFC_SECRET, Totp::at(self::RFC_SECRET, $now - 60), $now), 'two steps old');
        $this->assertNull(Totp::match(self::RFC_SECRET, Totp::at(self::RFC_SECRET, $now + 60), $now), 'two steps ahead');
    }

    public function testAMatchNeedsExactlySixDigits(): void
    {
        $now  = 1_700_000_000;
        $code = Totp::at(self::RFC_SECRET, $now);

        $this->assertNull(Totp::match(self::RFC_SECRET, substr($code, 1), $now));
        $this->assertNull(Totp::match(self::RFC_SECRET, $code . '0', $now));
        $this->assertNull(Totp::match(self::RFC_SECRET, 'abcdef', $now));
        $this->assertNull(Totp::match('another secret entirely', $code, $now));
    }

    public function testBase32MatchesRfc4648WithoutPadding(): void
    {
        $vectors = ['' => '', 'f' => 'MY', 'fo' => 'MZXQ', 'foo' => 'MZXW6', 'foob' => 'MZXW6YQ', 'fooba' => 'MZXW6YTB', 'foobar' => 'MZXW6YTBOI'];

        foreach ($vectors as $plain => $encoded) {
            $this->assertSame($encoded, Totp::base32Encode($plain), "encode '{$plain}'");
            $this->assertSame($plain, Totp::base32Decode($encoded), "decode '{$encoded}'");
        }

        // What a person types: lower case, spaces, the padding an RFC example carries.
        $this->assertSame('foobar', Totp::base32Decode('mzxw 6ytb oi======'));
        $this->assertNull(Totp::base32Decode('MZXW1'), '1 is not base32');
    }

    public function testASecretIs160BitsAnd32Base32Characters(): void
    {
        $secret = Totp::generateSecret();

        $this->assertSame(20, strlen($secret));
        $this->assertSame(32, strlen(Totp::base32Encode($secret)));
        $this->assertSame($secret, Totp::base32Decode(Totp::base32Encode($secret)));
        $this->assertNotSame($secret, Totp::generateSecret());
    }

    public function testTheUriIsWhatAnAuthenticatorAppReads(): void
    {
        $uri = Totp::uri('AICOUNTLY Remote', 'meera@example.test', 'JBSWY3DPEHPK3PXP');

        $this->assertSame(
            'otpauth://totp/AICOUNTLY%20Remote:meera%40example.test?secret=JBSWY3DPEHPK3PXP&issuer=AICOUNTLY%20Remote&algorithm=SHA1&digits=6&period=30',
            $uri,
        );
    }
}
