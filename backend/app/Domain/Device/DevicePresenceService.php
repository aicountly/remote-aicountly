<?php

declare(strict_types=1);

namespace App\Domain\Device;

use App\Domain\Audit\AuditService;
use App\Domain\Audit\EventType;
use App\Domain\Auth\RemoteIdentity;
use App\Domain\Policy\EffectivePolicyResolver;
use App\Domain\Policy\PermissionCatalog;
use App\Domain\Session\SessionService;
use App\Domain\Session\SessionStatus;
use App\Domain\Signalling\IceConfigService;
use App\Domain\Signalling\SignallingTokenService;
use App\Domain\Support\ApiException;
use App\Domain\Support\Clock;
use CodeIgniter\Database\BaseConnection;
use Config\Remote as RemoteConfig;

/**
 * How a registered device stays reachable without opening a port on it.
 *
 * The agent holds one **outbound** WSS connection to the existing signalling
 * service, in a room of its own named `device-<uuid>`. Nothing listens on the
 * endpoint; nothing is forwarded to it; a home router needs no configuration.
 *
 * The division of labour is exactly the one the session rooms already use, and
 * it is the reason no second relay protocol exists:
 *
 *   * **this API authorises**, and mints a signed token naming exactly one
 *     room and saying what the connection is for;
 *   * **the signalling service verifies**, and does nothing else. It holds no
 *     database, evaluates no policy, and has no code path that reads a room
 *     name from a client message — so a device cannot subscribe to another
 *     device's room, or to a company's, by asking.
 *
 * Two tokens are issued here and they are not the same thing:
 *
 *   * a **presence token**, for the device itself, obtained with its own
 *     device credential;
 *   * an **invite token**, for a person who has just been authorised to start
 *     an unattended session with that device — so the browser can tell the
 *     agent to join now rather than waiting for its next poll. It is minted
 *     only after {@see DeviceSessionService::startUnattended()} has already
 *     said yes, and it names the same single room.
 */
class DevicePresenceService
{
    /** Room names are `device-` + the device uuid. Sessions use the bare uuid. */
    public const ROOM_PREFIX = 'device-';

    public function __construct(
        private readonly BaseConnection $db,
        private readonly DeviceService $devices,
        private readonly SessionService $sessions,
        private readonly EffectivePolicyResolver $policies,
        private readonly SignallingTokenService $signalling,
        private readonly IceConfigService $ice,
        private readonly AuditService $audit,
        private readonly RemoteConfig $config,
    ) {
    }

    public static function roomFor(string $deviceUuid): string
    {
        return self::ROOM_PREFIX . $deviceUuid;
    }

    /**
     * The device's own presence credential.
     *
     * Deliberately short-lived and re-minted by the agent: a connection must
     * not outlive the authorisation that created it, so a device revoked while
     * connected loses its room at the next refresh — and its next API call
     * immediately, which is the faster of the two.
     *
     * @return array{token: string, url: string, room: string, expiresAt: string, staleAfterSeconds: int}
     */
    public function presenceToken(DevicePrincipal $principal): array
    {
        $principal->assertScope(DevicePrincipal::SCOPE_PRESENCE);

        $device = $this->devices->findByUuidOrFail($principal->deviceUuid);

        if ((string) $device['status'] !== DeviceService::STATUS_ACTIVE) {
            throw ApiException::forbidden('DEVICE_NOT_ACTIVE', 'This device is not active in AICOUNTLY Remote.');
        }

        $token = $this->signalling->issue(
            self::roomFor($principal->deviceUuid),
            $principal->deviceUuid,
            'DEVICE',
            (string) $device['device_name'],
            $device['capabilities'],
            $this->config->devicePresenceTokenTtlSeconds,
            'device',
        );

        return [
            'token'             => $token['token'],
            'url'               => $token['url'],
            'room'              => $token['room'],
            'expiresAt'         => (string) Clock::iso($token['expiresAt']),
            // How long the agent may go between heartbeats before the console
            // stops calling it reachable. Told to the agent rather than
            // hardcoded at both ends, so the two cannot disagree.
            'staleAfterSeconds' => $this->config->devicePresenceStaleSeconds,
        ];
    }

    /**
     * A token letting an authorised person's browser post one invitation into
     * a device's presence room.
     *
     * Minted only after the unattended-access checks have already passed. It is
     * not an authorisation in itself: the agent still re-reads the session from
     * the API before joining it, so a fabricated invite reaches an agent that
     * finds no such session and does nothing.
     *
     * @param  array<string, mixed> $device
     * @return array{token: string, url: string, room: string, expiresAt: string, sessionUuid: string}
     */
    public function inviteToken(RemoteIdentity $identity, array $device, string $sessionUuid): array
    {
        $token = $this->signalling->issue(
            self::roomFor((string) $device['uuid']),
            'invite-' . $sessionUuid,
            'CONTROLLER',
            $identity->displayName,
            ['screen_view' => true],
            // Long enough to open a socket and say one thing.
            120,
            'device',
        );

        return [
            'token'       => $token['token'],
            'url'         => $token['url'],
            'room'        => $token['room'],
            'expiresAt'   => (string) Clock::iso($token['expiresAt']),
            'sessionUuid' => $sessionUuid,
        ];
    }

