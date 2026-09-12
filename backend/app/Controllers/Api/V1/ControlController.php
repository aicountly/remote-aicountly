<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Domain\Support\ApiException;
use App\Domain\Support\Presenter;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * Attended remote control: request, grant, deny, revoke, clipboard.
 *
 * Every method resolves the policy **for the session's own scope**, not for
 * whatever organisation the caller happens to be looking at, and hands it to
 * {@see \App\Domain\Session\ControlService} — which is where the decisions are.
 * Holding `remote.control.request` at one company grants nothing in another
 * company's session.
 */
class ControlController extends BaseApiController
{
    /** `GET /sessions/{uuid}/control` — the current state, for rendering. */
    public function show(string $uuid): ResponseInterface
    {
        $session = Services::sessionService()->findForUser($uuid, $this->identity());
        $policy  = $this->sessionPolicy($session);

        return $this->ok(array_merge(
            Services::controlService()->stateFor($session),
            [
                'allowRemoteControl' => $policy->allowRemoteControl,
                'allowClipboardSync' => $policy->allowClipboardSync,
                'allowDeviceReboot'  => $policy->allowDeviceReboot,
                'restrictions'       => $policy->restrictions,
            ],
        ));
    }

    /** `POST /sessions/{uuid}/control/request` */
    public function request(string $uuid): ResponseInterface
    {
        $session = Services::sessionService()->findForUser($uuid, $this->identity());

        $participant = Services::controlService()->request(
            $session,
            $this->identity(),
            $this->sessionPolicy($session),
        );

        return $this->ok([
            'participant' => Presenter::participant($participant),
            'control'     => Services::controlService()->stateFor($session),
        ]);
    }

    /** `POST /sessions/{uuid}/control/grant` — host only. */
    public function grant(string $uuid): ResponseInterface
    {
        $session = Services::sessionService()->findForUser($uuid, $this->identity());
        $body    = $this->body();

        $participant = Services::controlService()->grant(
            $session,
            $this->identity(),
            $this->requiredString($body, 'participantUuid', 64),
            $this->sessionPolicy($session),
            $this->boolean($body, 'allowClipboard'),
        );

        return $this->ok([
            'participant' => Presenter::participant($participant),
            'control'     => Services::controlService()->stateFor($session),
        ]);
    }

    /** `POST /sessions/{uuid}/control/deny` — host only. */
    public function deny(string $uuid): ResponseInterface
    {
        $session = Services::sessionService()->findForUser($uuid, $this->identity());

        $participant = Services::controlService()->deny(
            $session,
            $this->identity(),
            $this->requiredString($this->body(), 'participantUuid', 64),
        );

        return $this->ok([
            'participant' => Presenter::participant($participant),
            'control'     => Services::controlService()->stateFor($session),
        ]);
    }

    /**
     * `POST /sessions/{uuid}/control/revoke`
     *
     * Either side, immediately, no permission required — see
     * `ControlService::revoke()` for why requiring one would be a mistake.
     */
    public function revoke(string $uuid): ResponseInterface
    {
        $session = Services::sessionService()->findForUser($uuid, $this->identity());

        $participant = Services::controlService()->revoke(
            $session,
            $this->identity(),
            $this->optionalString($this->body(), 'participantUuid', 64),
        );

        return $this->ok([
            'participant' => Presenter::participant($participant),
            'control'     => Services::controlService()->stateFor($session),
        ]);
    }

    /** `POST /sessions/{uuid}/control/clipboard` — host only. */
    public function clipboard(string $uuid): ResponseInterface
    {
        $session = Services::sessionService()->findForUser($uuid, $this->identity());
        $body    = $this->body();

        $participant = Services::controlService()->setClipboard(
            $session,
            $this->identity(),
            $this->requiredString($body, 'participantUuid', 64),
            $this->boolean($body, 'enabled'),
            $this->sessionPolicy($session),
        );

        return $this->ok([
            'participant' => Presenter::participant($participant),
            'control'     => Services::controlService()->stateFor($session),
        ]);
    }

    /**
     * The caller's policy for the session's own scope.
     *
     * @param array<string, mixed> $session
     */
    private function sessionPolicy(array $session): \App\Domain\Policy\EffectivePolicy
    {
        $scopeType = (string) $session['scope_type'];
        $companyId = $session['company_id'] !== null ? (int) $session['company_id'] : null;

        try {
            return $this->policyFor($scopeType, $companyId);
        } catch (ApiException $exception) {
            // A caller who has lost their standing in the session's company
            // gets the same answer as one who never had it (§26).
            throw ApiException::notFound('That Remote session could not be found.');
        }
    }
}
