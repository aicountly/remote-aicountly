<?php

declare(strict_types=1);

namespace App\Domain\Support;

/**
 * UTC, explicitly (§96).
 *
 * Every timestamp Remote writes carries an explicit `+00` offset. A naive
 * `Y-m-d H:i:s` string handed to a `TIMESTAMPTZ` column is interpreted in the
 * *database server's* timezone, which on a shared host is whatever the provider
 * set — so a session created at 14:00 UTC can land as 14:00 IST and expire five
 * and a half hours early. The offset removes the guesswork entirely.
 *
 * Reading back needs no such care: Postgres renders `TIMESTAMPTZ` with its
 * offset, so `strtotime()` recovers the correct instant wherever it is parsed.
 * {@see iso()} is what the API formats with, and it is always UTC.
 */
final class Clock
{
    /** Now, as a value safe to write into a TIMESTAMPTZ column. */
    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s') . '+00';
    }

    /** Now plus (or minus) some seconds, in the same form. */
    public static function in(int $seconds): string
    {
        return gmdate('Y-m-d H:i:s', time() + $seconds) . '+00';
    }

    public static function inMinutes(int $minutes): string
    {
        return self::in($minutes * 60);
    }

    /** ISO-8601 UTC, or null — the only shape timestamps leave the API in. */
    public static function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $timestamp = is_int($value) ? $value : strtotime((string) $value);

        return $timestamp === false ? null : gmdate('c', $timestamp);
    }

    public static function hasPassed(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        $timestamp = strtotime((string) $value);

        return $timestamp !== false && $timestamp <= time();
    }
}
