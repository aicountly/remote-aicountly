<?php

declare(strict_types=1);

namespace App\Domain\Device;

use App\Domain\Audit\AuditService;
use App\Domain\Audit\EventType;
use App\Domain\Auth\RemoteIdentity;
use App\Domain\Policy\EffectivePolicy;
use App\Domain\Policy\EffectivePolicyResolver;
use App\Domain\Policy\PermissionCatalog;
use App\Domain\Support\ApiException;
use App\Domain\Support\Clock;
use App\Domain\Support\Ids;
use App\Domain\Support\Presenter;
use CodeIgniter\Database\BaseConnection;
use Config\Remote as RemoteConfig;

/**
 * Devices: enrolling one, listing them, revoking one, and the separate,
 * deliberate act of turning unattended access on.
 *
 * `remote_devices` existed before any of this did, which is the point — the
 * desktop agent is a participant with different capabilities, not a second
 * product with a second identity system.
 *
 * Three properties this class exists to hold:
 *
 *   * **A device belongs to exactly one company.** Enrolment resolves the
 *     enroller's policy *for the company being enrolled into* before it writes
 *     anything, so holding `remote.device.enrol` at one organisation enrols
 *     nothing at another.
 *   * **A public key identifies one device, platform-wide.** Two devices
 *     sharing a key would both pass the same signature check. The database
 *     refuses it; this class turns the refusal into a sentence.
 *   * **Unattended access is its own workflow.** Its own entitlement, its own
 *     company switch, its own permission, its own audit event, its own
 *     timestamp, and its own revocation. It is never a side effect of an
 *     attended session, and it is switched off the moment the device is
 *     revoked or suspended.
 */
class DeviceService
{
    public const STATUS_PENDING   = 'PENDING';
    public const STATUS_ACTIVE    = 'ACTIVE';
    public const STATUS_SUSPENDED = 'SUSPENDED';
    public const STATUS_REVOKED   = 'REVOKED';

