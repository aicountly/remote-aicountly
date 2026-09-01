<?php

declare(strict_types=1);

namespace App\Domain\Auth;

/**
 * Who is calling, as far as Remote is concerned.
 *
 * `id` is Remote's local integer for this person — the value every foreign key
 * in the schema points at. `uuid` is AICOUNTLY's identifier and the only one
 * that means anything outside this product, which is why it, not the local id,
 * is what the API exposes.
 *
 * A guest has no AICOUNTLY identity at all and is represented by
 * {@see GuestIdentity} instead, never by a synthetic row here.
 */
final class RemoteIdentity
{
    public function __construct(
        public readonly int $id,
        public readonly string $uuid,
        public readonly string $displayName,
        public readonly ?string $email = null,
        public readonly bool $isSupportAgent = false,
        public readonly bool $isPlatformAdmin = false,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'uuid'        => $this->uuid,
            'displayName' => $this->displayName,
            'email'       => $this->email,
            'isSupportAgent' => $this->isSupportAgent,
        ];
    }
}
