<?php

declare(strict_types=1);

namespace App\Domain\Session;

use App\Domain\Audit\AuditService;
use App\Domain\Audit\EventType;
use App\Domain\Support\ApiException;
use App\Domain\Support\Ids;
use CodeIgniter\Database\BaseConnection;

/**
 * Session chat (§35).
 *
 * Messages travel peer-to-peer over the RTCDataChannel; this is the durable
 * copy, and the fallback path for the moments when the data channel is not up
 * — a viewer who is still connecting, or a connection that dropped mid-sentence.
 *
 * Chat is stored *here*, not in the audit trail. The audit record notes that a
 * conversation happened (CHAT_STARTED) and nothing about what was said, so
 * turning on advanced audit never turns on transcript retention (§60).
 */
class ChatService
{
    private const MAX_BODY_LENGTH = 4000;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * @param  array<string, mixed> $session
     * @param  array<string, mixed> $participant
     * @return array<string, mixed>
     */
    public function post(array $session, array $participant, string $body, string $deliveredVia = 'DATA_CHANNEL'): array
    {
        if (! (bool) $session['allow_chat']) {
            throw ApiException::forbidden('CHAT_DISABLED', 'Chat is turned off for this Remote session.');
        }

        if (! SessionStatus::isLive((string) $session['status'])) {
            throw ApiException::conflict('SESSION_ALREADY_ENDED', 'This Remote session has already finished.');
        }

        $body = trim($body);
        if ($body === '') {
            throw ApiException::badRequest('MESSAGE_EMPTY', 'Type a message before sending it.');
        }
        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            throw ApiException::badRequest('MESSAGE_TOO_LONG', 'That message is too long to send.');
        }

        $isFirst = $this->db->table('remote_messages')->where('session_id', $session['id'])->countAllResults() === 0;

        $uuid = Ids::uuid4();

        $this->db->table('remote_messages')->insert([
            'uuid'           => $uuid,
            'session_id'     => $session['id'],
            'participant_id' => $participant['id'],
            'author_user_id' => $participant['user_id'],
            'author_name'    => $participant['display_name'],
            'message_type'   => 'USER',
            'body'           => $body,
            'delivered_via'  => in_array($deliveredVia, ['DATA_CHANNEL', 'RELAY'], true) ? $deliveredVia : 'RELAY',
        ]);

        if ($isFirst) {
            // Recorded once per session, with no content: "they talked", not
            // "here is what they said".
            $this->audit->record(
                $session,
                EventType::CHAT_STARTED,
                $participant['user_id'] !== null ? (int) $participant['user_id'] : null,
                $participant['user_id'] === null ? 'GUEST' : 'USER',
                (int) $participant['id'],
                (string) $participant['uuid'],
            );
        }

        return $this->db->table('remote_messages')->where('uuid', $uuid)->get()->getRowArray() ?? [];
    }

    /**
     * Messages since a given one, for a client catching up after a reconnect.
     *
     * @return list<array<string, mixed>>
     */
    public function history(int $sessionId, ?string $afterUuid = null, int $limit = 200): array
    {
        $builder = $this->db->table('remote_messages')
            ->where('session_id', $sessionId)
            ->orderBy('created_at', 'ASC')
            ->orderBy('id', 'ASC')
            ->limit($limit);

        if ($afterUuid !== null && Ids::isUuid($afterUuid)) {
            $anchor = $this->db->table('remote_messages')
                ->select('id')
                ->where('session_id', $sessionId)
                ->where('uuid', $afterUuid)
                ->get()
                ->getRowArray();

            if ($anchor !== null) {
                $builder->where('id >', (int) $anchor['id']);
            }
        }

        return $builder->get()->getResultArray();
    }

    /** A system note in the transcript — "Aman joined", "sharing stopped". */
    public function system(int $sessionId, string $body): void
    {
        $this->db->table('remote_messages')->insert([
            'uuid'          => Ids::uuid4(),
            'session_id'    => $sessionId,
            'author_name'   => 'AICOUNTLY Remote',
            'message_type'  => 'SYSTEM',
            'body'          => mb_substr($body, 0, self::MAX_BODY_LENGTH),
            'delivered_via' => 'RELAY',
        ]);
    }
}
