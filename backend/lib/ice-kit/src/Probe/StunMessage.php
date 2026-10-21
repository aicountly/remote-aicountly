<?php

declare(strict_types=1);

namespace Aicountly\Ice\Probe;

/**
 * Just enough STUN (RFC 5389) and TURN (RFC 5766) to ask a relay "are you there" and "will you
 * allocate for this credential" — and nothing a client would need for carrying media.
 */
final class StunMessage
{
    public const MAGIC_COOKIE = 0x2112A442;

    public const BINDING_REQUEST  = 0x0001;
    public const BINDING_SUCCESS  = 0x0101;
    public const ALLOCATE_REQUEST = 0x0003;
    public const ALLOCATE_SUCCESS = 0x0103;
    public const ALLOCATE_ERROR   = 0x0113;
    public const REFRESH_REQUEST  = 0x0004;

    public const ATTR_USERNAME            = 0x0006;
    public const ATTR_MESSAGE_INTEGRITY   = 0x0008;
    public const ATTR_ERROR_CODE          = 0x0009;
    public const ATTR_LIFETIME            = 0x000D;
    public const ATTR_REALM               = 0x0014;
    public const ATTR_NONCE               = 0x0015;
    public const ATTR_XOR_RELAYED_ADDRESS = 0x0016;
    public const ATTR_REQUESTED_TRANSPORT = 0x0019;
    public const ATTR_XOR_MAPPED_ADDRESS  = 0x0020;
    public const ATTR_SOFTWARE            = 0x8022;

    /** UDP, as REQUESTED-TRANSPORT spells it — the relayed leg is always UDP for WebRTC. */
    public const TRANSPORT_UDP = 17;

    /**
     * @param array<int, list<string>> $attributes raw values by attribute type
     */
    public function __construct(
        public readonly int $type,
        public readonly string $transactionId,
        public readonly array $attributes = [],
    ) {
    }

    public static function newTransactionId(): string
    {
        return random_bytes(12);
    }

    /**
     * Encode, appending MESSAGE-INTEGRITY when a long-term key is given.
     *
     * @param list<array{0: int, 1: string}> $attributes in order
     */
    public static function encode(int $type, string $transactionId, array $attributes, ?string $integrityKey = null): string
    {
        $body = '';
        foreach ($attributes as [$attrType, $value]) {
            $body .= self::attribute($attrType, $value);
        }

        if ($integrityKey === null) {
            return self::header($type, strlen($body), $transactionId) . $body;
        }

        // The HMAC covers the header with its length already counting MESSAGE-INTEGRITY itself
        // (RFC 5389 §15.4) — the detail every hand-rolled STUN client gets wrong once.
        $lengthWithIntegrity = strlen($body) + 24;
        $hmac = hash_hmac('sha1', self::header($type, $lengthWithIntegrity, $transactionId) . $body, $integrityKey, true);

        return self::header($type, $lengthWithIntegrity, $transactionId) . $body . self::attribute(self::ATTR_MESSAGE_INTEGRITY, $hmac);
    }

    /** RFC 5389 §15.4: key = MD5(username ":" realm ":" password) for long-term credentials. */
    public static function longTermKey(string $username, string $realm, #[\SensitiveParameter] string $password): string
    {
        return md5($username . ':' . $realm . ':' . $password, true);
    }

    /** Length of the whole message from its 20-byte header, or null if it is not a STUN header. */
    public static function lengthFromHeader(string $header): ?int
    {
        if (strlen($header) < 20) {
            return null;
        }

        $fields = unpack('ntype/nlength/Ncookie', substr($header, 0, 8));
        if ($fields === false || $fields['cookie'] !== self::MAGIC_COOKIE || ($fields['type'] & 0xC000) !== 0) {
            return null;
        }

        return 20 + $fields['length'];
    }

    public static function decode(string $bytes): ?self
    {
        $total = self::lengthFromHeader($bytes);
        if ($total === null || strlen($bytes) < $total) {
            return null;
        }

        $fields = unpack('ntype', $bytes);
        $txid   = substr($bytes, 8, 12);
        $attrs  = [];
        $offset = 20;

        while ($offset + 4 <= $total) {
            $head = unpack('ntype/nlength', substr($bytes, $offset, 4));
            if ($head === false) {
                break;
            }

            $attrs[$head['type']][] = substr($bytes, $offset + 4, $head['length']);
            $offset += 4 + $head['length'] + ((4 - $head['length'] % 4) % 4);
        }

        return new self($fields['type'], $txid, $attrs);
    }

    public function first(int $attrType): ?string
    {
        return $this->attributes[$attrType][0] ?? null;
    }

    /** @return array{code: int, reason: string}|null */
    public function errorCode(): ?array
    {
        $raw = $this->first(self::ATTR_ERROR_CODE);
        if ($raw === null || strlen($raw) < 4) {
            return null;
        }

        return [
            'code'   => (ord($raw[2]) & 0x07) * 100 + ord($raw[3]),
            'reason' => substr($raw, 4),
        ];
    }

    /** An XOR-*-ADDRESS attribute, decoded. @return array{family: string, address: string, port: int}|null */
    public function xorAddress(int $attrType): ?array
    {
        $raw = $this->first($attrType);
        if ($raw === null || strlen($raw) < 8) {
            return null;
        }

        $family = ord($raw[1]);
        $port   = unpack('n', substr($raw, 2, 2))[1] ^ (self::MAGIC_COOKIE >> 16);
        $cookie = pack('N', self::MAGIC_COOKIE);

        if ($family === 0x01) {
            return ['family' => 'ipv4', 'address' => (string) inet_ntop(substr($raw, 4, 4) ^ $cookie), 'port' => $port];
        }

        if ($family === 0x02 && strlen($raw) >= 20) {
            return ['family' => 'ipv6', 'address' => (string) inet_ntop(substr($raw, 4, 16) ^ ($cookie . $this->transactionId)), 'port' => $port];
        }

        return null;
    }

    private static function header(int $type, int $length, string $transactionId): string
    {
        return pack('nnN', $type, $length, self::MAGIC_COOKIE) . $transactionId;
    }

    private static function attribute(int $type, string $value): string
    {
        $length = strlen($value);

        return pack('nn', $type, $length) . $value . str_repeat("\0", (4 - $length % 4) % 4);
    }
}
