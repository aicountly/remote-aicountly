<?php

declare(strict_types=1);

namespace App\Domain\TwoStep;

/**
 * Where one signed-in request stands with two-step verification: whether the
 * person has it on, and whether the browser making the request has passed it —
 * and when.
 *
 * Worked out once per request by the auth filter from the verification token
 * the browser presented, and re-read by {@see TwoStepService::requireVerified()}
 * where an action needs a fresher answer than "some time today".
 */
final class TwoStepStanding
{
    public function __construct(
        public readonly bool $enabled,
        public readonly ?int $verifiedAt = null,
        public readonly ?int $verifiedUntil = null,
    ) {
    }

    public static function off(): self
    {
        return new self(false);
    }

    /** The browser holds a verification that has not expired. */
    public function sessionVerified(): bool
    {
        return $this->enabled && $this->verifiedUntil !== null && $this->verifiedUntil > time();
    }

    /** The person has it on and this browser has not passed it: everything but a few routes is refused. */
    public function blocksRequest(): bool
    {
        return $this->enabled && ! $this->sessionVerified();
    }

    /** Passed, and recently enough for a step-up. */
    public function verifiedWithin(int $seconds): bool
    {
        return $this->sessionVerified() && $this->verifiedAt !== null && $this->verifiedAt >= time() - $seconds;
    }
}
