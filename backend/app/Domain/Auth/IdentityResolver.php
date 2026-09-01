<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use CodeIgniter\Cache\CacheInterface;
use CodeIgniter\Database\BaseConnection;

/**
 * Turns a Bearer `ses_key` into a {@see RemoteIdentity}.
 *
 * The portal is the authority. This class only projects its answer into
 * `remote_identities` so Remote has a stable integer to hang foreign keys on,
 * and a display name to render in a session list two years from now.
 *
 * The portal answer is cached briefly against a hash of the ses_key: without
 * it, a page that makes six API calls makes six `validatesession` round trips.
 * The window is short enough that a revoked key stops working promptly, and the
 * ses_key itself is never used as a cache key in clear — only its SHA-256.
 */
class IdentityResolver
{
    private const CACHE_TTL_SECONDS = 60;

    public function __construct(
        private readonly PortalClient $portal,
        private readonly BaseConnection $db,
        private readonly ?CacheInterface $cache = null,
    ) {
    }

    public function resolveFromSesKey(string $sesKey): ?RemoteIdentity
    {
        if ($sesKey === '') {
            return null;
        }

        $cacheKey = 'remote_identity_' . hash('sha256', $sesKey);
        $cached   = $this->cache?->get($cacheKey);
        if (is_array($cached) && isset($cached['id'], $cached['uuid'])) {
            return $this->hydrate($cached);
        }

        $payload = $this->portal->validateSesKey($sesKey);
        if ($payload === null) {
            return null;
        }

        $identity = $this->projectFromPortalPayload($payload);
        if ($identity === null) {
            return null;
        }

        $this->cache?->save($cacheKey, [
            'id'              => $identity->id,
            'uuid'            => $identity->uuid,
            'displayName'     => $identity->displayName,
            'email'           => $identity->email,
            'isSupportAgent'  => $identity->isSupportAgent,
            'isPlatformAdmin' => $identity->isPlatformAdmin,
        ], self::CACHE_TTL_SECONDS);

        return $identity;
    }

    /**
     * Upsert the portal's answer into the local projection.
     *
     * The portal's field names have varied across AICOUNTLY products, so each
     * value is read from the first key that is actually present rather than
     * assuming one shape and failing silently on another.
     *
     * @param array<string, mixed> $payload
     */
    public function projectFromPortalPayload(array $payload): ?RemoteIdentity
    {
        // Some portal endpoints nest the user under `data` or `user`.
        foreach (['data', 'user', 'result'] as $wrapper) {
            if (isset($payload[$wrapper]) && is_array($payload[$wrapper])) {
                $payload = array_merge($payload, $payload[$wrapper]);
            }
        }

        $uuid = $this->firstString($payload, ['uuid_aictly', 'uuid_aicountly', 'uuid', 'user_uuid', 'sub']);
        if ($uuid === null) {
            return null;
        }

        $platformUserId = $this->firstInt($payload, ['user_id', 'userid', 'id', 'uid']);
        $displayName    = $this->firstString($payload, ['name', 'full_name', 'display_name', 'username', 'first_name']) ?? '';
        $email          = $this->firstString($payload, ['email', 'email_id', 'user_email']);

        return $this->upsert($uuid, $platformUserId, $displayName, $email);
    }

    /**
     * Insert or refresh one identity row and return it.
     *
     * Concurrency: two tabs booting at once both try to create the row. The
     * unique index on `platform_uuid` is what decides, and the losing insert is
     * turned into an update by ON CONFLICT rather than surfacing as a 500.
     */
    public function upsert(string $uuid, ?int $platformUserId, string $displayName, ?string $email): RemoteIdentity
    {
        // The local `id` always comes from the sequence. AICOUNTLY's own numeric
        // user id is kept beside it in `platform_user_id` for cross-product
        // correlation — adopting it as the primary key would let a later
        // sequence value collide with an id the platform had already used.
        $sql = <<<'SQL'
            INSERT INTO remote_identities (platform_uuid, platform_user_id, display_name, email, last_seen_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, NOW(), NOW(), NOW())
            ON CONFLICT (platform_uuid) DO UPDATE
                SET display_name = CASE WHEN EXCLUDED.display_name <> '' THEN EXCLUDED.display_name ELSE remote_identities.display_name END,
                    email        = COALESCE(EXCLUDED.email, remote_identities.email),
                    platform_user_id = COALESCE(remote_identities.platform_user_id, EXCLUDED.platform_user_id),
                    last_seen_at = NOW(),
                    updated_at   = NOW()
            RETURNING id, platform_uuid, display_name, email, is_support_agent, is_platform_admin
            SQL;

        $row = $this->db->query($sql, [
            $uuid,
            $platformUserId,
            $displayName,
            $email,
        ])->getRowArray();

        if ($row === null) {
            // ON CONFLICT ... RETURNING gives no row only if the insert lost a
            // race *and* the conflicting row was deleted in between. Re-read.
            $row = $this->db->table('remote_identities')
                ->select('id, platform_uuid, display_name, email, is_support_agent, is_platform_admin')
                ->where('platform_uuid', $uuid)
                ->get()
                ->getRowArray();
        }

        return $this->hydrate([
            'id'              => (int) $row['id'],
            'uuid'            => (string) $row['platform_uuid'],
            'displayName'     => (string) $row['display_name'],
            'email'           => $row['email'] !== null ? (string) $row['email'] : null,
            'isSupportAgent'  => $this->truthy($row['is_support_agent']),
            'isPlatformAdmin' => $this->truthy($row['is_platform_admin']),
        ]);
    }

    public function findById(int $id): ?RemoteIdentity
    {
        $row = $this->db->table('remote_identities')
            ->select('id, platform_uuid, display_name, email, is_support_agent, is_platform_admin')
            ->where('id', $id)
            ->get()
            ->getRowArray();

        if ($row === null) {
            return null;
        }

        return $this->hydrate([
            'id'              => (int) $row['id'],
            'uuid'            => (string) $row['platform_uuid'],
            'displayName'     => (string) $row['display_name'],
            'email'           => $row['email'] !== null ? (string) $row['email'] : null,
            'isSupportAgent'  => $this->truthy($row['is_support_agent']),
            'isPlatformAdmin' => $this->truthy($row['is_platform_admin']),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function hydrate(array $data): RemoteIdentity
    {
        $name = (string) ($data['displayName'] ?? '');

        return new RemoteIdentity(
            (int) $data['id'],
            (string) $data['uuid'],
            $name !== '' ? $name : 'AICOUNTLY user',
            isset($data['email']) && $data['email'] !== null ? (string) $data['email'] : null,
            (bool) ($data['isSupportAgent'] ?? false),
            (bool) ($data['isPlatformAdmin'] ?? false),
        );
    }

    /** @param array<string, mixed> $payload @param list<string> $keys */
    private function firstString(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
            if (is_int($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $payload @param list<string> $keys */
    private function firstInt(array $payload, array $keys): ?int
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;
            if (is_int($value) && $value > 0) {
                return $value;
            }
            if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return null;
    }

    private function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === 't' || $value === '1' || $value === 'true';
    }
}
