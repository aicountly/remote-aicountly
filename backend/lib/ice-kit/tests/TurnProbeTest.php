<?php

declare(strict_types=1);

namespace Aicountly\Ice\Tests;

use Aicountly\Ice\NodeStatus;
use Aicountly\Ice\Probe\StunMessage;
use Aicountly\Ice\Probe\TurnProbe;
use Aicountly\Ice\TurnNode;

require_once __DIR__ . '/IceKitTestCase.php';

/**
 * The STUN codec against RFC 5769's published test vectors, and — when a coturn is available — the
 * whole probe against a real relay.
 *
 * The live half runs when ICE_KIT_TEST_TURN_HOST and ICE_KIT_TEST_TURN_SECRET name a coturn with
 * `use-auth-secret` (ICE_KIT_TEST_TURN_CA for its TLS certificate, ICE_KIT_TEST_TURN_TLS_PORT if not
 * 5349). CI starts one; locally see lib/ice-kit/README.md.
 */
final class TurnProbeTest extends IceKitTestCase
{
    /** RFC 5769 §2.2, sample IPv4 response. */
    public function testXorMappedAddressDecodesTheRfc5769Ipv4Vector(): void
    {
        $message = StunMessage::decode((string) hex2bin(
            '0101003c2112a442b7e7a701bc34d686fa87dfae' .
            '8022000b7465737420766563746f7220' .
            '002000080001a147e112a643' .
            '000800142b91f599fd9e90c38c7489f92af9ba53f06be7d7' .
            '80280004c07d4c96',
        ));

        $this->assertNotNull($message);
        $this->assertSame(StunMessage::BINDING_SUCCESS, $message->type);
        $this->assertSame(['family' => 'ipv4', 'address' => '192.0.2.1', 'port' => 32853], $message->xorAddress(StunMessage::ATTR_XOR_MAPPED_ADDRESS));
    }

    /** RFC 5769 §2.3, sample IPv6 response — the address is XORed with the transaction id too. */
    public function testXorMappedAddressDecodesTheRfc5769Ipv6Vector(): void
    {
        $message = StunMessage::decode((string) hex2bin(
            '010100482112a442b7e7a701bc34d686fa87dfae' .
            '8022000b7465737420766563746f7220' .
            '002000140002a1470113a9faa5d3f179bc25f4b5bed2b9d9' .
            '00080014a382954e4be67bf11784c97c8292c275bfe3ed41' .
            '80280004c8fb0b4c',
        ));

        $this->assertSame(
            ['family' => 'ipv6', 'address' => '2001:db8:1234:5678:11:2233:4455:6677', 'port' => 32853],
            $message?->xorAddress(StunMessage::ATTR_XOR_MAPPED_ADDRESS),
        );
    }

    public function testMessageIntegrityCoversALengthThatIncludesItself(): void
    {
        $txid    = str_repeat("\x01", 12);
        $key     = StunMessage::longTermKey('user', 'realm', 'pass');
        $encoded = StunMessage::encode(StunMessage::ALLOCATE_REQUEST, $txid, [[StunMessage::ATTR_USERNAME, 'user']], $key);

        // header(20) + USERNAME(8) + MESSAGE-INTEGRITY(24); the header must already say 32.
        $this->assertSame(52, strlen($encoded));
        $this->assertSame(32, unpack('n', substr($encoded, 2, 2))[1]);
        $this->assertSame(
            hash_hmac('sha1', substr($encoded, 0, 28), $key, true),
            StunMessage::decode($encoded)?->first(StunMessage::ATTR_MESSAGE_INTEGRITY),
        );
    }

