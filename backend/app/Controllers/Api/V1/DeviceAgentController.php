<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Domain\Device\DevicePrincipal;
use App\Domain\Support\ApiException;
use App\Domain\Support\Presenter;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * The endpoints a desktop agent calls with its own device credential.
 *
 * Kept apart from {@see DeviceController} on purpose. Everything here is
 * behind the `device-auth` filter and `$this->identity()` throws throughout —
 * a machine is never treated as the person who enrolled it, so there is no
 * route by which an agent could read a session, administer a company or see
 * another device.
 *
 * What an agent can do here is exactly five things: say it is still there,
 * find out what it is currently permitted to do, pick up an unattended session
 * it has been asked to host, report what the person at the machine decided
 * about remote control, and switch its own unattended access off.
 */
class DeviceAgentController extends BaseApiController
{
    /**
     * `POST /devices/auth/challenge` — unauthenticated by design.
     *
     * A nonce is worthless without the private key, so there is nothing here to
     * protect with a credential. It is rate-limited because generating them in
     * a loop is the one way to put load on this from outside.
     */
    public function challenge(): ResponseInterface
    {
        $body       = $this->body();
        $deviceUuid = $this->requiredString($body, 'deviceUuid', 64);

        $auth = Services::deviceAuthenticationService();
        $auth->sweepExpiredChallenges();

        return $this->ok($auth->challenge($deviceUuid, $this->clientIp()));
    }

    /**
     * `POST /devices/auth/verify` — also unauthenticated, because the signature
     * *is* the authentication.
     */
    public function verify(): ResponseInterface
    {
        $body = $this->body();

        $issuedAt = $this->optionalInt($body, 'issuedAt');
        if ($issuedAt === null) {
            throw ApiException::badRequest('VALIDATION_FAILED', 'Some of the details sent were not valid.', [
                'fields' => ['issuedAt' => 'This is required.'],
            ]);
        }

        $result = Services::deviceAuthenticationService()->verify(
            $this->requiredString($body, 'deviceUuid', 64),
            $this->requiredString($body, 'nonce', 64),
            $issuedAt,
            $this->requiredString($body, 'signature', 512),
        );

        return $this->ok([
            // Prefixed so the filter can tell a device credential from a
            // portal ses_key or a guest token without parsing it first.
            'token'     => 'device.' . $result['token'],
            'expiresAt' => $result['expiresAt'],
            'scopes'    => $result['scopes'],
            'device'    => Presenter::device($result['device']),
        ]);
    }

    /**
     * `GET /devices/me`
     *
     * The agent's own view of itself: its row, what its organisation currently
     * permits, the ICE configuration, and anything waiting for it. The agent's
     * Permissions panel renders from this rather than from something it decided
     * locally — showing a capability the server would refuse would be the agent
     * lying to the person in front of it.
     */
    public function me(): ResponseInterface
    {
        $principal = $this->context()->requireDevice();
        $presence  = Services::devicePresenceService();
        $devices   = Services::deviceService();

        $description = $presence->selfDescription($principal);
        $device      = $description['device'];
        $policy      = $description['policy'];

        return $this->ok([
            'device'       => Presenter::device($device, $devices->isOnline($device)),
            'capabilities' => $description['capabilities'],
            'policy'       => [
                'allowRemoteControl'    => $policy->allowRemoteControl,
                'allowUnattendedAccess' => $policy->allowUnattendedAccess,
                'allowClipboardSync'    => $policy->allowClipboardSync,
                'allowDeviceReboot'     => $policy->allowDeviceReboot,
                'allowFileTransfer'     => $policy->allowFileTransfer,
                'allowedShareModes'     => $policy->allowedShareModes(),
                'restrictions'          => $policy->restrictions,
            ],
            'realtime' => [
                'iceServers'     => $description['iceServers'],
                'relayAvailable' => $description['relayAvailable'],
                'presenceIntervalSeconds' => $description['presenceIntervalSeconds'],
            ],
            'agent' => [
                'minimumVersion'    => $description['minimumAgentVersion'],
                'updateFeedUrl'     => $description['updateFeedUrl'],
                'clipboardMaxBytes' => $description['clipboardMaxBytes'],
            ],
            'pendingSessions' => array_map(
                static fn (array $row) => Presenter::session($row),
                Services::deviceSessionService()->pendingFor($principal),
            ),
        ]);
    }

