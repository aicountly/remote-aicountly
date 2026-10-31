<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Domain\Device\DeviceHistory;
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
 * What an agent can do here: say it is still there, find out what it is
 * currently permitted to do, pick up an unattended session it has been asked
 * to host, report what the person at the machine decided about remote control,
 * switch its own unattended access off, and revoke itself when somebody signs
 * the computer out — and, for the person sitting at it, list their own
 * computers, read this computer's history, and host a session they started
 * here ("give your code"): start it, see who is waiting, admit or decline
 * them, and end it. Every one of those answers about this device or its
 * owner, from the device row its credential names; none of them takes a
 * person, a company or another device as input.
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
            // Control-companion links waiting for somebody at this machine to confirm them
            // (docs/desktop/CONTROL_COMPANION.md). Nothing here joins anything on its own.
            'pendingCompanions' => Services::companionService()->pendingFor($principal),
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

        return $this->ok($this->joinPayload($session, $participant));
    }

    /**
     * The session, the participant, a signalling token for it and its TURN grant — what an agent needs to
     * enter the room, whether as the shared screen or as a control companion.
     *
     * @param  array<string, mixed> $session
     * @param  array<string, mixed> $participant
     * @return array<string, mixed>
     */
    private function joinPayload(array $session, array $participant): array
    {
        $token = Services::signallingTokenService()->issue(
            (string) $session['uuid'],
            (string) $participant['uuid'],
            (string) $participant['participant_role'],
            (string) $participant['display_name'],
            is_string($participant['capabilities'])
                ? (json_decode($participant['capabilities'], true) ?: [])
                : (array) $participant['capabilities'],
        );

        // The agent cannot renew its TURN credential mid-session, so it is issued one for the rest of
        // the session rather than the browser's ordinary hour (docs/ICE_CONTRACT.md).
        $remaining = max(0, strtotime((string) ($session['expires_at'] ?? '')) - time());
        $ice       = Services::iceConfigService();
        $grant     = $ice->grantForSession(
            (string) $participant['uuid'],
            $remaining,
            $ice->contextFor($session, [], fn (string $name): string => $this->request->getHeaderLine($name)),
        );

        return [
            'session'     => Presenter::session($session),
            'participant' => Presenter::participant($participant),
            'signalling'  => [
                'token'     => $token['token'],
                'url'       => $token['url'],
                'room'      => $token['room'],
                'expiresAt' => gmdate('c', $token['expiresAt']),
            ],
            'iceServers'     => $grant->iceServers(),
            'relayAvailable' => $grant->relayAvailable(),
            'ice'            => $grant->toArray(),
        ];
    }

    // ------------------------------------------------------ control companions

    /**
     * `POST /devices/me/companions/{uuid}/join`
     *
     * Called only after somebody at this machine confirmed the link there. Idempotent: calling it again
     * returns a fresh signalling token for the same participant, which is how the companion renews.
     */
    public function companionJoin(string $uuid): ResponseInterface
    {
        $principal = $this->context()->requireDevice();
        $principal->assertScope(DevicePrincipal::SCOPE_SESSION);

        $result = Services::companionService()->join($principal, $uuid);

        return $this->ok(array_merge(
            $this->joinPayload($result['session'], $result['participant']),
            ['companion' => Services::companionLinks()->present($result['link'], $result['session'])],
        ));
    }

    /** `POST /devices/me/companions/{uuid}/decline` — somebody at the machine said no. */
    public function companionDecline(string $uuid): ResponseInterface
    {
        $principal = $this->context()->requireDevice();
        $principal->assertScope(DevicePrincipal::SCOPE_SESSION);

        $result = Services::companionService()->decline($principal, $uuid);

        return $this->ok(['companion' => Services::companionLinks()->present($result['link'], $result['session'])]);
    }

    /**
     * `POST /devices/me/companions/{uuid}/monitor` `{monitor: {id, name, x, y, width, height, scale}, matchedBy}`
     *
     * Which of this machine's monitors the shared picture is, in its own injection space. Makes the link
     * ACTIVE; control still needs the person at the browser to grant a request.
     */
    public function companionMonitor(string $uuid): ResponseInterface
    {
        $principal = $this->context()->requireDevice();
        $principal->assertScope(DevicePrincipal::SCOPE_SESSION);

        $body    = $this->body();
        $monitor = $body['monitor'] ?? null;

        if (! is_array($monitor)) {
            throw ApiException::badRequest('VALIDATION_FAILED', 'Some of the details sent were not valid.', [
                'fields' => ['monitor' => 'This is required.'],
            ]);
        }

        $result = Services::companionService()->selectMonitor(
            $principal,
            $uuid,
            $monitor,
            $this->enum($body, 'matchedBy', ['AUTO_SINGLE', 'AUTO_ASPECT', 'USER'], 'USER'),
        );

        return $this->ok([
            'companion' => Services::companionLinks()->present($result['link'], $result['session']),
            'control'   => Services::controlService()->stateFor($result['session']),
        ]);
    }

    /** `POST /devices/me/companions/{uuid}/leave` — Stop at the machine, or the app going away. */
    public function companionLeave(string $uuid): ResponseInterface
    {
        $principal = $this->context()->requireDevice();
        $principal->assertScope(DevicePrincipal::SCOPE_SESSION);

        $result = Services::companionService()->leave($principal, $uuid);

        return $this->ok(['companion' => Services::companionLinks()->present($result['link'], $result['session'])]);
    }

    /**
     * `GET /devices/me/sessions/{uuid}/control`
     *
     * What the agent polls while a session is running: who is waiting for
     * control, who has it, and what the organisation currently permits.
     *
     * Polled from the API rather than taken from the peer. A control request
     * that reached the API is the only kind that can be granted, so it is the
     * only kind that should be able to put a consent dialog in front of
     * somebody — a peer cannot conjure one by sending a message.
     */
    public function controlState(string $uuid): ResponseInterface
    {
        $principal = $this->context()->requireDevice();
        $principal->assertScope(DevicePrincipal::SCOPE_SESSION);

        $session = Services::sessionService()->findByUuidOrFail($uuid);

        return $this->ok(
            Services::deviceSessionService()->controlStateFor($session, $principal),
        );
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

    // ------------------------------------------------- the owner's computers

    /**
     * `GET /devices/me/computers` — `{computers: Computer[], count}`
     *
     * The owner's computers in this computer's scope, this one first. Asked
     * with the machine's credential, so the only person it can be about is the
     * one this machine belongs to.
     */
    public function computers(): ResponseInterface
    {
        $principal = $this->context()->requireDevice();
        $principal->assertScope(DevicePrincipal::SCOPE_MANAGE);

        $devices = Services::deviceService();
        $result  = $devices->computersFor($principal);
        $thisId  = (int) $result['device']['id'];

        $computers = array_map(
            static fn (array $row): array => Presenter::computer($row, $devices->isOnline($row), (int) $row['id'] === $thisId),
            $result['items'],
        );

        return $this->ok(['computers' => $computers, 'count' => count($computers)]);
    }

    /**
     * `GET /devices/me/history[?limit=]` — `{sessions, events}` for this
     * computer: the same history its owner reads in the console.
     */
    public function history(): ResponseInterface
    {
        $principal = $this->context()->requireDevice();
        $principal->assertScope(DevicePrincipal::SCOPE_MANAGE);

        $device = Services::deviceService()->findByUuidOrFail($principal->deviceUuid);

        return $this->ok(Presenter::deviceHistory(
            Services::deviceHistory()->for($device, DeviceHistory::limit($this->request->getGet('limit'))),
        ));
    }

    // ----------------------------------------- sessions this computer hosts

    /**
     * `POST /devices/me/sessions` `{issueSummary?}` — `201 {session}`
     *
     * Somebody at this computer starting a session for a helper to join by its
     * code. The agent then joins it as its host through
     * `devices/me/sessions/{uuid}/join`, exactly as it joins an unattended one.
     */
    public function startSession(): ResponseInterface
    {
        $principal = $this->context()->requireDevice();
        $principal->assertScope(DevicePrincipal::SCOPE_MANAGE);

        $session = Services::deviceSessionService()->startAttended(
            $principal,
            $this->optionalString($this->body(), 'issueSummary', 500),
        );

        return $this->created(['session' => Presenter::session($session)]);
    }

    /** `GET /devices/me/sessions/{uuid}/participants` — `{waiting, joined}` */
    public function sessionParticipants(string $uuid): ResponseInterface
    {
        $principal = $this->context()->requireDevice();
        $principal->assertScope(DevicePrincipal::SCOPE_MANAGE);

        $result = Services::deviceSessionService()->participantsOfHosted($principal, $uuid);

        return $this->ok([
            'waiting' => array_map([Presenter::class, 'hostedParticipant'], $result['waiting']),
            'joined'  => array_map([Presenter::class, 'hostedParticipant'], $result['joined']),
        ]);
    }

    /** `POST /devices/me/sessions/{uuid}/participants/{participantUuid}/admit` — `{participant}` */
    public function admitParticipant(string $uuid, string $participantUuid): ResponseInterface
    {
        $principal = $this->context()->requireDevice();
        $principal->assertScope(DevicePrincipal::SCOPE_MANAGE);

        $participant = Services::deviceSessionService()->admit($principal, $uuid, $participantUuid);

        return $this->ok(['participant' => Presenter::hostedParticipant($participant)]);
    }

    /** `POST /devices/me/sessions/{uuid}/participants/{participantUuid}/decline` — `{participant}` */
    public function declineParticipant(string $uuid, string $participantUuid): ResponseInterface
    {
        $principal = $this->context()->requireDevice();
        $principal->assertScope(DevicePrincipal::SCOPE_MANAGE);

        $participant = Services::deviceSessionService()->decline($principal, $uuid, $participantUuid);

        return $this->ok(['participant' => Presenter::hostedParticipant($participant)]);
    }

    /** `POST /devices/me/sessions/{uuid}/end` — `{session}` */
    public function endSession(string $uuid): ResponseInterface
    {
        $principal = $this->context()->requireDevice();
        $principal->assertScope(DevicePrincipal::SCOPE_MANAGE);

        $session = Services::deviceSessionService()->endHosted($principal, $uuid);

        return $this->ok(['session' => Presenter::session($session)]);
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

    /**
     * `POST /devices/me/revoke`
     *
     * "Sign this computer out", from the desktop app: the machine revokes its
     * own row before it deletes its key, so the computer leaves its owner's
     * Computers page rather than staying there ACTIVE for somebody to remove.
     * The same revocation an owner's revoke from the console makes, and it
     * names no device — the credential does — so there is nothing in the
     * request that could point it at another one.
     *
     * The last call this credential is good for: the filter re-reads the row
     * on every request, and from here on it is not ACTIVE.
     */
    public function revoke(): ResponseInterface
    {
        $principal = $this->context()->requireDevice();
        $principal->assertScope(DevicePrincipal::SCOPE_MANAGE);

        $device = Services::deviceService()->revokeByDevice($principal);

        return $this->ok(['device' => Presenter::device($device)]);
    }
}