    public function testTurnUrlsAreParsedIntoTransportHostAndPort(): void
    {
        $this->assertSame(['transport' => 'udp', 'host' => 'turn-mum.aicountly.com', 'port' => 3478], TurnProbe::parseTurnUrl('turn:turn-mum.aicountly.com:3478?transport=udp'));
        $this->assertSame(['transport' => 'tcp', 'host' => 'h', 'port' => 3478], TurnProbe::parseTurnUrl('turn:h?transport=tcp'));
        $this->assertSame(['transport' => 'tls', 'host' => 'turn-mum.aicountly.com', 'port' => 443], TurnProbe::parseTurnUrl('turns:turn-mum.aicountly.com:443?transport=tcp'));
        $this->assertSame(['transport' => 'udp', 'host' => '2001:db8::1', 'port' => 3478], TurnProbe::parseTurnUrl('turn:[2001:db8::1]:3478'));
        $this->assertNull(TurnProbe::parseTurnUrl('stun:h:3478'));
    }

    public function testErrorCodesBecomeReasonsAnOperatorCanActOn(): void
    {
        $this->assertSame('auth_rejected', TurnProbe::reasonFor(401));
        $this->assertSame('allocation_quota_reached', TurnProbe::reasonFor(486));
        $this->assertSame('error_599', TurnProbe::reasonFor(599));
    }

    public function testAnUnreachableNodeFailsQuicklyAndSaysWhy(): void
    {
        $node   = new TurnNode('dead', '127.0.0.1', NodeStatus::Active, transports: ['tcp'], port: 1, secret: 's');
        $result = (new TurnProbe(1.0))->probeNode($node, null, true);

        $this->assertFalse($result['ok']);
        $this->assertFalse($result['reachable']);
        $this->assertSame('connect_failed', $result['error']);
    }

    /** @return array{0: string, 1: string, 2: array<string, mixed>, 3: int} */
    private function liveRelay(): array
    {
        $host   = (string) getenv('ICE_KIT_TEST_TURN_HOST');
        $secret = (string) getenv('ICE_KIT_TEST_TURN_SECRET');

        if ($host === '' || $secret === '') {
            $this->markTestSkipped('No live coturn: set ICE_KIT_TEST_TURN_HOST and ICE_KIT_TEST_TURN_SECRET.');
        }

        $ca = (string) getenv('ICE_KIT_TEST_TURN_CA');

        return [$host, $secret, $ca !== '' ? ['caFile' => $ca] : ['verifyPeer' => false], (int) (getenv('ICE_KIT_TEST_TURN_TLS_PORT') ?: 5349)];
    }

    public function testALiveRelayAllocatesOnEveryTransportWithAMintedCredential(): void
    {
        [$host, $secret, $tls, $tlsPort] = $this->liveRelay();

        $node   = new TurnNode('live', $host, NodeStatus::Active, tlsPort: $tlsPort, secret: $secret);
        $result = (new TurnProbe(3.0, $tls))->probeNode($node, null, true);

        $this->assertTrue($result['ok'], json_encode($result, JSON_PRETTY_PRINT) ?: '');
        foreach (['udp', 'tcp', 'tls'] as $transport) {
            $this->assertTrue($result['transports'][$transport]['binding']['ok'], "{$transport} binding");
            $this->assertTrue($result['transports'][$transport]['allocation']['ok'], "{$transport} allocation");
        }
    }

