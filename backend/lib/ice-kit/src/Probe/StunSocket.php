<?php

declare(strict_types=1);

namespace Aicountly\Ice\Probe;

/**
 * One connection to a STUN/TURN server over udp, tcp or tls, exchanging whole STUN messages.
 *
 * Over TCP and TLS STUN messages are framed by their own header length (RFC 5389 §7.2.2), so a read
 * takes the 20-byte header and then exactly the length it announces. Over UDP a message is a datagram,
 * and a lost one is retransmitted (RFC 5389 §7.2.1, shortened: this is a probe, not a call).
 */
final class StunSocket
{
    /** @var resource */
    private $stream;

    private function __construct(
        $stream,
        public readonly string $transport,
        private readonly float $timeout,
        /** The server's certificate as openssl_x509_parse() reads it; TLS only. */
        public readonly ?array $certificate = null,
    ) {
        $this->stream = $stream;
    }

    /**
     * @param array{caFile?: string|null, verifyPeer?: bool} $tls verifyPeer defaults to true; turning it
     *        off exists for a test relay with a self-signed certificate and is never right in production
     */
    public static function open(string $transport, string $host, int $port, float $timeout, array $tls = []): self
    {
        $target = str_contains($host, ':') ? "[{$host}]" : $host;
        $scheme = match ($transport) {
            'udp'   => 'udp',
            'tcp'   => 'tcp',
            'tls'   => 'tls',
            default => throw new \InvalidArgumentException("Unknown transport {$transport}."),
        };

        $options = [];
        if ($transport === 'tls') {
            $verify = $tls['verifyPeer'] ?? true;
            $options['ssl'] = array_filter([
                'peer_name'         => $host,
                'SNI_enabled'       => true,
                'verify_peer'       => $verify,
                'verify_peer_name'  => $verify,
                'allow_self_signed' => ! $verify,
                'cafile'            => $tls['caFile'] ?? null,
                'capture_peer_cert' => true,
            ], static fn ($v) => $v !== null);
        }
        $context = stream_context_create($options);

        $errno  = 0;
        $errstr = '';
        $stream = @stream_socket_client(
            "{$scheme}://{$target}:{$port}",
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($stream === false) {
            // PHP reports a failed TLS handshake (an untrusted or mismatched certificate, or a port
            // that does not speak TLS) with an empty error string; name it, because "could not
            // connect" sends an operator to the firewall when the problem is the certificate.
            if ($transport === 'tls' && trim($errstr) === '') {
                throw new ProbeException('tls_failed', "TLS handshake with {$host}:{$port} failed: certificate not trusted, name mismatch, or not a TLS port.");
            }

            throw new ProbeException('connect_failed', trim($errstr) !== '' ? trim($errstr) : "Could not open {$transport} to {$host}:{$port}.");
        }

        stream_set_blocking($stream, true);

        $certificate = null;
        if ($transport === 'tls') {
            $captured    = stream_context_get_params($context)['options']['ssl']['peer_certificate'] ?? null;
            $parsed      = $captured !== null ? openssl_x509_parse($captured) : false;
            $certificate = is_array($parsed) ? $parsed : null;
        }

        return new self($stream, $transport, $timeout, $certificate);
    }

    /**
     * Send one request and wait for the response with the same transaction id.
     */
    public function exchange(string $request, string $transactionId): StunMessage
    {
        $attempts = $this->transport === 'udp' ? 3 : 1;
        $deadline = microtime(true) + $this->timeout;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            if (@fwrite($this->stream, $request) !== strlen($request)) {
                throw new ProbeException('send_failed', 'The request could not be sent.');
            }

            $wait = $this->transport === 'udp'
                ? min($deadline - microtime(true), $this->timeout / $attempts)
                : $deadline - microtime(true);

            $response = $this->receive($transactionId, microtime(true) + max(0.05, $wait));
            if ($response !== null) {
                return $response;
            }
        }

        throw new ProbeException('timeout', 'No STUN response before the timeout.');
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    private function receive(string $transactionId, float $until): ?StunMessage
    {
        while (($left = $until - microtime(true)) > 0) {
            $read   = [$this->stream];
            $write  = null;
            $except = null;
            $seconds = (int) floor($left);

            // A TLS stream can hold decrypted bytes stream_select cannot see.
            $buffered = $this->transport === 'tls' && (stream_get_meta_data($this->stream)['unread_bytes'] ?? 0) > 0;

            if (! $buffered && @stream_select($read, $write, $except, $seconds, (int) (($left - $seconds) * 1_000_000)) < 1) {
                return null;
            }

            $message = $this->transport === 'udp' ? $this->readDatagram() : $this->readFramed($until);

            if ($message !== null && $message->transactionId === $transactionId) {
                return $message;
            }
        }

        return null;
    }

    private function readDatagram(): ?StunMessage
    {
        $data = @fread($this->stream, 2048);

        return is_string($data) && $data !== '' ? StunMessage::decode($data) : null;
    }

    private function readFramed(float $until): ?StunMessage
    {
        $header = $this->readExactly(20, $until);
        if ($header === null) {
            throw new ProbeException('closed', 'The server closed the connection.');
        }

        $total = StunMessage::lengthFromHeader($header);
        if ($total === null) {
            throw new ProbeException('not_stun', 'The server answered with something that is not STUN.');
        }

        $rest = $total > 20 ? $this->readExactly($total - 20, $until) : '';

        return $rest === null ? null : StunMessage::decode($header . $rest);
    }

    private function readExactly(int $length, float $until): ?string
    {
        $buffer = '';

        while (strlen($buffer) < $length && microtime(true) < $until) {
            stream_set_timeout($this->stream, 0, (int) max(1000, ($until - microtime(true)) * 1_000_000));
            $chunk = @fread($this->stream, $length - strlen($buffer));

            if ($chunk === false || ($chunk === '' && feof($this->stream))) {
                return null;
            }

            $buffer .= $chunk;
        }

        return strlen($buffer) === $length ? $buffer : null;
    }
}
