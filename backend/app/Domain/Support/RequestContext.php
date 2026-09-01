<?php

declare(strict_types=1);

namespace App\Domain\Support;

use App\Domain\Auth\GuestPrincipal;
use App\Domain\Auth\RemoteIdentity;
use App\Domain\Auth\SourceContext;

/**
 * Who is making this request.
 *
 * Written once by the auth filter, read everywhere else. Two kinds of caller
 * exist and they are never conflated:
 *
 *   * an **AICOUNTLY user**, authenticated by a portal `ses_key`;
 *   * a **guest**, holding a token bound to one participant in one session.
 *
 * `identity()` throws for a guest rather than returning null, so a controller
 * that was written for signed-in users cannot silently treat a guest as one.
 * Endpoints that genuinely serve both ask for {@see guest()} explicitly.
 */
final class RequestContext
{
    private ?RemoteIdentity $identity = null;
    private ?GuestPrincipal $guest = null;
    private ?SourceContext $sourceContext = null;

    public function setIdentity(?RemoteIdentity $identity): void
    {
        $this->identity = $identity;
    }

    public function setGuest(?GuestPrincipal $guest): void
    {
        $this->guest = $guest;
    }

    public function setSourceContext(?SourceContext $context): void
    {
        $this->sourceContext = $context;
    }

    /** @throws ApiException when the caller is a guest or is not signed in */
    public function identity(): RemoteIdentity
    {
        if ($this->identity === null) {
            throw ApiException::unauthenticated(
                $this->guest !== null
                    ? 'Guests cannot use this part of AICOUNTLY Remote.'
                    : 'Sign in to continue.',
            );
        }

        return $this->identity;
    }

    public function identityOrNull(): ?RemoteIdentity
    {
        return $this->identity;
    }

    public function guest(): ?GuestPrincipal
    {
        return $this->guest;
    }

    public function sourceContext(): ?SourceContext
    {
        return $this->sourceContext;
    }

    public function isAuthenticated(): bool
    {
        return $this->identity !== null || $this->guest !== null;
    }

    /** The name to show for whoever this is. */
    public function displayName(): string
    {
        return $this->identity?->displayName ?? $this->guest?->displayName ?? 'Guest';
    }

    public function actorType(): string
    {
        return $this->identity !== null ? 'USER' : 'GUEST';
    }
}
