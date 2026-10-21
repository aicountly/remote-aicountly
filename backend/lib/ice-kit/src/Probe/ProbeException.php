<?php

declare(strict_types=1);

namespace Aicountly\Ice\Probe;

/** A probe step that failed, with a machine-readable reason an operator can act on. */
final class ProbeException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
