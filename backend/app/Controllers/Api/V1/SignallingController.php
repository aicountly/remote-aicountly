<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Domain\Audit\EventType;
use App\Domain\Session\SessionStatus;
use App\Domain\Support\ApiException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * `POST /sessions/{uuid}/signalling-token` (§19, §20).
 *
 * This is where authorisation for the realtime layer happens, and the only
 * place it happens. The signalling service holds no database and evaluates no
 * policy — it verifies this token's signature and puts the connection in the
 * room the token names.
 *
 * Which means the checks below are load-bearing:
 *
 *   * the session must still be live;
 *   * the caller must be a participant of *this* session;
 *   * that participant must be APPROVED or JOINED.
 *
 * A viewer the host has not admitted fails the last one, so they never get a
 * token, never enter the room, and never receive an SDP offer. Host approval is
 * enforced here rather than in the browser.
 */
class SignallingController extends BaseApiController
{
    public function token(string $uuid): ResponseInterface
    {
        $guest        = $this->context()->guest();
        $participants = Services::participantService();

        if ($guest !== null) {
            $guest->assertSession($uuid);
            $session     = Services::sessionService()->findByUuidOrFail($uuid);
            $participant = $participants->findByUuidOrFail($guest->participantUuid);

            if ((int) $participant['session_id'] !== (int) $session['id']) {
                throw ApiException::notFound('That Remote session could not be found.');
            }
        } else {
            $identity    = $this->identity();
            $session     = Services::sessionService()->findForUser($uuid, $identity);
            $participant = $participants->findByUser((int) $session['id'], $identity->id);

            if ($participant === null) {
                throw ApiException::forbidden(
                    'NOT_A_PARTICIPANT',
                    'You are not part of this Remote session.',
                );
            }
        }

        if (! SessionStatus::isLive((string) $session['status'])) {
            throw ApiException::conflict('SESSION_ALREADY_ENDED', 'This Remote session has already finished.');
        }

        if (! in_array((string) $participant['status'], ['APPROVED', 'JOINED'], true)) {
            throw ApiException::forbidden(
                'AWAITING_APPROVAL',
                'The host has not admitted you to this session yet.',
                ['participantStatus' => $participant['status']],
            );
        }

        $capabilities = $participant['capabilities'] ?? '{}';
        if (is_string($capabilities)) {
            $capabilities = json_decode($capabilities, true) ?: [];
        }

        $token = Services::signallingTokenService()->issue(
            (string) $session['uuid'],
            (string) $participant['uuid'],
            (string) $participant['participant_role'],
            (string) $participant['display_name'],
            $capabilities,
        );

        // Recorded without the token itself — the audit trail says a credential
        // was issued, never what it was (§60).
        Services::auditService()->recordEvent(
            (int) $session['id'],
            EventType::SIGNALLING_TOKEN_ISSUED,
            $participant['user_id'] !== null ? (int) $participant['user_id'] : null,
            $participant['user_id'] === null ? 'GUEST' : 'USER',
            (int) $participant['id'],
        );

        $ice = Services::iceConfigService();

        return $this->ok([
            'token'     => $token['token'],
            'url'       => $token['url'],
            'room'      => $token['room'],
            'expiresAt' => gmdate('c', $token['expiresAt']),
            'participantUuid' => (string) $participant['uuid'],
            'role'      => (string) $participant['participant_role'],
            'iceServers' => $ice->iceServers(),
            // The UI uses this to explain an unreachable peer honestly instead
            // of showing "reconnecting" forever (§49).
            'relayAvailable' => $ice->hasRelay(),
        ]);
    }
}
