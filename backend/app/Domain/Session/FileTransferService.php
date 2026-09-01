<?php

declare(strict_types=1);

namespace App\Domain\Session;

use App\Domain\Audit\AuditService;
use App\Domain\Audit\EventType;
use App\Domain\Policy\EffectivePolicy;
use App\Domain\Policy\PermissionCatalog;
use App\Domain\Support\ApiException;
use App\Domain\Support\Clock;
use App\Domain\Support\Ids;
use CodeIgniter\Database\BaseConnection;
use Config\Remote as RemoteConfig;

/**
 * Browser-to-browser file transfer (§36).
 *
 * **The bytes never touch this server.** They move peer-to-peer over the
 * RTCDataChannel, exactly like the screen. What lives here is the *ledger*: who
 * offered what to whom, whether the recipient accepted, and how it ended.
 *
 * That division is the point. A relayed file would need storage, retention, a
 * scanning story and a deletion story — none of which Remote wants to own for
 * a file two people are passing between themselves during a support call.
 *
 * The ledger is not decoration. It is what enforces the parts a peer must not
 * be trusted with:
 *
 *   * the organisation permits file transfer at all, and this user may send;
 *   * the recipient is a real, admitted participant of *this* session;
 *   * the file is within the configured size ceiling;
 *   * **the recipient accepted before a single byte was sent.**
 *
 * A client that skipped its own checks still cannot get a transfer past
 * {@see offer()} or {@see accept()}, and the receiving browser refuses chunks
 * for a transfer it has not accepted.
 */
class FileTransferService
{
    public const OFFERED     = 'OFFERED';
    public const ACCEPTED    = 'ACCEPTED';
    public const DECLINED    = 'DECLINED';
    public const IN_PROGRESS = 'IN_PROGRESS';
    public const COMPLETED   = 'COMPLETED';
    public const FAILED      = 'FAILED';
    public const CANCELLED   = 'CANCELLED';

    /** Statuses in which a transfer is still going somewhere. */
    private const LIVE = [self::OFFERED, self::ACCEPTED, self::IN_PROGRESS];

