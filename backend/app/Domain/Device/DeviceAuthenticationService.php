<?php

declare(strict_types=1);

namespace App\Domain\Device;

use App\Domain\Audit\AuditService;
use App\Domain\Audit\EventType;
use App\Domain\Support\ApiException;
use App\Domain\Support\Clock;
use CodeIgniter\Database\BaseConnection;
use Config\Remote as RemoteConfig;

/**
 * Proof of possession: how an enrolled device proves it is itself.
 *
 * ```
 *   POST /devices/auth/challenge      { deviceUuid }
 *        → { nonce, issuedAt, expiresAt }        single use, from a CSPRNG
 *
 *   agent signs DeviceSignature::challengePayload(...) with its private key
 *
 *   POST /devices/auth/verify         { deviceUuid, nonce, issuedAt, signature }
 *        → { token, expiresAt, device }          short-lived, scoped
 * ```
 *
 * Why this and not a machine API key: a bearer secret that identifies a machine
 * is a secret that has to be stored somewhere on that machine and transmitted
 * on every call. The private key here is generated on the device, protected by
 * the operating system (DPAPI on Windows), never transmitted, and never
 * recoverable from anything Remote stores. A dump of `remote_devices` yields
 * public keys, which authenticate nobody.
 *
 * The properties this class is shaped to hold, each with the mechanism:
 *
 *   * **the challenge is unpredictable** — 32 bytes from `random_bytes`;
 *   * **it expires** — `expires_at`, checked on redemption;
 *   * **it is single use** — `UPDATE … WHERE consumed_at IS NULL`, so two
 *     simultaneous replays produce exactly one success;
 *   * **it is bound to one device** — the nonce row names the device, and the
 *     signed payload names it again, so a challenge issued for one device
 *     cannot be answered by another;
 *   * **a revoked device fails** — status is re-read at verification and at
 *     every subsequent call, not only at issuance;
 *   * **failures are recorded without secrets** — the audit entry says a
 *     signature did not check out, never what was presented.
 */
