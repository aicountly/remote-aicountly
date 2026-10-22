<?php

declare(strict_types=1);

/**
 * Test helper for the simulations: open a TURN allocation with a minted credential and HOLD it, so a
 * relay has something to carry and count. Never used against a real node.
 *
 *   php hold-allocation.php <host> <port> <secret> <subject> <seconds>
 *
 * Prints `ALLOCATED <relay-port>` or `REFUSED <reason>` at once, then stays up for <seconds>, refreshing.
 */

use Aicountly\Ice\CredentialMinter;
use Aicountly\Ice\Probe\ProbeException;
use Aicountly\Ice\Probe\StunMessage;
use Aicountly\Ice\Probe\StunSocket;
use Aicountly\Ice\Probe\TurnProbe;

require_once dirname(__DIR__, 2) . '/autoload.php';

[, $host, $port, $secret, $subject, $seconds] = $argv;

$minted = CredentialMinter::mint($secret, $subject, 600, time());
$socket = StunSocket::open('udp', $host, (int) $port, 3.0);

try {
    $requested = [StunMessage::ATTR_REQUESTED_TRANSPORT, pack('C', StunMessage::TRANSPORT_UDP) . "\0\0\0"];
    $txid      = StunMessage::newTransactionId();
    $challenge = $socket->exchange(StunMessage::encode(StunMessage::ALLOCATE_REQUEST, $txid, [$requested]), $txid);
    $realm     = $challenge->first(StunMessage::ATTR_REALM);
    $nonce     = $challenge->first(StunMessage::ATTR_NONCE);
    $key       = StunMessage::longTermKey($minted['username'], $realm, $minted['credential']);

    for ($try = 0; $try < 2; $try++) {
        $txid  = StunMessage::newTransactionId();
        $reply = $socket->exchange(StunMessage::encode(StunMessage::ALLOCATE_REQUEST, $txid, [
            $requested, [StunMessage::ATTR_USERNAME, $minted['username']], [StunMessage::ATTR_REALM, $realm], [StunMessage::ATTR_NONCE, $nonce],
        ], $key), $txid);

        if ($reply->type === StunMessage::ALLOCATE_SUCCESS) {
            $relayed = $reply->xorAddress(StunMessage::ATTR_XOR_RELAYED_ADDRESS);
            echo 'ALLOCATED ', $relayed['port'] ?? '?', "\n";
            fflush(STDOUT);
            sleep((int) $seconds);

            // Release, so the relay logs the allocation's usage line (which is what summarize-usage reads).
            $txid = StunMessage::newTransactionId();
            try {
                $socket->exchange(StunMessage::encode(StunMessage::REFRESH_REQUEST, $txid, [
                    [StunMessage::ATTR_LIFETIME, pack('N', 0)], [StunMessage::ATTR_USERNAME, $minted['username']], [StunMessage::ATTR_REALM, $realm], [StunMessage::ATTR_NONCE, $nonce],
                ], $key), $txid);
            } catch (ProbeException) {
            }
            exit(0);
        }

        $code = $reply->errorCode()['code'] ?? 0;
        if ($code === 438 && ($fresh = $reply->first(StunMessage::ATTR_NONCE)) !== null) {
            $nonce = $fresh;
            continue;
        }

        echo 'REFUSED ', TurnProbe::reasonFor($code), "\n";
        exit(1);
    }
} catch (ProbeException $e) {
    echo 'REFUSED ', $e->reason, "\n";
    exit(1);
}