    /**
     * How many transfers one participant may have outstanding at once.
     *
     * Without a cap, a peer can create unbounded ledger rows by offering
     * thousands of files nobody will ever accept. Two is enough for the real
     * use — "here is the log, and here is the screenshot".
     */
    private const MAX_OUTSTANDING_PER_SENDER = 2;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly AuditService $audit,
        private readonly ParticipantService $participants,
        private readonly RemoteConfig $config,
    ) {
    }

    /**
     * Offer a file. Creates the ledger row; sends nothing.
     *
     * @param  array<string, mixed> $session
     * @param  array<string, mixed> $sender      the offering participant
     * @return array<string, mixed> the created transfer row
     */
    public function offer(
        array $session,
        array $sender,
        ?string $recipientUuid,
        string $fileName,
        int $fileSize,
        ?string $mimeType,
        EffectivePolicy $policy,
    ): array {
        $this->assertTransferable($session, $policy);

        if (! $policy->can(PermissionCatalog::FILE_SEND)) {
            throw ApiException::forbidden(
                'FILE_SEND_DENIED',
                'You do not have permission to send files in a Remote session.',
            );
        }

        if ($fileSize <= 0) {
            throw ApiException::badRequest('FILE_EMPTY', 'That file is empty.');
        }

        if ($fileSize > $this->config->fileTransferMaxBytes) {
            throw ApiException::badRequest(
                'FILE_TOO_LARGE',
                sprintf(
                    'Files must be %s or smaller.',
                    $this->humanBytes($this->config->fileTransferMaxBytes),
                ),
                ['maxBytes' => $this->config->fileTransferMaxBytes, 'fileSize' => $fileSize],
            );
        }

        $recipient = $this->resolveRecipient($session, $sender, $recipientUuid);

        $outstanding = $this->db->table('remote_file_transfers')
            ->where('session_id', $session['id'])
            ->where('from_participant_id', $sender['id'])
            ->whereIn('status', self::LIVE)
            ->countAllResults();

        if ($outstanding >= self::MAX_OUTSTANDING_PER_SENDER) {
            throw ApiException::conflict(
                'FILE_TRANSFER_BUSY',
                'Finish or cancel your current file transfer before starting another.',
            );
        }

        $uuid = Ids::uuid4();

        $this->db->table('remote_file_transfers')->insert([
            'uuid'                => $uuid,
            'session_id'          => $session['id'],
            'from_participant_id' => $sender['id'],
            'to_participant_id'   => $recipient['id'],
            // Stored for the record only. The receiving browser sanitises it
            // again before it is ever used as a download filename — a name is
            // attacker-controlled input, wherever it was stored in between.
            'file_name'           => $this->sanitiseFileName($fileName),
            'file_size'           => $fileSize,
            'mime_type'           => $this->sanitiseMimeType($mimeType),
            'status'              => self::OFFERED,
        ]);

        $transfer = $this->findByUuidOrFail($uuid);

        $this->audit->record(
            $session,
            EventType::FILE_TRANSFER_OFFERED,
            $sender['user_id'] !== null ? (int) $sender['user_id'] : null,
            $sender['user_id'] === null ? 'GUEST' : 'USER',
            (int) $sender['id'],
            (string) $sender['uuid'],
            [
                'transferUuid' => $uuid,
                // The name and size are business context an administrator needs
                // to answer "what left this machine?". The contents never are.
                'fileName'     => $transfer['file_name'],
                'fileSize'     => $fileSize,
                'toParticipant' => $recipient['uuid'],
            ],
        );

        return $transfer;
    }

    /**
     * The recipient accepts. **This is the gate the sender waits on.**
     *
     * The UPDATE is guarded on `status = 'OFFERED'`, so a double-click, or an
     * accept racing a cancel, resolves to exactly one outcome.
     *
     * @param  array<string, mixed> $session
     * @param  array<string, mixed> $participant the accepting participant
     * @return array<string, mixed>
     */
    public function accept(array $session, string $transferUuid, array $participant, EffectivePolicy $policy): array
    {
        $transfer = $this->findInSession((int) $session['id'], $transferUuid);

        $this->assertIsRecipient($transfer, $participant);

        if (! $policy->can(PermissionCatalog::FILE_RECEIVE)) {
            throw ApiException::forbidden(
                'FILE_RECEIVE_DENIED',
                'You do not have permission to receive files in a Remote session.',
            );
        }

        if ((string) $transfer['status'] === self::ACCEPTED) {
            return $transfer;
        }

        $this->db->table('remote_file_transfers')
            ->where('id', $transfer['id'])
            ->where('status', self::OFFERED)
            ->update(['status' => self::ACCEPTED, 'updated_at' => Clock::now()]);

        if ($this->db->affectedRows() === 0) {
            throw ApiException::conflict(
                'FILE_TRANSFER_NOT_OFFERED',
                'That file is no longer waiting for a decision.',
                ['status' => $transfer['status']],
            );
        }

        return $this->findByUuidOrFail($transferUuid);
    }

    /**
     * The recipient declines. Nothing was sent, so nothing has to be undone.
     *
     * @param  array<string, mixed> $session
     * @param  array<string, mixed> $participant
     * @return array<string, mixed>
     */
    public function decline(array $session, string $transferUuid, array $participant): array
    {
        $transfer = $this->findInSession((int) $session['id'], $transferUuid);
        $this->assertIsRecipient($transfer, $participant);

        $this->db->table('remote_file_transfers')
            ->where('id', $transfer['id'])
            ->whereIn('status', [self::OFFERED, self::ACCEPTED])
            ->update(['status' => self::DECLINED, 'completed_at' => Clock::now(), 'updated_at' => Clock::now()]);

        if ($this->db->affectedRows() === 0) {
            throw ApiException::conflict(
                'FILE_TRANSFER_NOT_OFFERED',
                'That file is no longer waiting for a decision.',
                ['status' => $transfer['status']],
            );
        }

        $updated = $this->findByUuidOrFail($transferUuid);

        $this->audit->record(
            $session,
            EventType::FILE_TRANSFER_DECLINED,
            $participant['user_id'] !== null ? (int) $participant['user_id'] : null,
            $participant['user_id'] === null ? 'GUEST' : 'USER',
            (int) $participant['id'],
            (string) $participant['uuid'],
            ['transferUuid' => $transferUuid, 'fileName' => $updated['file_name']],
        );

        return $updated;
    }

    /**
     * The sender has begun putting bytes on the wire, or is reporting how far
     * it has got.
     *
     * Progress is reported sparingly — the client throttles it — because the
     * ledger only needs to show that a large transfer is moving, not to mirror
     * every chunk.
     *
     * @param  array<string, mixed> $session
     * @param  array<string, mixed> $participant
     * @return array<string, mixed>
     */
    public function progress(array $session, string $transferUuid, array $participant, int $bytesTransferred): array
    {
        $transfer = $this->findInSession((int) $session['id'], $transferUuid);
        $this->assertIsParty($transfer, $participant);

        if (! in_array((string) $transfer['status'], [self::ACCEPTED, self::IN_PROGRESS], true)) {
            throw ApiException::conflict(
                'FILE_TRANSFER_NOT_ACTIVE',
                'That file transfer is not running.',
                ['status' => $transfer['status']],
            );
        }

        $bytes = max(0, min($bytesTransferred, (int) $transfer['file_size']));
        $first = (string) $transfer['status'] === self::ACCEPTED;

        $this->db->table('remote_file_transfers')
            ->where('id', $transfer['id'])
            ->whereIn('status', [self::ACCEPTED, self::IN_PROGRESS])
            ->set('status', self::IN_PROGRESS)
            ->set('bytes_transferred', $bytes)
            ->set('started_at', $transfer['started_at'] ?? Clock::now())
            ->set('updated_at', Clock::now())
            ->update();

        if ($first) {
            $this->audit->record(
                $session,
                EventType::FILE_TRANSFER_STARTED,
                $participant['user_id'] !== null ? (int) $participant['user_id'] : null,
                $participant['user_id'] === null ? 'GUEST' : 'USER',
                (int) $participant['id'],
                (string) $participant['uuid'],
                ['transferUuid' => $transferUuid, 'fileName' => $transfer['file_name'], 'fileSize' => (int) $transfer['file_size']],
            );
        }

        return $this->findByUuidOrFail($transferUuid);
    }

    /**
     * Every byte arrived. Reported by the **recipient**, which is the only side
     * that actually knows.
     *
     * @param  array<string, mixed> $session
     * @param  array<string, mixed> $participant
     * @return array<string, mixed>
     */
    public function complete(array $session, string $transferUuid, array $participant): array
    {
        $transfer = $this->findInSession((int) $session['id'], $transferUuid);
        $this->assertIsRecipient($transfer, $participant);

        if ((string) $transfer['status'] === self::COMPLETED) {
            return $transfer;
        }

        $this->db->table('remote_file_transfers')
            ->where('id', $transfer['id'])
            ->whereIn('status', [self::ACCEPTED, self::IN_PROGRESS])
            ->set('status', self::COMPLETED)
            ->set('bytes_transferred', (int) $transfer['file_size'])
            ->set('completed_at', Clock::now())
            ->set('updated_at', Clock::now())
            ->update();

        if ($this->db->affectedRows() === 0) {
            throw ApiException::conflict(
                'FILE_TRANSFER_NOT_ACTIVE',
                'That file transfer is not running.',
                ['status' => $transfer['status']],
            );
        }

        $updated = $this->findByUuidOrFail($transferUuid);

        $this->audit->record(
            $session,
            EventType::FILE_TRANSFER_COMPLETED,
            $participant['user_id'] !== null ? (int) $participant['user_id'] : null,
            $participant['user_id'] === null ? 'GUEST' : 'USER',
            (int) $participant['id'],
            (string) $participant['uuid'],
            [
                'transferUuid' => $transferUuid,
                'fileName'     => $updated['file_name'],
                'fileSize'     => (int) $updated['file_size'],
            ],
        );

        return $updated;
    }

    /**
     * Either side gives up: a cancel, a dropped connection, a size mismatch on
     * arrival.
     *
     * @param  array<string, mixed> $session
     * @param  array<string, mixed> $participant
     * @return array<string, mixed>
     */
    public function abort(
        array $session,
        string $transferUuid,
        array $participant,
        string $status,
        ?string $errorCode = null,
    ): array {
        if (! in_array($status, [self::FAILED, self::CANCELLED], true)) {
            throw ApiException::badRequest('FILE_STATUS_INVALID', 'A transfer can only be cancelled or failed.');
        }

        $transfer = $this->findInSession((int) $session['id'], $transferUuid);
        $this->assertIsParty($transfer, $participant);

        if (! in_array((string) $transfer['status'], self::LIVE, true)) {
            // Already finished one way or another. Aborting is idempotent so a
            // dropped connection reporting late is not an error.
            return $transfer;
        }

        $this->db->table('remote_file_transfers')
            ->where('id', $transfer['id'])
            ->whereIn('status', self::LIVE)
            ->set('status', $status)
            ->set('error_code', $errorCode !== null ? mb_substr($errorCode, 0, 40) : null)
            ->set('completed_at', Clock::now())
            ->set('updated_at', Clock::now())
            ->update();

        $updated = $this->findByUuidOrFail($transferUuid);

        if ($status === self::FAILED) {
            $this->audit->record(
                $session,
                EventType::FILE_TRANSFER_FAILED,
                $participant['user_id'] !== null ? (int) $participant['user_id'] : null,
                $participant['user_id'] === null ? 'GUEST' : 'USER',
                (int) $participant['id'],
                (string) $participant['uuid'],
                ['transferUuid' => $transferUuid, 'fileName' => $updated['file_name'], 'errorCode' => $errorCode],
            );
        }

        return $updated;
    }

    /**
     * Everything offered in this session, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function forSession(int $sessionId): array
    {
        return $this->db->table('remote_file_transfers t')
            ->select('t.*, f.uuid AS from_uuid, f.display_name AS from_name, r.uuid AS to_uuid, r.display_name AS to_name')
            ->join('remote_participants f', 'f.id = t.from_participant_id', 'left')
            ->join('remote_participants r', 'r.id = t.to_participant_id', 'left')
            ->where('t.session_id', $sessionId)
            ->orderBy('t.created_at', 'DESC')
            ->limit(50)
            ->get()
            ->getResultArray();
    }

    // ------------------------------------------------------------ internals

    /** @param array<string, mixed> $session */
    private function assertTransferable(array $session, EffectivePolicy $policy): void
    {
        if (! SessionStatus::isLive((string) $session['status'])) {
            throw ApiException::conflict('SESSION_ALREADY_ENDED', 'This Remote session has already finished.');
        }

        // Both the session's own snapshot and the current policy must permit it.
        // The snapshot is what the session was created under; the policy is what
        // applies now. A capability needs both (§9).
        if (! $session['allow_file_transfer'] || ! $policy->allowFileTransfer) {
            throw ApiException::forbidden(
                'FILE_TRANSFER_DISABLED',
                'File transfer is turned off for this Remote session.',
            );
        }
    }

    /**
     * The recipient must be a real participant of this session who has been
     * admitted — not merely a uuid the sender typed.
     *
     * @param  array<string, mixed> $session
     * @param  array<string, mixed> $sender
     * @return array<string, mixed>
     */
    private function resolveRecipient(array $session, array $sender, ?string $recipientUuid): array
    {
        $admitted = array_values(array_filter(
            $this->participants->forSession((int) $session['id']),
            static fn (array $participant) => (int) $participant['id'] !== (int) $sender['id']
                && in_array((string) $participant['status'], ['APPROVED', 'JOINED'], true),
        ));

        if ($recipientUuid !== null) {
            foreach ($admitted as $participant) {
                if (hash_equals((string) $participant['uuid'], $recipientUuid)) {
                    return $participant;
                }
            }

            throw ApiException::notFound('That participant could not be found in this session.');
        }

        if ($admitted === []) {
            throw ApiException::conflict(
                'NO_RECIPIENT',
                'There is nobody in this session to send a file to yet.',
            );
        }

        if (count($admitted) > 1) {
            throw ApiException::badRequest(
                'RECIPIENT_REQUIRED',
                'Choose who should receive this file.',
            );
        }

        return $admitted[0];
    }

    /** @param array<string, mixed> $transfer @param array<string, mixed> $participant */
    private function assertIsRecipient(array $transfer, array $participant): void
    {
        if ((int) ($transfer['to_participant_id'] ?? 0) !== (int) $participant['id']) {
            throw ApiException::forbidden(
                'NOT_FILE_RECIPIENT',
                'Only the person this file was sent to can answer for it.',
            );
        }
    }

    /** @param array<string, mixed> $transfer @param array<string, mixed> $participant */
    private function assertIsParty(array $transfer, array $participant): void
    {
        $id = (int) $participant['id'];

        if ((int) ($transfer['from_participant_id'] ?? 0) !== $id
            && (int) ($transfer['to_participant_id'] ?? 0) !== $id) {
            throw ApiException::forbidden('NOT_FILE_PARTY', 'You are not part of that file transfer.');
        }
    }

    /** @return array<string, mixed> */
    private function findInSession(int $sessionId, string $uuid): array
    {
        if (! Ids::isUuid($uuid)) {
            throw ApiException::notFound('That file transfer could not be found.');
        }

        $row = $this->db->table('remote_file_transfers')
            ->where('session_id', $sessionId)
            ->where('uuid', $uuid)
            ->get()
            ->getRowArray();

        if ($row === null) {
            throw ApiException::notFound('That file transfer could not be found.');
        }

        return $row;
    }

    /** @return array<string, mixed> */
    public function findByUuidOrFail(string $uuid): array
    {
        $row = $this->db->table('remote_file_transfers t')
            ->select('t.*, f.uuid AS from_uuid, f.display_name AS from_name, r.uuid AS to_uuid, r.display_name AS to_name')
            ->join('remote_participants f', 'f.id = t.from_participant_id', 'left')
            ->join('remote_participants r', 'r.id = t.to_participant_id', 'left')
            ->where('t.uuid', $uuid)
            ->get()
            ->getRowArray();

        if ($row === null) {
            throw ApiException::notFound('That file transfer could not be found.');
        }

        return $row;
    }

    /**
     * Reduce a client-supplied name to something safe to store and display.
     *
     * Path separators, traversal segments, control characters and leading dots
     * all go. The receiving browser sanitises again before using it as a
     * download filename — a name is attacker-controlled input at every hop, and
     * the hop that matters is the one where it becomes a path.
     */
    private function sanitiseFileName(string $name): string
    {
        $name = str_replace(['/', '\\', "\0"], '_', trim($name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = ltrim($name, '.');
        $name = trim($name);

        if ($name === '') {
            return 'file';
        }

        return mb_substr($name, 0, 255);
    }

    /**
     * A MIME type is a claim by the sender's browser, so it is stored for the
     * record and never trusted. The receiving side downloads every file as an
     * opaque binary, whatever this says.
     */
    private function sanitiseMimeType(?string $mimeType): ?string
    {
        if ($mimeType === null) {
            return null;
        }

        $clean = preg_replace('/[^A-Za-z0-9!#$&^_.+\-\/]/', '', trim($mimeType)) ?? '';

        return $clean === '' ? null : mb_substr($clean, 0, 160);
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024)) . ' MB';
        }

        return round($bytes / 1024) . ' KB';
    }
}
