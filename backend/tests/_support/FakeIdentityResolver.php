<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Auth\IdentityResolver;
use App\Domain\Auth\PortalClient;
use App\Domain\Auth\RemoteIdentity;
use CodeIgniter\Database\BaseConnection;

/**
 * Stands in for the portal during feature tests.
 *
 * The real resolver makes a network call to `my.aicountly.com/validatesession`,
 * which a test must not depend on. Everything downstream — the filter, the
 * request context, every controller — is exercised for real; only the portal
 * round trip is replaced, and the identity it returns is a genuine row in
 * `remote_identities` so foreign keys behave normally.
 *
 * A ses_key of `invalid` resolves to nobody, which is how the tests assert that
 * an unauthenticated request is refused rather than assumed.
 */
final class FakeIdentityResolver extends IdentityResolver
{
    /** @var array<string, RemoteIdentity> */
    private array $identities = [];

    public function __construct(PortalClient $portal, BaseConnection $db)
    {
        parent::__construct($portal, $db, null);
    }

    public function register(string $sesKey, RemoteIdentity $identity): void
    {
        $this->identities[$sesKey] = $identity;
    }

    public function resolveFromSesKey(string $sesKey): ?RemoteIdentity
    {
        return $this->identities[$sesKey] ?? null;
    }
}
