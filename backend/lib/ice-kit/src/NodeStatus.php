<?php

declare(strict_types=1);

namespace Aicountly\Ice;

/**
 * Whether a TURN node may be handed to a client.
 *
 * `Disabled` is the default for a node whose status is not written down: a relay that has not been
 * validated must never reach a browser by accident, and "configured but forgotten" is exactly how a
 * half-built node would otherwise go live.
 */
enum NodeStatus: string
{
    /** Served to clients, in priority order. */
    case Active = 'active';

    /** Served only when no active node is healthy — a warm spare in another region. */
    case Standby = 'standby';

    /** Never served. Still probeable with `bin/probe.php --node=<id>`, which is how it gets validated. */
    case Disabled = 'disabled';

    public static function parse(?string $value): self
    {
        return self::tryFrom(strtolower(trim((string) $value))) ?? self::Disabled;
    }
}
