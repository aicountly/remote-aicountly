<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Domain\Policy\EffectivePolicy;
use App\Domain\Support\ApiException;
use App\Domain\Support\Presenter;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * Control companions, from the browser (docs/desktop/CONTROL_COMPANION.md).
 *
 * The person sharing their screen links one of their own desktop apps to the session, so a viewer they
 * grant control to can operate the computer while the picture still comes from the browser. Every decision
 * is in {@see \App\Domain\Device\CompanionService}; the policy handed to it is always the caller's policy for
 * the *session's own* company.
 */
class CompanionController extends BaseApiController
{
    /** `GET /sessions/{uuid}/companion/devices` — the caller's own devices that could serve this session. */
    public function devices(string $uuid): ResponseInterface
    {
        $session = Services::sessionService()->findForUser($uuid, $this->identity());
        $devices = Services::deviceService();

        $result = Services::companionService()->eligibleDevices($session, $this->identity(), $this->sessionPolicy($session));

        return $this->ok([
            'devices'      => array_map(
                static fn (array $device) => Presenter::device($device, $devices->isOnline($device)),
                $result['devices'],
            ),
            'restrictions' => $result['restrictions'],
        ]);
    }

    /** `POST /sessions/{uuid}/companion` `{deviceUuid}` — link one of them. */
    public function link(string $uuid): ResponseInterface
    {
        $session  = Services::sessionService()->findForUser($uuid, $this->identity());
        $identity = $this->identity();

        $result = Services::companionService()->link(
            $session,
            $identity,
            $this->sessionPolicy($session),
            $this->requiredString($this->body(), 'deviceUuid', 64),
        );

        $invite = Services::devicePresenceService()->inviteToken($identity, $result['device'], (string) $session['uuid']);

        return $this->created([
            'companion'    => Services::companionLinks()->present($result['link'], $session),
            'control'      => Services::controlService()->stateFor($session),
            // A short-lived token for the device's presence room, so the browser can tell the app to look now
            // (it re-reads GET /devices/me, which is what lists the request) instead of on its next poll.
            'deviceInvite' => array_merge($invite, ['companionUuid' => (string) $result['link']['uuid']]),
        ]);
    }

    /** `DELETE /sessions/{uuid}/companion` — end the link, and any control that ran through it. */
    public function unlink(string $uuid): ResponseInterface
    {
        $session = Services::sessionService()->findForUser($uuid, $this->identity());

        $link = Services::companionService()->unlink($session, $this->identity());

        return $this->ok([
            'companion' => Services::companionLinks()->present($link, $session),
            'control'   => Services::controlService()->stateFor($session),
        ]);
    }

    /** @param array<string, mixed> $session */
    private function sessionPolicy(array $session): EffectivePolicy
    {
        try {
            return $this->policyFor(
                (string) $session['scope_type'],
                $session['company_id'] !== null ? (int) $session['company_id'] : null,
            );
        } catch (ApiException) {
            // Lost standing in the session's company: the same answer as never having had it.
            throw ApiException::notFound('That Remote session could not be found.');
        }
    }
}
