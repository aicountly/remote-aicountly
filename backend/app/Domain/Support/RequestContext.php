<?php

declare(strict_types=1);

namespace App\Domain\Support;

use App\Domain\Auth\GuestPrincipal;
use App\Domain\Auth\RemoteIdentity;
use App\Domain\Auth\SourceContext;
use App\Domain\Device\DevicePrincipal;

/**
 * Who is making this request.
 *
 * Written once by an auth filter, read everywhere else. Three kinds of caller
 * exist and they are never conflated:
 *
 *   * an **AICOUNTLY user**, authenticated by a portal `ses_key`;
 *   * a **guest**, holding a token bound to one participant in one session;
 *   * a **device**, holding a short-lived credential it obtained by proving
 *     possession of its enrolled private key.
 *
 * `identity()` throws for the other two rather than returning null, so a
 * controller written for signed-in users cannot silently treat a machine as a
 * person. Endpoints that genuinely serve a device ask for {@see device()}
 * explicitly, and there is no code path that turns one into the other.
 */
final class RequestContext
{
    private ?RemoteIdentity $identity = null;
    private ?GuestPrincipal $guest = null;
    private ?SourceContext $sourceContext = null;
    private ?DevicePrincipal $device = null;

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

    public function setDevice(?DevicePrincipal $device): void
    {
        $this->device = $device;
    }

    /** @throws ApiException when the caller is a guest or is not signed in */
    public function identity(): RemoteIdentity
    {
        if ($this->identity === null) {
            throw ApiException::unauthenticated(match (true) {
                $this->guest !== null  => 'Guests cannot use this part of AICOUNTLY Remote.',
                $this->device !== null => 'A device credential cannot be used for this.',
                default                => 'Sign in to continue.',
            });
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

    public function device(): ?DevicePrincipal
    {
        return $this->device;
    }

    /** @throws ApiException when the caller is not an authenticated device */
    public function requireDevice(): DevicePrincipal
    {
        if ($this->device === null) {
            throw ApiException::unauthenticated('This endpoint is for a registered AICOUNTLY Remote device.');
        }

        return $this->device;
    }

    public function sourceContext(): ?SourceContext
    {
        return $this->sourceContext;
    }

    public function isAuthenticated(): bool
    {
        return $this->identity !== null || $this->guest !== null || $this->device !== null;
    }

    /** The name to show for whoever this is. */
    public function displayName(): string
    {
        return $this->identity?->displayName ?? $this->guest?->displayName ?? 'Guest';
    }

    public function actorType(): string
    {
        return match (true) {
            $this->identity !== null => 'USER',
            $this->device !== null   => 'DEVICE',
            default                  => 'GUEST',
        };
    }
}
