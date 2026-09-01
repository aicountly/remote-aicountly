<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Domain\Policy\EffectivePolicy;
use App\Domain\Policy\PermissionCatalog;
use App\Domain\Session\FileTransferService;
use App\Domain\Support\ApiException;
use App\Domain\Support\Presenter;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * File transfer (§36).
 *
 * These endpoints carry **no file content** — only the offer, the decision and
 * the outcome. The bytes go peer-to-peer over the data channel, so there is
 * nothing here to upload to, and nothing stored to leak.
 *
 * What the endpoints do carry is the authority a peer must not have: whether
 * the organisation permits transfers, whether this user may send, whether the
 * recipient is really in this session, whether the file is within the size
 * ceiling, and — the one that matters — whether the recipient said yes.
 */
class FileTransferController extends BaseApiController
{
    /** `GET /sessions/{uuid}/transfers` */
    public function index(string $uuid): ResponseInterface
    {
        $session = $this->sessionForCaller($uuid);
        $this->participantFor($session);

        return $this->ok(array_map(
            static fn (array $row) => Presenter::fileTransfer($row),
            Services::fileTransferService()->forSession((int) $session['id']),
        ));
    }

    /**
     * `POST /sessions/{uuid}/transfers` — offer a file.
     *
     * Called *before* any chunk is put on the wire. The response's uuid is what
     * the sender puts in the data-channel offer, so the receiving browser can
     * tie incoming bytes to a transfer the server already knows about — and
     * refuse anything it does not recognise.
     */
    public function create(string $uuid): ResponseInterface
    {
        $session     = $this->sessionForCaller($uuid);
        $participant = $this->participantFor($session);
        $body        = $this->body();

        $transfer = Services::fileTransferService()->offer(
            $session,
            $participant,
            $this->optionalString($body, 'toParticipantUuid', 64),
            $this->requiredString($body, 'fileName', 255),
            $this->optionalInt($body, 'fileSize') ?? 0,
            $this->optionalString($body, 'mimeType', 160),
            $this->policyForSession($session),
        );

        return $this->created(Presenter::fileTransfer(
            Services::fileTransferService()->findByUuidOrFail((string) $transfer['uuid']),
        ));
    }

    /** `POST /sessions/{uuid}/transfers/{transferUuid}/accept` */
    public function accept(string $uuid, string $transferUuid): ResponseInterface
    {
        $session     = $this->sessionForCaller($uuid);
        $participant = $this->participantFor($session);

        $transfer = Services::fileTransferService()->accept(
            $session,
            $transferUuid,
            $participant,
            $this->policyForSession($session),
        );

        return $this->ok(Presenter::fileTransfer(
            Services::fileTransferService()->findByUuidOrFail((string) $transfer['uuid']),
        ));
    }

    /** `POST /sessions/{uuid}/transfers/{transferUuid}/decline` */
    public function decline(string $uuid, string $transferUuid): ResponseInterface
    {
        $session     = $this->sessionForCaller($uuid);
        $participant = $this->participantFor($session);

        $transfer = Services::fileTransferService()->decline($session, $transferUuid, $participant);

        return $this->ok(Presenter::fileTransfer(
            Services::fileTransferService()->findByUuidOrFail((string) $transfer['uuid']),
        ));
    }

    /**
     * `POST /sessions/{uuid}/transfers/{transferUuid}/progress`
     *
     * Throttled by the client — the ledger needs to show that a large transfer
     * is moving, not to mirror every chunk.
     */
    public function progress(string $uuid, string $transferUuid): ResponseInterface
    {
        $session     = $this->sessionForCaller($uuid);
        $participant = $this->participantFor($session);

        $transfer = Services::fileTransferService()->progress(
            $session,
            $transferUuid,
            $participant,
            $this->optionalInt($this->body(), 'bytesTransferred') ?? 0,
        );

        return $this->ok(Presenter::fileTransfer(
            Services::fileTransferService()->findByUuidOrFail((string) $transfer['uuid']),
        ));
    }

