<?php

declare(strict_types=1);

namespace App\Domain\Device;

use App\Domain\Audit\AuditService;
use App\Domain\Audit\EventType;
use App\Domain\Auth\RemoteIdentity;
use App\Domain\Policy\EffectivePolicyResolver;
use App\Domain\Policy\PermissionCatalog;
use App\Domain\Support\ApiException;
use App\Domain\Support\Clock;
use CodeIgniter\Database\BaseConnection;
use Config\Remote as RemoteConfig;

/**
 * Device-code sign-in: how a desktop agent gets enrolled without ever holding
 * a portal credential, and without depending on the portal accepting a
 * loopback `returnUrl` (docs/desktop/DEVICE_ENROLMENT.md).
 *
 * ```
 *   POST /desktop-signin/start    { publicKey, deviceName, ... }   agent, unauthenticated
 *        → { userCode, deviceCode, verificationUriComplete, expiresAt }
 *
 *   agent opens verificationUriComplete in the system browser
 *
 *   POST /desktop-signin/confirm  { userCode, companyId }          browser, api-auth
 *   POST /desktop-signin/deny     { userCode }                     browser, api-auth
 *
 *   POST /desktop-signin/poll     { deviceCode }                   agent, unauthenticated
 *        → { status: pending | denied | expired | confirmed, device? }
 * ```
 *
 * `device_code` is the security property of the poll endpoint, exactly as a
 * challenge nonce is for {@see DeviceAuthenticationService}: 32 random bytes,
 * known only to the process that called `start`, so answering the poll is
 * proof of being that process — nobody who merely saw the short `user_code`
 * over someone's shoulder can act as the agent.
 *
 * The row is spent exactly once, on the same guarded `UPDATE … WHERE status =
 * 'CONFIRMED'` pattern used to spend a device auth nonce, so two polls racing
 * the moment a person confirms cannot both enrol a device.
 */
class DesktopSignInService
{
    private const USER_CODE_ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    public function __construct(
        private readonly BaseConnection $db,
        private readonly DeviceService $devices,
        private readonly EffectivePolicyResolver $policies,
        private readonly AuditService $audit,
        private readonly RemoteConfig $config,
    ) {
    }

    /**
     * Start a sign-in. Unauthenticated — the agent has no credential of any
     * kind yet, which is the entire reason this exists.
     *
     * @param array<string, mixed> $input the same fields `/devices/enrol` takes
     * @return array{userCode: string, deviceCode: string, verificationUri: string,
     *               verificationUriComplete: string, expiresAt: string, intervalSeconds: int}
     */
    public function start(array $input, ?string $ip): array
    {
        // Validated up front rather than left until a person has already gone
        // through the confirmation step, minutes later, only to learn the key
        // the agent sent was never usable.
        $publicKey = DeviceSignature::normalisePublicKey((string) ($input['publicKey'] ?? ''));
        if ($publicKey === null) {
            throw ApiException::badRequest('DEVICE_KEY_INVALID', 'That is not a valid Ed25519 public key.');
        }
        $input['publicKey'] = $publicKey;

        $this->sweepExpired();

        $deviceCode = bin2hex(random_bytes(32));
        $userCode   = $this->uniqueUserCode();
        $expiresAt  = Clock::in($this->config->desktopSignInCodeTtlSeconds);

        $this->db->table('remote_desktop_signin_codes')->insert([
            'device_code'    => $deviceCode,
            'user_code'      => $userCode,
            'device_label'   => $this->stringField($input, 'deviceName', 160),
            'device_payload' => json_encode($input, JSON_THROW_ON_ERROR),
            'requested_ip'   => $ip !== null && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null,
            'expires_at'     => $expiresAt,
        ]);

        $verificationUri = $this->config->appUrl . '/desktop-signin';

        return [
            'userCode'                => $userCode,
            'deviceCode'              => $deviceCode,
            'verificationUri'         => $verificationUri,
            'verificationUriComplete' => $verificationUri . '?code=' . rawurlencode($userCode),
            'expiresAt'               => (string) Clock::iso($expiresAt),
            'intervalSeconds'         => $this->config->desktopSignInPollIntervalSeconds,
        ];
    }