    /** How many devices one company may enrol. A ceiling, not a licence model. */
    private const MAX_DEVICES_PER_COMPANY = 500;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly EffectivePolicyResolver $policies,
        private readonly AuditService $audit,
        private readonly RemoteConfig $config,
    ) {
    }

    // ------------------------------------------------------------- enrolment

    /**
     * Enrol this machine, for the signed-in user who is sitting at it.
     *
     * The agent generates its keypair locally and sends only the public half;
     * the private key never leaves the machine and never appears in any request
     * this method could see. What arrives here is a public key, a name and some
     * inventory — all of it useless to anyone who steals it.
     *
     * Re-enrolling the *same* public key is idempotent: an agent that lost its
     * enrolment record but still holds its key gets its row back rather than a
     * duplicate. Re-enrolling a *different* key against a name that already
     * exists creates a second device, because it is one — a reinstalled machine
     * with a new key is not the machine that was enrolled before.
     *
     * @param  array{deviceName: string, publicKey: string, operatingSystem?: ?string,
     *               osVersion?: ?string, architecture?: ?string, hostname?: ?string,
     *               agentVersion?: ?string, deviceType?: ?string,
     *               capabilities?: array<string, mixed>} $input
     * @return array<string, mixed> the device row
     */
    public function enrol(RemoteIdentity $identity, int $companyId, array $input): array
    {
        $policy = $this->policies->resolve($identity, 'COMPANY', $companyId);

        if (! $policy->remoteEnabled) {
            throw ApiException::forbidden(
                'COMPANY_REMOTE_DISABLED',
                'Remote is turned off for this organisation.',
            );
        }

        if (! $policy->can(PermissionCatalog::DEVICE_ENROL)) {
            throw ApiException::forbidden(
                'DEVICE_ENROL_DENIED',
                'You do not have permission to register a device for this organisation.',
                ['permission' => PermissionCatalog::DEVICE_ENROL],
            );
        }

        $publicKey = DeviceSignature::normalisePublicKey((string) ($input['publicKey'] ?? ''));
        if ($publicKey === null) {
            throw ApiException::badRequest(
                'DEVICE_KEY_INVALID',
                'That is not a valid Ed25519 public key.',
            );
        }

        $fingerprint = DeviceSignature::fingerprint($publicKey);

        // Same key, already enrolled? Then this is the same machine, and the
        // honest answer is its existing row — but only to somebody who may see
        // it. Reporting "already enrolled elsewhere" to a stranger would leak
        // that a key is in use at another organisation.
        $existing = $this->db->table('remote_devices')
            ->where('public_key_fingerprint', $fingerprint)
            ->get()
            ->getRowArray();

        if ($existing !== null) {
            if ((int) $existing['company_id'] !== $companyId) {
                throw ApiException::conflict(
                    'DEVICE_KEY_IN_USE',
                    'That device key is already registered. Reinstall the agent to generate a new one.',
                );
            }

            if ((string) $existing['status'] === self::STATUS_REVOKED) {
                throw ApiException::conflict(
                    'DEVICE_REVOKED',
                    'This device was revoked by an administrator. Reinstall the agent to register it again.',
                );
            }

            return $this->refreshEnrolment($this->castRow($existing), $identity, $input);
        }

        $deviceCount = $this->db->table('remote_devices')
            ->where('company_id', $companyId)
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_ACTIVE, self::STATUS_SUSPENDED])
            ->countAllResults();

        if ($deviceCount >= self::MAX_DEVICES_PER_COMPANY) {
            throw ApiException::conflict(
                'DEVICE_LIMIT_REACHED',
                'This organisation has reached its registered device limit. Revoke a device you no longer use.',
            );
        }

        $uuid = Ids::uuid4();

        // The device's *declared* capabilities are stored as they arrived, as
        // an upper bound on what the software can do. They are intersected with
        // the organisation's policy every time a session is created — a device
        // cannot widen anything by editing this JSON.
        $declared = DeviceCapabilities::normaliseDeclaration($input['capabilities'] ?? []);

        $this->db->table('remote_devices')->insert([
            'uuid'                   => $uuid,
            'company_id'             => $companyId,
            'user_id'                => $identity->id,
            'enrolled_by_user_id'    => $identity->id,
            'device_name'            => $this->cleanName($input['deviceName'] ?? ''),
            'device_type'            => $this->deviceType($input['deviceType'] ?? null),
            'operating_system'       => $this->clean($input['operatingSystem'] ?? null, 60),
            'os_version'             => $this->clean($input['osVersion'] ?? null, 60),
            'architecture'           => $this->clean($input['architecture'] ?? null, 16),
            'hostname'               => $this->clean($input['hostname'] ?? null, 160),
            'agent_version'          => $this->clean($input['agentVersion'] ?? null, 32),
            'key_algorithm'          => DeviceSignature::ALGORITHM,
            'public_key'             => $publicKey,
            'public_key_fingerprint' => $fingerprint,
            'capabilities'           => json_encode($declared, JSON_UNESCAPED_SLASHES),
            // ACTIVE straight away: the person doing this is signed in, holds
            // the permission, and is physically at the machine. A pending queue
            // would be an approval step with nobody meaningful to approve it —
            // and unattended access, which is the part that needs deliberation,
            // has its own separate act below.
            'status'                 => self::STATUS_ACTIVE,
            'presence_state'         => 'OFFLINE',
            'unattended_access_enabled' => false,
        ]);

        $device = $this->findByUuidOrFail($uuid);

        $this->audit->recordAudit(
            EventType::DEVICE_ENROLLED,
            $identity->id,
            'USER',
            $companyId,
            null,
            null,
            null,
            [
                'deviceUuid'      => $uuid,
                'deviceName'      => $device['device_name'],
                'operatingSystem' => $device['operating_system'],
                'agentVersion'    => $device['agent_version'],
                // The fingerprint, not the key: enough for an administrator to
                // compare with what the agent displays, and not a credential.
                'keyFingerprint'  => DeviceSignature::displayFingerprint($fingerprint),
            ],
        );

        return $device;
    }

    /**
     * The same machine, same key, enrolling again — an agent upgrade, a
     * reinstall that kept its key, or a rename.
     *
     * @param  array<string, mixed> $device
     * @param  array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function refreshEnrolment(array $device, RemoteIdentity $identity, array $input): array
    {
        $update = [
            'device_name'      => $this->cleanName($input['deviceName'] ?? (string) $device['device_name']),
            'operating_system' => $this->clean($input['operatingSystem'] ?? null, 60) ?? $device['operating_system'],
            'os_version'       => $this->clean($input['osVersion'] ?? null, 60) ?? $device['os_version'],
            'architecture'     => $this->clean($input['architecture'] ?? null, 16) ?? $device['architecture'],
            'hostname'         => $this->clean($input['hostname'] ?? null, 160) ?? $device['hostname'],
            'agent_version'    => $this->clean($input['agentVersion'] ?? null, 32) ?? $device['agent_version'],
            'capabilities'     => json_encode(
                DeviceCapabilities::normaliseDeclaration($input['capabilities'] ?? []),
                JSON_UNESCAPED_SLASHES,
            ),
            'updated_at'       => Clock::now(),
        ];

        // A suspended device that re-enrols with the right key becomes active
        // again; a revoked one never does — that decision was an administrator's
        // and is not undone by reinstalling software.
        if ((string) $device['status'] === self::STATUS_SUSPENDED) {
            $update['status'] = self::STATUS_ACTIVE;
        }

        $this->db->table('remote_devices')->where('id', $device['id'])->update($update);

        $this->audit->recordAudit(
            EventType::DEVICE_UPDATED,
            $identity->id,
            'USER',
            (int) $device['company_id'],
            null,
            null,
            null,
            ['deviceUuid' => (string) $device['uuid'], 'reason' => 'RE_ENROLMENT'],
        );

        return $this->findByUuidOrFail((string) $device['uuid']);
    }

    // ------------------------------------------------------------ management

    /**
     * Every device in a company the caller may see.
     *
     * `remote.device.manage` shows the organisation's devices; without it a
     * person sees only the ones they enrolled themselves. There is no third
     * case — a device nobody can see is a device nobody can revoke.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function forCompany(RemoteIdentity $identity, int $companyId, int $limit = 50, int $offset = 0): array
    {
        $policy = $this->policies->resolve($identity, 'COMPANY', $companyId);

        $builder = $this->db->table('remote_devices d')
            ->select('d.*, i.display_name AS owner_name, e.display_name AS enrolled_by_name')
            ->join('remote_identities i', 'i.id = d.user_id', 'left')
            ->join('remote_identities e', 'e.id = d.enrolled_by_user_id', 'left')
            ->where('d.company_id', $companyId);

        if (! $policy->can(PermissionCatalog::DEVICE_MANAGE)) {
            $builder->where('d.user_id', $identity->id);
        }

        $total = (clone $builder)->countAllResults(false);

        $rows = $builder
            ->orderBy('d.status', 'ASC')
            ->orderBy('d.device_name', 'ASC')
            ->limit(max(1, min(200, $limit)), max(0, $offset))
            ->get()
            ->getResultArray();

        return [
            'items' => array_map(fn (array $row) => $this->castRow($row), $rows),
            'total' => $total,
        ];
    }

    /**
     * One device, if this caller may see it.
     *
     * 404 rather than 403 for a device in another tenant, exactly as sessions
     * do: an id must not be probeable for existence.
     *
     * @return array<string, mixed>
     */
    public function findForUser(RemoteIdentity $identity, string $uuid): array
    {
        $device = $this->findByUuidOrFail($uuid);

        try {
            $policy = $this->policies->resolve($identity, 'COMPANY', (int) $device['company_id']);
        } catch (ApiException) {
            throw ApiException::notFound('That device could not be found.');
        }

        if ($policy->can(PermissionCatalog::DEVICE_MANAGE)) {
            return $device;
        }

        if ($device['user_id'] !== null && (int) $device['user_id'] === $identity->id) {
            return $device;
        }

        throw ApiException::notFound('That device could not be found.');
    }

    /**
     * Rename a device, or suspend/resume it.
     *
     * @param  array{deviceName?: string, status?: string} $input
     * @return array<string, mixed>
     */
    public function update(RemoteIdentity $identity, string $uuid, array $input): array
    {
        $device = $this->findForUser($identity, $uuid);
        $this->assertManageable($identity, $device);

        if ((string) $device['status'] === self::STATUS_REVOKED) {
            throw ApiException::conflict('DEVICE_REVOKED', 'That device has been revoked.');
        }

        $update = [];

        if (isset($input['deviceName']) && is_string($input['deviceName'])) {
            $update['device_name'] = $this->cleanName($input['deviceName']);
        }

        if (isset($input['status']) && is_string($input['status'])) {
            $status = strtoupper($input['status']);
            if (! in_array($status, [self::STATUS_ACTIVE, self::STATUS_SUSPENDED], true)) {
                throw ApiException::badRequest(
                    'DEVICE_STATUS_INVALID',
                    'A device can be made active or suspended here. Revoking is a separate action.',
                );
            }

            $update['status'] = $status;

            // The database refuses unattended access on a non-ACTIVE device, so
            // suspending has to withdraw it in the same statement. That is the
            // intended behaviour, not a workaround: a suspended machine must
            // not remain reachable without anybody at it.
            if ($status === self::STATUS_SUSPENDED) {
                $update['unattended_access_enabled'] = false;
                $update['unattended_enabled_at']     = null;
            }
        }

        if ($update === []) {
            return $device;
        }

        $update['updated_at'] = Clock::now();
        $this->db->table('remote_devices')->where('id', $device['id'])->update($update);

        $this->audit->recordAudit(
            EventType::DEVICE_UPDATED,
            $identity->id,
            'USER',
            (int) $device['company_id'],
            null,
            null,
            null,
            ['deviceUuid' => $uuid, 'changes' => array_keys($update)],
        );

        return $this->findByUuidOrFail($uuid);
    }

    /**
     * Revoke a device, server-side and for good.
     *
     * The key stays in the row so an administrator can still see *which* key
     * was revoked, but the status makes it unusable: `authenticate()` refuses
     * anything that is not ACTIVE, so the agent's next call fails whether or
     * not it still holds an unexpired token.
     *
     * @return array<string, mixed>
     */
    public function revoke(RemoteIdentity $identity, string $uuid, ?string $reason = null): array
    {
        $device = $this->findForUser($identity, $uuid);
        $this->assertManageable($identity, $device);

        if ((string) $device['status'] === self::STATUS_REVOKED) {
            return $device;
        }

        $this->db->table('remote_devices')
            ->where('id', $device['id'])
            ->where('status !=', self::STATUS_REVOKED)
            ->update([
                'status'                    => self::STATUS_REVOKED,
                'unattended_access_enabled' => false,
                'unattended_enabled_at'     => null,
                'presence_state'            => 'OFFLINE',
                'revoked_at'                => Clock::now(),
                'revoked_by_user_id'        => $identity->id,
                'updated_at'                => Clock::now(),
            ]);

        $this->audit->recordAudit(
            EventType::DEVICE_REVOKED,
            $identity->id,
            'USER',
            (int) $device['company_id'],
            null,
            null,
            null,
            ['deviceUuid' => $uuid, 'reason' => $this->clean($reason, 120)],
        );

        return $this->findByUuidOrFail($uuid);
    }

    // ----------------------------------------------------- unattended access

    /**
     * Turn unattended access on for one device.
     *
     * This is deliberately not a flag inside an attended session. It is a
     * distinct call, requiring a distinct permission
     * (`remote.unattended.access`), gated by a distinct company switch
     * (`allow_unattended_access`) and a distinct entitlement
     * (`remote_entitlements.unattended_access`), recorded with who enabled it
     * and when, and visible in both the desktop agent and the web console.
     *
     * The caller must also acknowledge what it means. `confirm` is not
     * ceremony: the agent and the web console both show the same warning, and a
     * request without it is a request that skipped the screen carrying it.
     *
     * @return array<string, mixed>
     */
    public function enableUnattended(RemoteIdentity $identity, string $uuid, bool $confirmed): array
    {
        $device = $this->findForUser($identity, $uuid);
        $policy = $this->policies->resolve($identity, 'COMPANY', (int) $device['company_id']);

        if (! $policy->allowUnattendedAccess) {
            throw ApiException::forbidden(
                'UNATTENDED_ACCESS_NOT_ALLOWED',
                'Unattended access is not enabled for this organisation.',
                ['restrictions' => $policy->restrictions],
            );
        }

        if (! $policy->can(PermissionCatalog::UNATTENDED_ACCESS)) {
            throw ApiException::forbidden(
                'UNATTENDED_ACCESS_DENIED',
                'You do not have permission to enable unattended access.',
                ['permission' => PermissionCatalog::UNATTENDED_ACCESS],
            );
        }

        if ((string) $device['status'] !== self::STATUS_ACTIVE) {
            throw ApiException::conflict(
                'DEVICE_NOT_ACTIVE',
                'Only an active device can be reached without somebody at it.',
            );
        }

        if (! $confirmed) {
            throw ApiException::badRequest(
                'UNATTENDED_CONFIRMATION_REQUIRED',
                'Unattended access lets an authorised colleague connect to this machine when nobody is at it. Confirm to continue.',
            );
        }

        if ($device['unattended_access_enabled'] === true) {
            return $device;
        }

        $this->db->table('remote_devices')->where('id', $device['id'])->update([
            'unattended_access_enabled'     => true,
            'unattended_enabled_at'         => Clock::now(),
            'unattended_enabled_by_user_id' => $identity->id,
            'updated_at'                    => Clock::now(),
        ]);

        $this->audit->recordAudit(
            EventType::UNATTENDED_ACCESS_ENABLED,
            $identity->id,
            'USER',
            (int) $device['company_id'],
            null,
            null,
            null,
            ['deviceUuid' => $uuid, 'deviceName' => $device['device_name']],
        );

        return $this->findByUuidOrFail($uuid);
    }

    /**
     * Turn unattended access off.
     *
     * Two people can do this and both must be able to: the person at the
     * machine (through the agent, using its own device credential — see
     * {@see disableUnattendedByDevice()}) and a company administrator from the
     * web console. Neither route needs the other's cooperation, because
     * "I want my machine to stop being reachable" must never require a ticket.
     *
     * @return array<string, mixed>
     */
    public function disableUnattended(RemoteIdentity $identity, string $uuid): array
    {
        $device = $this->findForUser($identity, $uuid);

        // Deliberately *not* gated on `remote.unattended.access`: taking a
        // capability away is not the same act as granting it, and a person
        // whose permission was withdrawn must still be able to switch off what
        // they turned on while they had it.
        $this->assertOwnerOrManager($identity, $device);

        if ($device['unattended_access_enabled'] !== true) {
            return $device;
        }

        $this->clearUnattended((int) $device['id']);

        $this->audit->recordAudit(
            EventType::UNATTENDED_ACCESS_DISABLED,
            $identity->id,
            'USER',
            (int) $device['company_id'],
            null,
            null,
            null,
            ['deviceUuid' => $uuid, 'by' => 'USER'],
        );

        return $this->findByUuidOrFail($uuid);
    }

    /**
     * The agent switching its own unattended access off, locally.
     *
     * Somebody at the keyboard must be able to stop their machine being
     * reachable without signing in to a web console first.
     *
     * @return array<string, mixed>
     */
    public function disableUnattendedByDevice(DevicePrincipal $principal): array
    {
        $device = $this->findByUuidOrFail($principal->deviceUuid);

        if ($device['unattended_access_enabled'] === true) {
            $this->clearUnattended((int) $device['id']);

            $this->audit->recordAudit(
                EventType::UNATTENDED_ACCESS_DISABLED,
                null,
                'SYSTEM',
                (int) $device['company_id'],
                null,
                null,
                null,
                ['deviceUuid' => $principal->deviceUuid, 'by' => 'DEVICE'],
            );
        }

        return $this->findByUuidOrFail($principal->deviceUuid);
    }

    private function clearUnattended(int $deviceId): void
    {
        $this->db->table('remote_devices')->where('id', $deviceId)->update([
            'unattended_access_enabled'     => false,
            'unattended_enabled_at'         => null,
            'unattended_enabled_by_user_id' => null,
            'updated_at'                    => Clock::now(),
        ]);
    }

    // --------------------------------------------------------------- presence

    /**
     * The device says it is still there.
     *
     * Presence is advisory and is never a permission: a device reporting itself
     * online grants nothing, and a stale ONLINE simply means the connection
     * attempt fails a moment later. What it buys is an honest list — an
     * administrator looking at a device that has not been seen for three days
     * should be told that rather than shown a connect button that will hang.
     *
     * @return array<string, mixed>
     */
    public function recordPresence(DevicePrincipal $principal, string $state, ?string $ip, ?string $agentVersion): array
    {
        $device = $this->findByUuidOrFail($principal->deviceUuid);

        $update = [
            'presence_state' => $state === 'ONLINE' ? 'ONLINE' : 'OFFLINE',
            'last_seen_at'   => Clock::now(),
            'updated_at'     => Clock::now(),
        ];

        if ($ip !== null && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            $update['last_ip'] = $ip;
        }

        $version = $this->clean($agentVersion, 32);
        if ($version !== null) {
            $update['agent_version'] = $version;
        }

        $this->db->table('remote_devices')->where('id', $device['id'])->update($update);

        return $this->findByUuidOrFail($principal->deviceUuid);
    }

    /**
     * Whether a device counts as reachable right now.
     *
     * A device that stopped reporting is offline whatever its stored state
     * says — a crashed agent has no opportunity to write OFFLINE on its way
     * out, so the timestamp is what decides.
     *
     * @param array<string, mixed> $device
     */
    public function isOnline(array $device): bool
    {
        if ((string) ($device['presence_state'] ?? 'OFFLINE') !== 'ONLINE') {
            return false;
        }

        $lastSeen = $device['last_seen_at'] ?? null;
        if ($lastSeen === null) {
            return false;
        }

        return (strtotime((string) $lastSeen) + $this->config->devicePresenceStaleSeconds) > time();
    }

    // -------------------------------------------------------------- lookups

    /** @return array<string, mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        if (! Ids::isUuid($uuid)) {
            return null;
        }

        $row = $this->db->table('remote_devices')->where('uuid', $uuid)->get()->getRowArray();

        return $row === null ? null : $this->castRow($row);
    }

    /** @return array<string, mixed> */
    public function findByUuidOrFail(string $uuid): array
    {
        $device = $this->findByUuid($uuid);
        if ($device === null) {
            throw ApiException::notFound('That device could not be found.');
        }

        return $device;
    }

    /**
     * Postgres hands booleans back as `'t'`/`'f'`, and `(bool) 'f'` is true.
     * Normalising once here is what stops a revoked device reading as enabled.
     *
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function castRow(array $row): array
    {
        $row['unattended_access_enabled'] = Presenter::bool($row['unattended_access_enabled'] ?? false);

        $capabilities = $row['capabilities'] ?? '{}';
        if (is_string($capabilities)) {
            $capabilities = json_decode($capabilities, true) ?: [];
        }
        $row['capabilities'] = DeviceCapabilities::normaliseDeclaration($capabilities);

        return $row;
    }

    /**
     * The capability set a session with this device would actually get.
     *
     * The device's declaration ∧ the organisation's policy. Both halves are
     * needed and neither is sufficient: the agent cannot claim a capability the
     * organisation forbids, and the organisation cannot conjure one the agent
     * does not have.
     *
     * @param  array<string, mixed> $device
     * @return array<string, bool>
     */
    public function effectiveCapabilities(array $device, EffectivePolicy $policy): array
    {
        return DeviceCapabilities::intersect(
            $device['capabilities'] ?? [],
            $policy->desktopCapabilityCeiling(),
        );
    }

    // ------------------------------------------------------------- internals

    /** @param array<string, mixed> $device */
    private function assertManageable(RemoteIdentity $identity, array $device): void
    {
        $policy = $this->policies->resolve($identity, 'COMPANY', (int) $device['company_id']);

        if ($policy->can(PermissionCatalog::DEVICE_MANAGE)) {
            return;
        }

        // Somebody must always be able to revoke their own machine, even
        // without the organisation-wide permission — otherwise a lost laptop
        // waits for an administrator.
        if ($device['user_id'] !== null && (int) $device['user_id'] === $identity->id) {
            return;
        }

        throw ApiException::forbidden(
            'DEVICE_MANAGE_DENIED',
            'You do not have permission to manage this organisation’s devices.',
            ['permission' => PermissionCatalog::DEVICE_MANAGE],
        );
    }

    /** @param array<string, mixed> $device */
    private function assertOwnerOrManager(RemoteIdentity $identity, array $device): void
    {
        $this->assertManageable($identity, $device);
    }

    private function cleanName(string $value): string
    {
        $name = $this->clean($value, 160);

        if ($name === null) {
            throw ApiException::badRequest('DEVICE_NAME_REQUIRED', 'Give this device a name.');
        }

        return $name;
    }

    /**
     * Strip control characters before storing anything a machine reported.
     *
     * A hostname is attacker-controlled input wherever it came from, and it is
     * rendered in a device list an administrator reads.
     */
    private function clean(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $clean = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');

        return $clean === '' ? null : mb_substr($clean, 0, $maxLength);
    }

    private function deviceType(mixed $value): string
    {
        $type = is_string($value) ? strtoupper($value) : '';

        return in_array($type, ['DESKTOP', 'LAPTOP', 'SERVER', 'MOBILE'], true) ? $type : 'DESKTOP';
    }
}