    /**
     * `POST /devices/me/presence`
     *
     * Advisory and never a permission: a device reporting itself online grants
     * nothing. What it buys is an honest device list — an administrator looking
     * at a machine nobody has seen for three days should be told that, rather
     * than shown a Connect button that will hang.
     */
    public function presence(): ResponseInterface
    {
        $principal = $this->context()->requireDevice();
        $principal->assertScope(DevicePrincipal::SCOPE_PRESENCE);

        $body   = $this->body();
        $device = Services::deviceService()->recordPresence(
            $principal,
            $this->enum($body, 'state', ['ONLINE', 'OFFLINE'], 'ONLINE'),
            $this->clientIp(),
            $this->optionalString($body, 'agentVersion', 32),
        );

        return $this->ok(['device' => Presenter::device($device, true)]);
    }

    /**
     * `POST /devices/me/presence-token`
     *
     * A short-lived token for this device's own signalling room, and nothing
     * else. The room is inside the signed payload, so an agent cannot ask to
     * listen to another machine's.
     */
    public function presenceToken(): ResponseInterface
    {
        return $this->ok(
            Services::devicePresenceService()->presenceToken($this->context()->requireDevice()),
        );
    }

    /**
     * `POST /devices/me/sessions/{uuid}/join`
     *
     * The agent joining a session it was invited to. The session is re-read
     * here rather than trusted from the invitation, so a fabricated invite over
     * the presence room finds no session and achieves nothing.
     */
    public function joinSession(string $uuid): ResponseInterface
    {
        $principal = $this->context()->requireDevice();
        $principal->assertScope(DevicePrincipal::SCOPE_SESSION);

        $session = Services::sessionService()->findByUuidOrFail($uuid);

        $participant = Services::deviceSessionService()->joinAsHost($session, $principal);

        $token = Services::signallingTokenService()->issue(
            (string) $session['uuid'],
            (string) $participant['uuid'],
            (string) $participant['participant_role'],
            (string) $participant['display_name'],
            is_string($participant['capabilities'])
                ? (json_decode($participant['capabilities'], true) ?: [])
                : (array) $participant['capabilities'],
        );

        $ice = Services::iceConfigService();

        return $this->ok([
            'session'     => Presenter::session($session),
            'participant' => Presenter::participant($participant),
            'signalling'  => [
                'token'     => $token['token'],
                'url'       => $token['url'],
                'room'      => $token['room'],
                'expiresAt' => gmdate('c', $token['expiresAt']),
            ],
            'iceServers'     => $ice->iceServers(),
            'relayAvailable' => $ice->hasRelay(),
        ]);
    }

    /**
     * `POST /devices/me/sessions/{uuid}/control`
     *
     * The machine reporting what the person at it decided: Allow, Not now, or
     * Stop control.
     *
     * The agent's own gate has already taken effect by the time this arrives —
     * it is local and needs no network, which is the property that makes Stop
     * control trustworthy. This is how the *server* and the other participant
     * find out, so the browser stops sending and the decision is recorded
     * against the person the machine belongs to.
     */
    public function control(string $uuid): ResponseInterface
    {
        $principal = $this->context()->requireDevice();
        $principal->assertScope(DevicePrincipal::SCOPE_SESSION);

        $session = Services::sessionService()->findByUuidOrFail($uuid);
        $body    = $this->body();

        $result = Services::deviceSessionService()->decideControl(
            $session,
            $principal,
            $this->requiredString($body, 'participantUuid', 64),
            $this->enum($body, 'decision', ['GRANT', 'DENY', 'REVOKE'], 'REVOKE'),
            $this->boolean($body, 'allowClipboard'),
        );

        return $this->ok([
            'participant' => Presenter::participant($result['participant']),
            'control'     => Services::controlService()->stateFor($session),
        ]);
    }

    /**
     * `POST /devices/me/unattended/disable`
     *
     * The person at the keyboard switching their own machine's unattended
     * access off, without signing in to a web console first. Deliberately needs
     * no permission: taking a capability away is not the act that needed
     * authorising.
     */
    public function disableUnattended(): ResponseInterface
    {
        $device = Services::deviceService()->disableUnattendedByDevice(
            $this->context()->requireDevice(),
        );

        return $this->ok(['device' => Presenter::device($device, true)]);
    }
}
