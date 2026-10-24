<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use Aicountly\Ice\ConnectivityReport;
use Aicountly\Ice\ReportLog;
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
        [$session, $participant] = $this->admittedParticipant($uuid);

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

        $grant = $this->grantFor($session, $participant);

        return $this->ok([
            'token'     => $token['token'],
            'url'       => $token['url'],
            'room'      => $token['room'],
            'expiresAt' => gmdate('c', $token['expiresAt']),
            'participantUuid' => (string) $participant['uuid'],
            'role'      => (string) $participant['participant_role'],
            'iceServers' => $grant->iceServers(),
            // The UI uses this to explain an unreachable peer honestly instead
            // of showing "reconnecting" forever (§49).
            'relayAvailable' => $grant->relayAvailable(),
            // The whole aicountly.ice/1 grant: expiry, when to refresh, and which
            // relay node each URL belongs to (docs/ICE_CONTRACT.md).
            'ice' => $grant->toArray(),
        ]);
    }

    /**
     * `POST /sessions/{uuid}/ice-servers` — fresh TURN credentials, without a signalling token.
     *
     * A relay refuses a new allocation on an expired credential, so whatever a long session must set up
     * again (an ICE restart, a reconnect after a network change) needs a valid one. The browser asks again
     * at `refreshAfter`, and asks here rather than re-minting a signalling token so that renewing a relay
     * is not also a reconnection to the room. Same admission rule as the token.
     */
    public function iceServers(string $uuid): ResponseInterface
    {
        [$session, $participant] = $this->admittedParticipant($uuid);

        return $this->ok($this->grantFor($session, $participant)->toArray());
    }

    /**
     * `POST /sessions/{uuid}/connectivity-report` — how this participant's connection went.
     *
     * Diagnostics, not audit: written as one structured line to its own daily file so an operator can
     * answer "is the Mumbai relay carrying calls, and where does ICE fail" across sessions. The body is
     * reduced to an allow-list of enumerations and counts before anything is written — no SDP, no
     * candidate, no IP address and no credential can pass through, whatever a client sends.
     */
    public function connectivityReport(string $uuid): ResponseInterface
    {
        [$session, $participant] = $this->admittedParticipant($uuid, false);

        $report = ConnectivityReport::sanitize($this->body());

        // How the connection really went tells the capacity books where the traffic is: nothing on the
        // relays for a direct connection, and the bandwidth on the node it says it relays through.
        if (in_array($report['path'], ['direct', 'relay'], true)) {
            Services::iceConfigService()->noteConnection((string) $participant['uuid'], (string) $session['uuid'], $report['path'], $report['relayNode']);
        }

        // Its own daily file (writable/logs/ice-reports-YYYY-MM-DD.log), not the framework log: production logs at
        // `error`, which dropped every report, and these are the evidence summarize-reports.php reads.
        ReportLog::append(WRITEPATH . 'logs', 'remote', [
            'session'     => (string) $session['uuid'],
            'participant' => (string) $participant['uuid'],
        ] + $report);

        return $this->ok(['recorded' => true]);
    }

    /**
     * The session and the caller's participant row, refused unless the session is live and the
     * participant has been admitted. This is the load-bearing check described above.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function admittedParticipant(string $uuid, bool $requireLive = true): array
    {
        $guest        = $this->context()->guest();
        $participants = Services::participantService();

        if ($guest !== null) {
            // `allowPreview`: a person awaiting admission is answered below with
            // AWAITING_APPROVAL and their participant status, which is how the
            // waiting room learns it is still waiting.
            $session     = Services::sessionService()->findForGuest($guest, $uuid, true);
            $participant = $participants->findByUuidOrFail($guest->participantUuid);
        } else {
            $identity    = $this->identity();
            $session     = Services::sessionService()->findForUser($uuid, $identity, true);
            $participant = $participants->findByUser((int) $session['id'], $identity->id);

            if ($participant === null) {
                throw ApiException::forbidden(
                    'NOT_A_PARTICIPANT',
                    'You are not part of this Remote session.',
                );
            }
        }

        // A report about how a connection ended may arrive after the session has.
        if ($requireLive && ! SessionStatus::isLive((string) $session['status'])) {
            throw ApiException::conflict('SESSION_ALREADY_ENDED', 'This Remote session has already finished.');
        }

        if (! in_array((string) $participant['status'], ['APPROVED', 'JOINED', ...($requireLive ? [] : ['LEFT'])], true)) {
            throw ApiException::forbidden(
                'AWAITING_APPROVAL',
                'The host has not admitted you to this session yet.',
                ['participantStatus' => $participant['status']],
            );
        }

        return [$session, $participant];
    }

    /**
     * The grant for one admitted participant: accounted to the session's licence tier, ordered by where the
     * client is (a region hint, edge geography, the round trips it measured), and capacity-checked.
     *
     * @param array<string, mixed> $session
     * @param array<string, mixed> $participant
     */
    private function grantFor(array $session, array $participant): \Aicountly\Ice\IceGrant
    {
        $ice     = Services::iceConfigService();
        $context = $ice->contextFor($session, $this->body(), fn (string $name): string => $this->request->getHeaderLine($name));
        $ice->learnLatency($context['client'], (string) $participant['uuid']);

        return $ice->grant((string) $participant['uuid'], $this->regionHint(), null, $context);
    }

    /** The client's region hint for relay ordering (`in-west`, `in-north`); a hint, never a filter. */
    private function regionHint(): ?string
    {
        $body   = $this->body();
        $region = strtolower(trim((string) ($body['region'] ?? '')));

        return preg_match('/^[a-z0-9-]{2,32}$/', $region) === 1 ? $region : null;
    }
}