class DeviceAuthenticationService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly DeviceService $devices,
        private readonly AuditService $audit,
        private readonly RemoteConfig $config,
    ) {
    }

    /**
     * Issue a single-use challenge for a device.
     *
     * The response is identical in shape whether or not the device exists as
     * far as an unauthenticated caller can tell — except that a device which
     * does not exist gets a 404, the same answer any unknown resource gets.
     * There is nothing to learn from a nonce: it is worthless without the key.
     *
     * @return array{nonce: string, issuedAt: int, expiresAt: string, audience: string}
     */
    public function challenge(string $deviceUuid, ?string $ip): array
    {
        $device = $this->devices->findByUuidOrFail($deviceUuid);

        $this->assertUsable($device, 'CHALLENGE');

        // Outstanding challenges for this device are dropped: an agent that
        // asks for a second one has abandoned the first, and leaving a hundred
        // live nonces per device would widen the replay window for no reason.
        $this->db->table('remote_device_challenges')
            ->where('device_id', $device['id'])
            ->where('consumed_at', null)
            ->update(['consumed_at' => Clock::now()]);

        $nonce     = bin2hex(random_bytes(32));
        $issuedAt  = time();
        $expiresAt = Clock::in($this->config->deviceChallengeTtlSeconds);

        $this->db->table('remote_device_challenges')->insert([
            'device_id'  => $device['id'],
            'nonce'      => $nonce,
            'issued_ip'  => $ip !== null && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null,
            'expires_at' => $expiresAt,
        ]);

        return [
            'nonce'     => $nonce,
            'issuedAt'  => $issuedAt,
            'expiresAt' => (string) Clock::iso($expiresAt),
            'audience'  => DeviceSignature::AUDIENCE,
        ];
    }

    /**
     * Verify a signed challenge and mint a short-lived device credential.
     *
     * @return array{token: string, expiresAt: string, scopes: list<string>, device: array<string, mixed>}
     */
    public function verify(string $deviceUuid, string $nonce, int $issuedAt, string $signature): array
    {
        $device = $this->devices->findByUuidOrFail($deviceUuid);

        $this->assertUsable($device, 'VERIFY');

        $nonce = strtolower(trim($nonce));
        if (preg_match('/^[0-9a-f]{64}$/', $nonce) !== 1) {
            $this->recordFailure($device, 'NONCE_MALFORMED');

            throw $this->rejected();
        }

        // Spend the nonce first, and spend it with the same statement that
        // checks it. A SELECT-then-UPDATE would let two replays of one
        // signature both pass between the two queries.
        $this->db->table('remote_device_challenges')
            ->where('nonce', $nonce)
            ->where('device_id', $device['id'])
            ->where('consumed_at', null)
            ->where('expires_at >', Clock::now())
            ->update(['consumed_at' => Clock::now()]);

        if ($this->db->affectedRows() !== 1) {
            // Unknown, already used, expired, or issued for a different device.
            // All four are the same answer to the caller.
            $this->recordFailure($device, 'CHALLENGE_NOT_REDEEMABLE');

            throw $this->rejected();
        }

        // A signature is over an issued-at the agent chose. Bounding it stops a
        // signature made long ago from being useful, without requiring the two
        // clocks to agree exactly.
        $skew = $this->config->deviceChallengeTtlSeconds + 60;
        if ($issuedAt < time() - $skew || $issuedAt > time() + 60) {
            $this->recordFailure($device, 'ISSUED_AT_OUT_OF_RANGE');

            throw $this->rejected();
        }

        $publicKey = (string) ($device['public_key'] ?? '');
        if ($publicKey === '') {
            $this->recordFailure($device, 'NO_ENROLLED_KEY');

            throw $this->rejected();
        }

        $payload = DeviceSignature::challengePayload($deviceUuid, $nonce, $issuedAt);

        if (! DeviceSignature::verify($payload, $signature, $publicKey)) {
            $this->recordFailure($device, 'SIGNATURE_INVALID');

            throw $this->rejected();
        }

        $expiresAt = time() + $this->config->deviceTokenTtlSeconds;
        $scopes    = DevicePrincipal::agentScopes();

        $token = DevicePrincipal::issue(
            $this->config,
            $deviceUuid,
            (int) $device['company_id'],
            $scopes,
            $expiresAt,
        );

        $this->db->table('remote_devices')->where('id', $device['id'])->update([
            'last_authenticated_at' => Clock::now(),
            'last_seen_at'          => Clock::now(),
            'updated_at'            => Clock::now(),
        ]);

        // Recorded without the token and without the signature: the audit trail
        // says a device authenticated, never with what (§60).
        $this->audit->recordAudit(
            EventType::DEVICE_AUTHENTICATED,
            null,
            'SYSTEM',
            (int) $device['company_id'],
            null,
            null,
            null,
            ['deviceUuid' => $deviceUuid, 'deviceName' => $device['device_name']],
        );

        return [
            'token'     => $token,
            'expiresAt' => (string) Clock::iso($expiresAt),
            'scopes'    => $scopes,
            'device'    => $this->devices->findByUuidOrFail($deviceUuid),
        ];
    }

    /**
     * Re-read the device behind a token and refuse anything not ACTIVE.
     *
     * Called on every device-authenticated request, not only at issuance. That
     * is what makes server-side revocation immediate rather than "within five
     * minutes": an administrator revoking a device stops it on its next call,
     * whatever unexpired token it is holding.
     *
     * @return array<string, mixed>
     */
    public function requireActiveDevice(DevicePrincipal $principal): array
    {
        $device = $this->devices->findByUuid($principal->deviceUuid);

        if ($device === null || (string) $device['status'] !== DeviceService::STATUS_ACTIVE) {
            throw ApiException::unauthenticated('This device is no longer registered for AICOUNTLY Remote.');
        }

        // The token names a company. If the device has since moved — or the
        // token was minted against a stale row — the two must agree.
        if ((int) $device['company_id'] !== $principal->companyId) {
            throw ApiException::unauthenticated('This device credential is no longer valid.');
        }

        return $device;
    }

    /**
     * Delete challenges nobody can redeem any more.
     *
     * Called opportunistically from the challenge endpoint rather than from a
     * scheduler, for the same reason sessions expire on read: cPanel provides
     * no reliable one.
     */
    public function sweepExpiredChallenges(): void
    {
        $this->db->table('remote_device_challenges')
            ->where('expires_at <', Clock::in(-3600))
            ->delete();
    }

    // ------------------------------------------------------------- internals

    /** @param array<string, mixed> $device */
    private function assertUsable(array $device, string $stage): void
    {
        $status = (string) $device['status'];

        if ($status === DeviceService::STATUS_ACTIVE) {
            return;
        }

        $this->recordFailure($device, 'DEVICE_' . $status, $stage);

        throw ApiException::forbidden(
            'DEVICE_NOT_ACTIVE',
            $status === DeviceService::STATUS_REVOKED
                ? 'This device has been revoked. Register it again from AICOUNTLY Remote.'
                : 'This device is not active in AICOUNTLY Remote.',
            ['status' => $status],
        );
    }

    /**
     * One message for every failure.
     *
     * Distinguishing "wrong signature" from "expired nonce" in the response
     * tells an attacker which half to work on, and neither is information the
     * legitimate agent needs — it retries the whole exchange either way.
     */
    private function rejected(): ApiException
    {
        return ApiException::forbidden(
            'DEVICE_AUTH_FAILED',
            'This device could not be verified. Ask the agent to register again.',
        );
    }

    /** @param array<string, mixed> $device */
    private function recordFailure(array $device, string $reason, string $stage = 'VERIFY'): void
    {
        $this->audit->recordAudit(
            EventType::DEVICE_AUTH_FAILED,
            null,
            'SYSTEM',
            $device['company_id'] !== null ? (int) $device['company_id'] : null,
            null,
            null,
            null,
            [
                'deviceUuid' => (string) $device['uuid'],
                'stage'      => $stage,
                // A machine reason, never the nonce, the signature or the key.
                'reason'     => $reason,
            ],
        );
    }
}
