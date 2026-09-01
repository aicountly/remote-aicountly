<?php

declare(strict_types=1);

namespace App\Domain\Session;

use App\Domain\Support\ApiException;

/**
 * The session state machine (§21).
 *
 * Every status change goes through {@see assertTransition()}. There are no
 * boolean flags standing in for state anywhere in this product: a session is
 * exactly one of these values, and the set of moves out of it is fixed here.
 */
final class SessionStatus
{
    public const CREATED        = 'CREATED';
    public const WAITING        = 'WAITING';
    public const JOIN_REQUESTED = 'JOIN_REQUESTED';
    public const CONNECTING     = 'CONNECTING';
    public const ACTIVE         = 'ACTIVE';
    public const PAUSED         = 'PAUSED';
    public const RECONNECTING   = 'RECONNECTING';
    public const ENDED          = 'ENDED';
    public const DECLINED       = 'DECLINED';
    public const EXPIRED        = 'EXPIRED';
    public const FAILED         = 'FAILED';

    /**
     * Allowed moves. The happy path is
     * CREATED → WAITING → JOIN_REQUESTED → CONNECTING → ACTIVE → ENDED,
     * with the rest covering the ways a real network and real people deviate
     * from it.
     *
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        self::CREATED => [self::WAITING, self::JOIN_REQUESTED, self::CONNECTING, self::ENDED, self::EXPIRED, self::FAILED],
        self::WAITING => [self::JOIN_REQUESTED, self::CONNECTING, self::ACTIVE, self::ENDED, self::DECLINED, self::EXPIRED, self::FAILED],
        // A join request can be declined, or superseded by another participant.
        self::JOIN_REQUESTED => [self::WAITING, self::CONNECTING, self::ACTIVE, self::DECLINED, self::ENDED, self::EXPIRED, self::FAILED],
        self::CONNECTING     => [self::ACTIVE, self::WAITING, self::RECONNECTING, self::ENDED, self::FAILED, self::EXPIRED],
        self::ACTIVE         => [self::PAUSED, self::RECONNECTING, self::WAITING, self::ENDED, self::EXPIRED, self::FAILED],
        self::PAUSED         => [self::ACTIVE, self::RECONNECTING, self::ENDED, self::EXPIRED, self::FAILED],
        self::RECONNECTING   => [self::ACTIVE, self::PAUSED, self::WAITING, self::ENDED, self::FAILED, self::EXPIRED],
        // Terminal.
        self::ENDED    => [],
        self::DECLINED => [],
        self::EXPIRED  => [],
        self::FAILED   => [],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::TRANSITIONS);
    }

    public static function isTerminal(string $status): bool
    {
        return self::TRANSITIONS[$status] === [];
    }

    /** Can this session still be joined, shared to, or chatted in? */
    public static function isLive(string $status): bool
    {
        return ! self::isTerminal($status);
    }

    public static function canTransition(string $from, string $to): bool
    {
        if ($from === $to) {
            return true; // Idempotent: re-asserting the current state is a no-op.
        }

        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * @throws ApiException 409 — the caller asked for a move the session cannot make
     */
    public static function assertTransition(string $from, string $to): void
    {
        if (! isset(self::TRANSITIONS[$from])) {
            throw ApiException::conflict('SESSION_STATE_UNKNOWN', 'This session is in an unrecognised state.');
        }

        if (self::canTransition($from, $to)) {
            return;
        }

        if (self::isTerminal($from)) {
            throw ApiException::conflict(
                'SESSION_ALREADY_ENDED',
                'This Remote session has already finished.',
                ['status' => $from],
            );
        }

        throw ApiException::conflict(
            'SESSION_STATE_INVALID',
            'That is not possible for this session right now.',
            ['from' => $from, 'to' => $to],
        );
    }
}