    /**
     * What the agent needs to know about itself, in one call.
     *
     * Its row, the capability ceiling its organisation currently imposes, the
     * ICE configuration for whatever session it is about to join, and any
     * unattended session waiting for it. The agent renders its Permissions
     * panel from this rather than from anything it decided locally — the
     * server is the authority on what this machine may do, and the agent
     * showing something different would be the agent lying to its user.
     *
     * @return array<string, mixed>
     */
    public function selfDescription(DevicePrincipal $principal): array
    {
        $device = $this->devices->findByUuidOrFail($principal->deviceUuid);
        $policy = $this->policyForDevice($device);

        return [
            'device'   => $device,
            'policy'   => $policy,
            'capabilities' => $this->devices->effectiveCapabilities($device, $policy),
            'iceServers'   => $this->ice->iceServers(),
            'relayAvailable' => $this->ice->hasRelay(),
            'minimumAgentVersion' => $this->config->desktopMinimumAgentVersion,
            'updateFeedUrl'       => $this->config->desktopUpdateFeedUrl !== ''
                ? $this->config->desktopUpdateFeedUrl
                : null,
            'clipboardMaxBytes'   => $this->config->clipboardMaxBytes,
            'presenceIntervalSeconds' => max(15, (int) floor($this->config->devicePresenceStaleSeconds / 3)),
        ];
    }

    /**
     * Ask a machine to restart.
     *
     * Three separate things have to be true, and none of them comes free with
     * remote control:
     *
     *   1. the organisation permits device reboot;
     *   2. the caller holds `remote.control.request` — restarting somebody's
     *      computer is an act of control, not of viewing;
     *   3. there is a live session on that device, with this caller in it.
     *
     * The third is what stops a machine being power-cycled by somebody who is
     * not connected to it. The reboot is recorded *before* it is attempted,
     * because a machine that goes down mid-write cannot record anything
     * afterwards.
     *
     * @return array<string, mixed>
     */
    public function requestReboot(RemoteIdentity $identity, string $deviceUuid, ?string $sessionUuid): array
    {
        $device = $this->devices->findForUser($identity, $deviceUuid);
        $policy = $this->policies->resolve($identity, 'COMPANY', (int) $device['company_id']);

        if (! $policy->allowDeviceReboot) {
            throw ApiException::forbidden(
                'DEVICE_REBOOT_NOT_ALLOWED',
                'Restarting a computer remotely is not enabled for this organisation.',
                ['restrictions' => $policy->restrictions],
            );
        }

        if (! $policy->can(PermissionCatalog::CONTROL_REQUEST)) {
            throw ApiException::forbidden(
                'DEVICE_REBOOT_DENIED',
                'You do not have permission to restart this computer.',
                ['permission' => PermissionCatalog::CONTROL_REQUEST],
            );
        }

        if ($sessionUuid === null) {
            throw ApiException::badRequest(
                'SESSION_REQUIRED',
                'A restart happens inside a session, so that it is recorded against one.',
            );
        }

        $session = $this->sessions->findForUser($sessionUuid, $identity);

        if (! SessionStatus::isLive((string) $session['status'])) {
            throw ApiException::conflict('SESSION_ALREADY_ENDED', 'This Remote session has already finished.');
        }

        if ($session['device_id'] === null || (int) $session['device_id'] !== (int) $device['id']) {
            throw ApiException::conflict(
                'DEVICE_NOT_IN_SESSION',
                'That session is not connected to this device.',
            );
        }

        $controller = $this->db->table('remote_participants')
            ->where('session_id', $session['id'])
            ->where('user_id', $identity->id)
            ->where('device_id', null)
            ->where('control_state', 'GRANTED')
            ->countAllResults();

        if ($controller === 0) {
            throw ApiException::forbidden(
                'CONTROL_NOT_ACTIVE',
                'You need control of this computer before you can restart it.',
            );
        }

        // Written before the attempt, on purpose: a machine that goes down
        // cannot record what happened to it afterwards.
        $this->audit->record(
            $session,
            EventType::DEVICE_REBOOT_REQUESTED,
            $identity->id,
            'USER',
            null,
            null,
            ['deviceUuid' => $deviceUuid, 'deviceName' => (string) $device['device_name']],
        );

        return [
            'accepted'    => true,
            'deviceUuid'  => $deviceUuid,
            'sessionUuid' => (string) $session['uuid'],
            // The instruction itself travels on the session's data channel to
            // the agent, which validates it against this same session before
            // asking Windows to restart. Nothing here reaches the machine.
            'command'     => ['type' => 'reboot', 'sessionUuid' => (string) $session['uuid']],
        ];
    }

    /**
     * The effective policy that governs a device, resolved as its *owner*.
     *
     * A device has no permissions of its own — it acts within what the person
     * who enrolled it may do. Resolving as the owner is what makes an
     * administrator withdrawing that person's grant take effect on the machine
     * as well, without a second place to keep it in step.
     *
     * @param  array<string, mixed> $device
     */
    public function policyForDevice(array $device): \App\Domain\Policy\EffectivePolicy
    {
        $row = $this->db->table('remote_identities')
            ->select('id, platform_uuid, display_name, email, is_support_agent, is_platform_admin')
            ->where('id', (int) $device['user_id'])
            ->get()
            ->getRowArray();

        if ($row === null) {
            throw ApiException::forbidden(
                'DEVICE_OWNER_MISSING',
                'This device is not linked to an AICOUNTLY user any more.',
            );
        }

        return $this->policies->resolve(
            new RemoteIdentity(
                (int) $row['id'],
                (string) $row['platform_uuid'],
                (string) $row['display_name'],
                $row['email'] !== null ? (string) $row['email'] : null,
            ),
            'COMPANY',
            (int) $device['company_id'],
        );
    }
}