    /**
     * Poll for an outcome, and claim + enrol the moment there is one to claim.
     *
     * Deliberately one call rather than "poll, then separately claim": there is
     * nothing a legitimate caller would ever do between seeing `confirmed` and
     * claiming it, and a second call would only be a second place for the same
     * device-code secret to travel.
     *
     * @return array{status: string, device?: array<string, mixed>}
     */
    public function poll(string $deviceCode): array
    {
        $deviceCode = strtolower(trim($deviceCode));
        if (preg_match('/^[0-9a-f]{64}$/', $deviceCode) !== 1) {
            throw ApiException::notFound('That sign-in code could not be found.');
        }

        $row = $this->db->table('remote_desktop_signin_codes')
            ->where('device_code', $deviceCode)
            ->get()
            ->getRowArray();

        if ($row === null) {
            throw ApiException::notFound('That sign-in code could not be found.');
        }

        if (Clock::hasPassed($row['expires_at'])) {
            return ['status' => 'expired'];
        }

        if ($row['status'] === 'DENIED') {
            return ['status' => 'denied'];
        }

        if ($row['status'] === 'PENDING') {
            return ['status' => 'pending'];
        }

        // CLAIMED already — a retried poll after a connection hiccup on the
        // first success. Idempotent: hand back the same device rather than
        // refusing a caller who never learned it already worked.
        if ($row['status'] === 'CLAIMED') {
            $payload   = $this->decodePayload($row['device_payload']);
            $publicKey = DeviceSignature::normalisePublicKey((string) ($payload['publicKey'] ?? ''));
            $device    = $publicKey !== null ? $this->devices->findByFingerprint(DeviceSignature::fingerprint($publicKey)) : null;

            return $device !== null
                ? ['status' => 'confirmed', 'device' => $device, 'companyName' => $row['company_name']]
                : ['status' => 'expired'];
        }

        // CONFIRMED: spend the row and enrol, in that order and in one
        // statement's worth of exclusivity — the same shape as spending a
        // device auth nonce, so two polls racing this moment cannot both
        // enrol.
        $this->db->table('remote_desktop_signin_codes')
            ->where('id', $row['id'])
            ->where('status', 'CONFIRMED')
            ->update(['status' => 'CLAIMED', 'claimed_at' => Clock::now()]);

        if ($this->db->affectedRows() !== 1) {
            // Lost the race to another poll. Its response already carries the
            // device; this one only needs to say the same thing happened.
            return $this->poll($deviceCode);
        }

        $identity = $this->identityFor((int) $row['identity_id']);
        $payload  = $this->decodePayload($row['device_payload']);

        $device = $this->devices->enrol($identity, (int) $row['company_id'], $payload);

        return ['status' => 'confirmed', 'device' => $device, 'companyName' => $row['company_name']];
    }

    /**
     * A person, signed in through the browser's own working portal session,
     * confirming that the code on their screen matches the one on the machine.
     */
    public function confirmByUserCode(RemoteIdentity $identity, string $userCode, int $companyId, ?string $ip): array
    {
        $userCode = $this->normaliseUserCode($userCode);

        // The same checks `DeviceService::enrol()` makes, run here too so a
        // refusal is seen on the screen the person is looking at rather than
        // minutes later on a machine they may not be watching. Enrolling
        // still re-checks everything itself — this is a courtesy, not the
        // authority.
        $policy = $this->policies->resolve($identity, 'COMPANY', $companyId);
        if (! $policy->remoteEnabled) {
            throw ApiException::forbidden('COMPANY_REMOTE_DISABLED', 'Remote is turned off for this organisation.');
        }
        if (! $policy->can(PermissionCatalog::DEVICE_ENROL)) {
            throw ApiException::forbidden(
                'DEVICE_ENROL_DENIED',
                'You do not have permission to register a device for this organisation.',
                ['permission' => PermissionCatalog::DEVICE_ENROL],
            );
        }

        $row = $this->liveRowByUserCode($userCode, ['PENDING']);

        $this->db->table('remote_desktop_signin_codes')
            ->where('id', $row['id'])
            ->where('status', 'PENDING')
            ->update([
                'status'       => 'CONFIRMED',
                'identity_id'  => $identity->id,
                'company_id'   => $companyId,
                'company_name' => $policy->companyName,
                'confirmed_at' => Clock::now(),
                'confirmed_ip' => $ip !== null && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null,
            ]);

        if ($this->db->affectedRows() !== 1) {
            throw ApiException::conflict('SIGNIN_CODE_ALREADY_HANDLED', 'That code has already been used or denied.');
        }

        $this->audit->recordAudit(
            EventType::DESKTOP_SIGNIN_CONFIRMED,
            $identity->id,
            'USER',
            $companyId,
            null,
            null,
            null,
            ['deviceLabel' => $row['device_label']],
        );

        return ['status' => 'confirmed', 'deviceLabel' => $row['device_label']];
    }