    /**
     * `POST /sessions/{uuid}/transfers/{transferUuid}/complete`
     *
     * Reported by the recipient, which is the only side that actually knows
     * every byte arrived.
     */
    public function complete(string $uuid, string $transferUuid): ResponseInterface
    {
        $session     = $this->sessionForCaller($uuid);
        $participant = $this->participantFor($session);

        $transfer = Services::fileTransferService()->complete($session, $transferUuid, $participant);

        return $this->ok(Presenter::fileTransfer(
            Services::fileTransferService()->findByUuidOrFail((string) $transfer['uuid']),
        ));
    }

    /** `POST /sessions/{uuid}/transfers/{transferUuid}/abort` — cancel or fail. */
    public function abort(string $uuid, string $transferUuid): ResponseInterface
    {
        $session     = $this->sessionForCaller($uuid);
        $participant = $this->participantFor($session);
        $body        = $this->body();

        $transfer = Services::fileTransferService()->abort(
            $session,
            $transferUuid,
            $participant,
            $this->enum($body, 'status', ['CANCELLED', 'FAILED'], 'CANCELLED'),
            $this->optionalString($body, 'errorCode', 40),
        );

        return $this->ok(Presenter::fileTransfer(
            Services::fileTransferService()->findByUuidOrFail((string) $transfer['uuid']),
        ));
    }

    // ------------------------------------------------------------ internals

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
     * Transfers are between people *in* the session. Someone who can see it
     * through company-wide history is a reader of the record, not a party to
     * it, and must not be able to send into a live session.
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

    /**
     * The policy governing this caller in this session.
     *
     * An AICOUNTLY user gets their real effective policy, resolved for the
     * session's own scope — not for whichever organisation they happen to have
     * selected in the header.
     *
     * @param array<string, mixed> $session
     */
    private function policyForSession(array $session): EffectivePolicy
    {
        $identity = $this->context()->identityOrNull();

        if ($identity !== null) {
            return Services::policyResolver()->resolve(
                $identity,
                (string) $session['scope_type'],
                $session['company_id'] !== null ? (int) $session['company_id'] : null,
            );
        }

        return $this->guestPolicy($session);
    }

    /**
     * A stand-in policy for a guest, who has no AICOUNTLY policy of their own.
     *
     * They may send and receive exactly if the session was created with file
     * transfer on, and nothing else — the minimum capability §23 asks a guest
     * to be held to. It cannot exceed the session either, because
     * {@see FileTransferService::assertTransferable()} checks the snapshot too.
     *
     * @param array<string, mixed> $session
     */
    private function guestPolicy(array $session): EffectivePolicy
    {
        $fileTransfer = Presenter::bool($session['allow_file_transfer'] ?? false);

        return new EffectivePolicy(
            remoteEnabled: true,
            scopeType: (string) $session['scope_type'],
            companyId: $session['company_id'] !== null ? (int) $session['company_id'] : null,
            companyName: null,
            policyPreset: 'CUSTOM',
            allowSafeShare: false,
            allowBrowserTab: false,
            allowApplicationWindow: false,
            allowEntireMonitor: false,
            allowMicrophone: Presenter::bool($session['allow_audio'] ?? false),
            allowSystemAudio: false,
            allowTextChat: Presenter::bool($session['allow_chat'] ?? false),
            allowAnnotation: Presenter::bool($session['allow_annotation'] ?? false),
            allowFileTransfer: $fileTransfer,
            allowExternalGuest: false,
            allowInternalSessions: false,
            allowAicountlySupport: false,
            allowRecording: false,
            recordingRequiresConsent: true,
            maxSessionDurationMinutes: (int) $session['max_duration_minutes'],
            guestLinkExpiryMinutes: 10,
            permissions: [
                PermissionCatalog::FILE_SEND    => $fileTransfer,
                PermissionCatalog::FILE_RECEIVE => $fileTransfer,
            ],
        );
    }
}
