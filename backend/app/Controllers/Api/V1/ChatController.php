<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Domain\Support\ApiException;
use App\Domain\Support\Presenter;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * Session chat, over HTTP.
 *
 * The live path is the RTCDataChannel — this is the durable record and the
 * fallback for a peer whose channel is not open yet (§35). A client posts here
 * *and* sends over the channel; the receiving side de-duplicates on the message
 * uuid, so a reconnecting participant can fetch what it missed without seeing
 * anything twice.
 */
class ChatController extends BaseApiController
{
    /** `GET /sessions/{uuid}/messages` */
    public function index(string $uuid): ResponseInterface
    {
        $session = $this->sessionForCaller($uuid);

        $after = $this->request->getGet('after');

        $messages = Services::chatService()->history(
            (int) $session['id'],
            is_string($after) ? $after : null,
        );

        return $this->ok(array_map(static fn (array $row) => Presenter::message($row), $messages));
    }

    /** `POST /sessions/{uuid}/messages` */
    public function create(string $uuid): ResponseInterface
    {
        $session     = $this->sessionForCaller($uuid);
        $participant = $this->participantFor($session);

        $message = Services::chatService()->post(
            $session,
            $participant,
            $this->requiredString($this->body(), 'body', 4000),
            $this->enum($this->body(), 'deliveredVia', ['DATA_CHANNEL', 'RELAY'], 'RELAY'),
        );

        return $this->created(Presenter::message($message));
    }

    /** @return array<string, mixed> */
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
     * Chat is for people *in* the session. Someone who can see it through
     * company-wide history is a reader of the record, not a participant, and
     * must not be able to speak into a live session.
     *
     * @param  array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function participantFor(array $session): array
    {
        $guest = $this->context()->guest();

        $participant = $guest !== null
            ? Services::participantService()->findByUuid($guest->participantUuid)
            : Services::participantService()->findByUser((int) $session['id'], $this->identity()->id);

        if ($participant === null || (int) $participant['session_id'] !== (int) $session['id']) {
            throw ApiException::forbidden('NOT_A_PARTICIPANT', 'You are not part of this Remote session.');
        }

        if (! in_array((string) $participant['status'], ['APPROVED', 'JOINED'], true)) {
            throw ApiException::forbidden('AWAITING_APPROVAL', 'The host has not admitted you to this session yet.');
        }

        return $participant;
    }
}