    /** Declining, or cancelling one already confirmed but not yet claimed. */
    public function denyByUserCode(RemoteIdentity $identity, string $userCode): array
    {
        $userCode = $this->normaliseUserCode($userCode);
        $row      = $this->liveRowByUserCode($userCode, ['PENDING', 'CONFIRMED']);

        // Once a code carries an identity, only that person may withdraw it —
        // otherwise anyone who happened to be signed in and saw the short
        // code could cancel someone else's already-confirmed sign-in.
        if ($row['identity_id'] !== null && (int) $row['identity_id'] !== $identity->id) {
            throw ApiException::notFound('That sign-in code could not be found.');
        }

        $this->db->table('remote_desktop_signin_codes')
            ->where('id', $row['id'])
            ->whereIn('status', ['PENDING', 'CONFIRMED'])
            ->update(['status' => 'DENIED']);

        if ($this->db->affectedRows() !== 1) {
            throw ApiException::conflict('SIGNIN_CODE_ALREADY_HANDLED', 'That code has already been used or denied.');
        }

        $this->audit->recordAudit(
            EventType::DESKTOP_SIGNIN_DENIED,
            $identity->id,
            'USER',
            $row['company_id'] !== null ? (int) $row['company_id'] : null,
            null,
            null,
            null,
            ['deviceLabel' => $row['device_label']],
        );

        return ['status' => 'denied'];
    }

    /** Delete rows nobody can act on any more. Opportunistic, like the device-challenge sweep. */
    public function sweepExpired(): void
    {
        $this->db->table('remote_desktop_signin_codes')
            ->where('expires_at <', Clock::in(-3600))
            ->delete();
    }

    // ------------------------------------------------------------- internals

    /** @param list<string> $liveStatuses */
    private function liveRowByUserCode(string $userCode, array $liveStatuses): array
    {
        $row = $this->db->table('remote_desktop_signin_codes')
            ->where('user_code', $userCode)
            ->whereIn('status', $liveStatuses)
            ->where('expires_at >', Clock::now())
            ->get()
            ->getRowArray();

        if ($row === null) {
            throw ApiException::notFound('That code has expired or could not be found. Ask for a new one from the application.');
        }

        return $row;
    }

    private function identityFor(int $identityId): RemoteIdentity
    {
        $identity = \Config\Services::identityResolver()->findById($identityId);
        if ($identity === null) {
            // The identity row is only ever deleted along with this one —
            // ON DELETE CASCADE — so this means the person's account itself
            // is gone between confirming and the agent's next poll.
            throw ApiException::notFound('The person who confirmed this sign-in could no longer be found.');
        }

        return $identity;
    }

    /** @return array<string, mixed> */
    private function decodePayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        $decoded = is_string($payload) ? json_decode($payload, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $input */
    private function stringField(array $input, string $key, int $maxLength): ?string
    {
        $value = $input[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, $maxLength);
    }

    private function normaliseUserCode(string $userCode): string
    {
        $stripped = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $userCode) ?? '');
        if (strlen($stripped) !== 8) {
            throw ApiException::notFound('That code has expired or could not be found. Ask for a new one from the application.');
        }

        return substr($stripped, 0, 4) . '-' . substr($stripped, 4, 4);
    }

    /** Retried, bounded, on the vanishingly unlikely chance of a live collision. */
    private function uniqueUserCode(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = $this->randomUserCode();

            $collision = $this->db->table('remote_desktop_signin_codes')
                ->where('user_code', $code)
                ->whereIn('status', ['PENDING', 'CONFIRMED'])
                ->countAllResults();

            if ($collision === 0) {
                return $code;
            }
        }

        throw ApiException::unavailable('SIGNIN_CODE_UNAVAILABLE', 'Could not allocate a sign-in code. Please try again.');
    }

    private function randomUserCode(): string
    {
        $alphabet = self::USER_CODE_ALPHABET;
        $length   = strlen($alphabet);

        $chars = '';
        for ($i = 0; $i < 8; $i++) {
            $chars .= $alphabet[random_int(0, $length - 1)];
        }

        return substr($chars, 0, 4) . '-' . substr($chars, 4, 4);
    }
}
