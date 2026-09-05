<?php

declare(strict_types=1);

namespace App\Domain\Device;

use App\Domain\Session\ClientCapabilities;

/**
 * What a device says it can do, and what it is therefore allowed to do.
 *
 * The rule from §51 and `docs/DESKTOP_AGENT.md`, stated once:
 *
 * > **A client-declared capability is an upper bound, never a grant.**
 *
 * An agent declares `remote_control: true` because the software is capable of
 * it. Whether it *may* is decided by the organisation's policy, the plan
 * entitlement and the person's permission — and this class is where the two
 * halves meet, so no caller can accidentally use one without the other.
 *
 * Editing the capability JSON on a device therefore gains nothing: the
 * declaration is intersected, not trusted.
 */
final class DeviceCapabilities
{
    /**
     * Normalise whatever a device declared against the known capability keys.
     *
     * Anything unrecognised is dropped rather than carried through, so a future
     * capability name cannot be smuggled into the row and read by some later
     * version of the code that would have honoured it.
     *
     * @param  array<string, mixed>|mixed $claimed
     * @return array<string, bool>
     */
    public static function normaliseDeclaration(mixed $claimed): array
    {
        $claimed = is_array($claimed) ? $claimed : [];

        // The desktop agent's ceiling is the widest shape the product knows.
        return ClientCapabilities::normalise(ClientCapabilities::CLIENT_DESKTOP_AGENT, $claimed);
    }

    /**
     * The declaration ∧ what policy permits.
     *
     * @param  array<string, mixed> $declared
     * @param  array<string, bool>  $ceiling
     * @return array<string, bool>
     */
    public static function intersect(array $declared, array $ceiling): array
    {
        $declared = self::normaliseDeclaration($declared);

        $result = [];
        foreach ($declared as $key => $capable) {
            $result[$key] = $capable === true && ($ceiling[$key] ?? false) === true;
        }

        return $result;
    }
}
