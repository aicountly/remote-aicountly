<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Support\Ids;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\IncomingRequest;
use Throwable;

/**
 * Writes the two records a session leaves behind (§60).
 *
 * `recordEvent()` appends to a session's own timeline — the thing shown on the
 * session detail page to anyone who can see that session.
 *
 * `recordAudit()` appends to the security record — actor, company, IP, user
 * agent, request id — readable only with `remote.audit.view`, and kept whether
 * or not the session survives.
 *
 * Most meaningful moments want both, which is what {@see record()} does.
 *
 * Neither ever receives screen content, a chat body, a token or a credential;
 * {@see scrub()} strips anything that looks like one before it reaches the
 * database, because the caller that forgets is the one that matters.
 */
class AuditService
{
    /** Metadata keys that must never be persisted, whoever passes them. */
    private const FORBIDDEN_KEYS = [
        'password', 'token', 'ses_key', 'seskey', 'auth_token', 'authorization',
        'secret', 'credential', 'turn_credential', 'signalling_token', 'jwt',
        'body', 'message', 'chat', 'transcript', 'frame', 'screenshot', 'image',
    ];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly ?IncomingRequest $request = null,
    ) {
    }

    /**
     * Append to the session timeline.
     *
     * @param array<string, mixed> $metadata
     */
    public function recordEvent(
        int $sessionId,
        string $eventType,
        ?int $actorUserId = null,
        string $actorType = 'USER',
        ?int $participantId = null,
        array $metadata = [],
    ): void {
        $this->db->table('remote_session_events')->insert([
            'session_id'     => $sessionId,
            'participant_id' => $participantId,
            'event_type'     => $eventType,
            'actor_user_id'  => $actorUserId,
            'actor_type'     => $actorType,
            'metadata'       => json_encode($this->scrub($metadata), JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * Append to the security record.
     *
     * @param array<string, mixed> $metadata
     */
    public function recordAudit(
        string $event,
        ?int $actorUserId = null,
        string $actorType = 'USER',
        ?int $companyId = null,
        ?string $sessionUuid = null,
        ?string $participantUuid = null,
        ?string $sourceProduct = null,
        array $metadata = [],
    ): void {
        $this->db->table('remote_audit_logs')->insert([
            'uuid'             => Ids::uuid4(),
            'event'            => $event,
            'actor_user_id'    => $actorUserId,
            'actor_type'       => $actorType,
            'company_id'       => $companyId,
            'session_uuid'     => $sessionUuid,
            'participant_uuid' => $participantUuid,
            'source_product'   => $sourceProduct,
            'ip'               => $this->clientIp(),
            'user_agent'       => $this->userAgent(),
            'request_id'       => $this->requestId(),
            'metadata'         => json_encode($this->scrub($metadata), JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * The common case: one moment, written to both records.
     *
     * @param array<string, mixed> $session  the session row (id, uuid, company_id, source_product)
     * @param array<string, mixed> $metadata
     */
    public function record(
        array $session,
        string $eventType,
        ?int $actorUserId = null,
        string $actorType = 'USER',
        ?int $participantId = null,
        ?string $participantUuid = null,
        array $metadata = [],
    ): void {
        $this->recordEvent(
            (int) $session['id'],
            $eventType,
            $actorUserId,
            $actorType,
            $participantId,
            $metadata,
        );

        $this->recordAudit(
            $eventType,
            $actorUserId,
            $actorType,
            isset($session['company_id']) && $session['company_id'] !== null ? (int) $session['company_id'] : null,
            (string) $session['uuid'],
            $participantUuid,
            $session['source_product'] ?? null,
            $metadata,
        );
    }

    /**
     * Remove anything that must not be persisted, at any depth.
     *
     * Values are also length-capped: a 2 MB "reason" string in a JSONB column
     * is a denial-of-service dressed up as diagnostics.
     *
     * @param  array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function scrub(array $metadata, int $depth = 0): array
    {
        if ($depth > 4) {
            return [];
        }

        $clean = [];

        foreach ($metadata as $key => $value) {
            $normalised = strtolower((string) $key);

            foreach (self::FORBIDDEN_KEYS as $forbidden) {
                if (str_contains($normalised, $forbidden)) {
                    continue 2;
                }
            }

            if (is_array($value)) {
                $clean[$key] = $this->scrub($value, $depth + 1);
            } elseif (is_string($value)) {
                $clean[$key] = mb_substr($value, 0, 500);
            } elseif (is_scalar($value) || $value === null) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    private function clientIp(): ?string
    {
        try {
            $ip = $this->request?->getIPAddress();
        } catch (Throwable) {
            return null;
        }

        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }

    private function userAgent(): ?string
    {
        $agent = $this->request?->getUserAgent()?->getAgentString();

        return is_string($agent) && $agent !== '' ? mb_substr($agent, 0, 500) : null;
    }

    private function requestId(): ?string
    {
        $id = $this->request?->getHeaderLine('X-Request-Id');
        if (is_string($id) && $id !== '') {
            return mb_substr(preg_replace('/[^A-Za-z0-9._-]/', '', $id) ?? '', 0, 64);
        }

        return null;
    }
}
