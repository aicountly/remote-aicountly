<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Domain\Audit\EventType;
use App\Domain\Support\ApiException;
use App\Domain\Support\Presenter;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * Admitting people to a session (§71).
 *
 * Approval is the gate that everything else hangs off: a participant who is not
 * APPROVED cannot obtain a signalling token, so they never enter the room and
 * never receive an offer. Nothing is transmitted before the host says yes.
 */
class ParticipantController extends BaseApiController
{
    /**
     * `POST /sessions/{uuid}/join-request` — an AICOUNTLY user asks to join a
     * session they already have the link for.
     *
     * `findByUuidOrFail`, not `findForUser`: someone who is not yet a
     * participant has no access to the session, and asking to join is precisely
     * how they acquire it. The isolation check is inside `joinAuthenticated`,
     * which refuses anyone who is not a member of the session's company.
     */
    public function requestJoin(string $uuid): ResponseInterface
    {
        $session = Services::sessionService()->findByUuidOrFail($uuid);

        $result = Services::joinService()->joinAuthenticated(
            $session,
            $this->identity(),
            $this->userAgent(),
            $this->clientIp(),
        );

        return $this->created([
            'session'     => Presenter::session($result['session']),
            'participant' => Presenter::participant($result['participant']),
        ]);
    }

    /** `POST /sessions/{uuid}/participants/{participantUuid}/approve` */
    public function approve(string $uuid, string $participantUuid): ResponseInterface
    {
        $session = Services::sessionService()->findForUser($uuid, $this->identity());

        $participant = Services::participantService()->approve($session, $participantUuid, $this->identity());

        return $this->ok(Presenter::participant($participant));
    }

    /** `POST /sessions/{uuid}/participants/{participantUuid}/deny` */
    public function deny(string $uuid, string $participantUuid): ResponseInterface
    {
        $session = Services::sessionService()->findForUser($uuid, $this->identity());

        $participant = Services::participantService()->deny($session, $participantUuid, $this->identity());

        return $this->ok(Presenter::participant($participant));
    }

    /**
     * `POST /sessions/{uuid}/participants/{participantUuid}/joined`
     *
     * The peer connection is up. Called by whoever the participant is —
     * including a guest, which is why the ownership check below is explicit.
     */
    public function markJoined(string $uuid, string $participantUuid): ResponseInterface
    {
        $session = $this->sessionForCaller($uuid);
        $this->assertIsSelf($session, $participantUuid);

        $participant = Services::participantService()->markJoined($session, $participantUuid);

        // First join moves a waiting session into ACTIVE.
        Services::sessionService()->markActive($session);

        return $this->ok(Presenter::participant($participant));
    }

    /** `POST /sessions/{uuid}/participants/{participantUuid}/leave` */
    public function leave(string $uuid, string $participantUuid): ResponseInterface
    {
        $session = $this->sessionForCaller($uuid);
        $this->assertIsSelf($session, $participantUuid);

        Services::participantService()->leave($session, $participantUuid);

        return $this->noContent();
    }

    /**
     * `POST /sessions/{uuid}/participants/{participantUuid}/presence`
     *
     * A heartbeat carrying the connection state the browser is reporting, so
     * the other side's participant list is not guesswork. Also the hook the
     * connection-quality indicator is fed from (§49).
     */
    public function presence(string $uuid, string $participantUuid): ResponseInterface
    {
        $session = $this->sessionForCaller($uuid);
        $this->assertIsSelf($session, $participantUuid);

        $participants = Services::participantService();
        $participant  = $participants->findByUuidOrFail($participantUuid);

        $state = $this->enum(
            $this->body(),
            'connectionState',
            ['IDLE', 'CONNECTING', 'CONNECTED', 'INTERRUPTED', 'CLOSED'],
            'CONNECTED',
        );

        $previous = (string) $participant['connection_state'];
        $participants->touch((int) $participant['id'], $state);

        // Only the transitions are worth recording; a heartbeat every few
        // seconds is not an event (§65).
        if ($previous !== $state && in_array($state, ['INTERRUPTED', 'CONNECTED'], true)) {
            Services::auditService()->recordEvent(
                (int) $session['id'],
                $state === 'INTERRUPTED' ? EventType::CONNECTION_INTERRUPTED : EventType::CONNECTION_RESTORED,
                $participant['user_id'] !== null ? (int) $participant['user_id'] : null,
                $participant['user_id'] === null ? 'GUEST' : 'USER',
                (int) $participant['id'],
            );
        }

        $body = $this->body();
        if (array_key_exists('microphoneEnabled', $body)) {
            $enabled = $this->boolean($body, 'microphoneEnabled');

            if ($enabled && ! Presenter::bool($session['allow_audio'])) {
                throw ApiException::forbidden('MICROPHONE_NOT_ALLOWED', 'Microphone is not enabled for this Remote session.');
            }

            if ($enabled !== Presenter::bool($participant['microphone_enabled'])) {
                $participants->setMicrophone((int) $session['id'], (int) $participant['id'], $enabled);
                Services::auditService()->recordEvent(
                    (int) $session['id'],
                    $enabled ? EventType::MICROPHONE_STARTED : EventType::MICROPHONE_STOPPED,
                    $participant['user_id'] !== null ? (int) $participant['user_id'] : null,
                    $participant['user_id'] === null ? 'GUEST' : 'USER',
                    (int) $participant['id'],
                );
            }
        }

        return $this->ok(Presenter::participant($participants->findByIdOrFail((int) $participant['id'])));
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionForCaller(string $uuid): array
    {
        $guest = $this->context()->guest();

        if ($guest !== null) {
            $guest->assertSession($uuid);

            return Services::sessionService()->findByUuidOrFail($uuid);
        }

        return Services::sessionService()->findForUser($uuid, $this->identity());
    }

    /**
     * You may only act on your own participant row.
     *
     * Without this, any member of a session could mark another participant as
     * joined, or remove them — moderation is the host's, and only through the
     * approve/deny endpoints.
     *
     * @param array<string, mixed> $session
     */
    private function assertIsSelf(array $session, string $participantUuid): void
    {
        $guest = $this->context()->guest();

        if ($guest !== null) {
            if (! hash_equals($guest->participantUuid, $participantUuid)) {
                throw ApiException::notFound('That participant could not be found.');
            }

            return;
        }

        $me = Services::participantService()->findByUser((int) $session['id'], $this->identity()->id);

        if ($me === null || ! hash_equals((string) $me['uuid'], $participantUuid)) {
            throw ApiException::notFound('That participant could not be found.');
        }
    }
}