    public function testALiveRelayRefusesACredentialFromTheWrongSecret(): void
    {
        [$host, , $tls, $tlsPort] = $this->liveRelay();

        $node   = new TurnNode('live', $host, NodeStatus::Active, transports: ['udp'], tlsPort: $tlsPort, secret: 'not-the-secret');
        $result = (new TurnProbe(3.0, $tls))->probeNode($node, null, true);

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['reachable']);
        $this->assertSame('auth_rejected', $result['error']);
    }

    public function testALiveRelayRefusesAnExpiredCredential(): void
    {
        [$host, $secret, $tls] = $this->liveRelay();

        // What a client holding a credential past its expiry presents: the HMAC is right, the time is not.
        $username = (time() - 60) . ':expired';
        $result   = (new TurnProbe(3.0, $tls))->allocation('udp', $host, 3478, $username, base64_encode(hash_hmac('sha1', $username, $secret, true)));

        $this->assertFalse($result['ok']);
        $this->assertSame('auth_rejected', $result['error']);
    }

    // ------------------------------------------------------------------ negative checks and certificates

    public function testRefusableCredentialsAreWronglySignedOrExpiredAndNeverTheRealOne(): void
    {
        $node = new TurnNode('n', 'turn.example', NodeStatus::Active, secret: 'real-secret');

        $wrong = $node->refusableCredential('wrong-secret', 'probe', self::NOW);
        $this->assertGreaterThan(self::NOW, (int) $wrong['username'], 'Not expired: it is the signature that is wrong.');
        $this->assertNotSame(base64_encode(hash_hmac('sha1', $wrong['username'], 'real-secret', true)), $wrong['credential']);

        $expired = $node->refusableCredential('expired', 'probe', self::NOW);
        $this->assertLessThan(self::NOW, (int) $expired['username'], 'Expired: the timestamp has passed.');
        $this->assertSame(base64_encode(hash_hmac('sha1', $expired['username'], 'real-secret', true)), $expired['credential'], 'Signed correctly, so only the time can be why it is refused.');

        $this->assertNull((new TurnNode('s', 'turn.example', NodeStatus::Active, staticUsername: 'u', staticCredential: 'c'))->refusableCredential('expired', 'p', self::NOW));
        $this->expectException(\InvalidArgumentException::class);
        $node->refusableCredential('nonsense', 'p', self::NOW);
    }

    public function testACertificateIsSummarisedWithItsNamesIssuerAndDaysLeft(): void
    {
        $summary = TurnProbe::summarizeCertificate([
            'subject' => ['CN' => 'turn-mum.aicountly.com'],
            'issuer' => ['O' => "Let's Encrypt", 'CN' => 'R11'],
            'extensions' => ['subjectAltName' => 'DNS:turn-mum.aicountly.com, DNS:turn.aicountly.com, IP Address:148.135.210.65'],
            'validFrom_time_t' => self::NOW - 86400 * 30,
            'validTo_time_t' => self::NOW + 86400 * 9 + 3600,
        ], self::NOW, true);

        $this->assertSame('turn-mum.aicountly.com', $summary['subject']);
        $this->assertSame(['turn-mum.aicountly.com', 'turn.aicountly.com'], $summary['san']);
        $this->assertSame("Let's Encrypt R11", $summary['issuer']);
        $this->assertSame(9, $summary['daysRemaining']);
        $this->assertTrue($summary['verified']);
    }

    public function testALiveRelayRefusesForgedExpiredAndAnonymousRequestsOnEveryTransport(): void
    {
        [$host, $secret, $tls, $tlsPort] = $this->liveRelay();

        $node   = new TurnNode('live', $host, NodeStatus::Active, tlsPort: $tlsPort, secret: $secret);
        $result = (new TurnProbe(3.0, $tls))->probeNode($node, null, true, 300, 'probe', true);

        $this->assertTrue($result['ok'], json_encode($result, JSON_PRETTY_PRINT) ?: '');
        $this->assertTrue($result['negativeOk']);
        foreach (['udp', 'tcp', 'tls'] as $transport) {
            foreach (['wrongSecret', 'expired', 'anonymous'] as $check) {
                $this->assertSame('rejected', $result['transports'][$transport]['negative'][$check]['verdict'], "{$transport} {$check}");
            }
        }
    }

    public function testALiveRelayPresentsACertificateThatVerifiesForItsName(): void
    {
        [$host, $secret, $tls, $tlsPort] = $this->liveRelay();

        $node   = new TurnNode('live', $host, NodeStatus::Active, transports: ['tls'], tlsPort: $tlsPort, secret: $secret);
        $result = (new TurnProbe(3.0, $tls))->probeNode($node);
        $cert   = $result['transports']['tls']['binding']['certificate'] ?? null;

        $this->assertNotNull($cert);
        $this->assertSame(($tls['verifyPeer'] ?? true) === true, $cert['verified']);
        $this->assertNotSame('', $cert['subject'] . implode('', $cert['san']));
    }
}
