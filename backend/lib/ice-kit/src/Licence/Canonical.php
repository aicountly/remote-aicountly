<?php

declare(strict_types=1);

namespace Aicountly\Ice\Licence;

/**
 * The byte string a signature covers. A deliberately small subset of RFC 8785 (JSON Canonicalization Scheme):
 * object keys sorted by byte order, no insignificant whitespace, strings in UTF-8 with "/" and non-ASCII left
 * unescaped, and — the restriction that keeps every language's output identical — no floating-point numbers
 * and no objects that could be empty (PHP decodes `{}` and `[]` alike, so the contract uses lists where a collection may be empty).
 */
final class Canonical
{
    /** @throws \InvalidArgumentException on a float or anything not JSON-representable */
    public static function json(mixed $value): string
    {
        return json_encode(self::normalise($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function normalise(mixed $value): mixed
    {
        if (is_float($value)) {
            throw new \InvalidArgumentException('Floats are not allowed in a signed licence document.');
        }
        if (! is_array($value)) {
            if ($value === null || is_scalar($value)) {
                return $value;
            }
            throw new \InvalidArgumentException('Only JSON values may be signed.');
        }
        if (array_is_list($value)) {
            return array_map(self::normalise(...), $value);
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[(string) $k] = self::normalise($v);
        }
        uksort($out, static fn (string $a, string $b): int => strcmp($a, $b));

        return $out;
    }
}
