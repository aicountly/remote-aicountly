<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Support\Ids;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\IncomingRequest;
use RuntimeException;
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
     * Append to the security record, best effort.
     *
     * A trail that cannot be written must not take the request down with it:
     * a failure is logged and swallowed. Inside a transaction the insert runs
     * under a savepoint so a failed audit statement cannot poison the caller's
     * own work. Use {@see recordMaterial()} where the change must not outlive
     * its record.
     *
     * @param array<string, mixed>                                    $metadata
     * @param array{type: string, id?: ?string, label?: ?string}|null $entity   the record the row is about; derived from
     *                                                                           the session or device when omitted
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
        ?array $entity = null,
        ?string $summary = null,
        ?string $severity = null,
    ): void {
        $inTransaction = $this->db->transDepth > 0;

        try {
            if ($inTransaction) {
                $this->db->query('SAVEPOINT remote_audit_row');
            }

            $this->insertAudit(
                $event, $actorUserId, $actorType, $companyId, $sessionUuid, $participantUuid,
                $sourceProduct, $metadata, $entity, $summary, $severity,
            );

            if ($inTransaction) {
                $this->db->query('RELEASE SAVEPOINT remote_audit_row');
            }
        } catch (Throwable $e) {
            if ($inTransaction) {
                try {
                    $this->db->query('ROLLBACK TO SAVEPOINT remote_audit_row');
                } catch (Throwable) {
                    // The transaction is beyond saving; the caller will find out.
                }
            }
            log_message('error', 'Audit row for {event} could not be written: {error}', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Append to the security record, fail closed.
     *
     * For changes that must not exist without their record — access, policy,
     * credentials, deletions. Call it inside the same transaction as the
     * change: if the row cannot be written this throws, and the transaction
     * rolls the change back with it.
     *
     * @param array<string, mixed>                                    $metadata
     * @param array{type: string, id?: ?string, label?: ?string}|null $entity
     */
    public function recordMaterial(
        string $event,
        ?int $actorUserId = null,
        string $actorType = 'USER',
        ?int $companyId = null,
        ?string $sessionUuid = null,
        ?string $participantUuid = null,
        ?string $sourceProduct = null,
        array $metadata = [],
        ?array $entity = null,
        ?string $summary = null,
        ?string $severity = null,
    ): void {
        $this->insertAudit(
            $event, $actorUserId, $actorType, $companyId, $sessionUuid, $participantUuid,
            $sourceProduct, $metadata, $entity, $summary, $severity,
        );
    }

    /**
     * @param array<string, mixed>                                    $metadata
     * @param array{type: string, id?: ?string, label?: ?string}|null $entity
     */
    private function insertAudit(
        string $event,
        ?int $actorUserId,
        string $actorType,
        ?int $companyId,
        ?string $sessionUuid,
        ?string $participantUuid,
        ?string $sourceProduct,
        array $metadata,
        ?array $entity,
        ?string $summary,
        ?string $severity,
    ): void {
        $entity ??= $this->deriveEntity($sessionUuid, $metadata);
        [$actorUuid, $actorLabel] = $this->actorOf($actorUserId, $actorType);

        $entityLabel = isset($entity['label']) ? mb_substr((string) $entity['label'], 0, 200) : null;
        $summary ??= EventType::describe($event) . ($entityLabel !== null && $entityLabel !== '' ? ': ' . $entityLabel : '');

        $ok = $this->db->table('remote_audit_logs')->insert([
            'uuid'             => Ids::uuid4(),
            'event'            => $event,
            'actor_user_id'    => $actorUserId,
            'actor_type'       => $actorType,
            'actor_uuid'       => $actorUuid,
            'actor_label'      => $actorLabel,
            'company_id'       => $companyId,
            'session_uuid'     => $sessionUuid,
            'participant_uuid' => $participantUuid,
            'source_product'   => $sourceProduct,
            'entity_type'      => $entity['type'] ?? null,
            'entity_id'        => isset($entity['id']) ? mb_substr((string) $entity['id'], 0, 64) : null,
            'entity_label'     => $entityLabel,
            'summary'          => mb_substr($summary, 0, 500),
            'severity'         => $severity ?? EventType::severity($event),
            'ip'               => $this->clientIp(),
            'user_agent'       => $this->userAgent(),
            'request_id'       => $this->requestId(),
            'metadata'         => json_encode($this->scrub($metadata), JSON_UNESCAPED_SLASHES),
        ]);

        if ($ok === false) {
            throw new RuntimeException("The audit row for {$event} could not be written.");
        }
    }

    /**
     * Field-level changes between two states, for `metadata.changes`.
     *
     * Unchanged fields are skipped, and so is anything named like a secret —
     * the entry would otherwise be the one place a credential was kept in the
     * clear. Booleans and scalars are kept as they are so the screen can show
     * `old -> new` without guessing.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, string> $labels field => human label; the field name is humanised when absent
     * @return list<array{field: string, label: string, old: mixed, new: mixed}>
     */
    public static function diff(array $before, array $after, array $labels = []): array
    {
        $changes = [];

        foreach ($after as $field => $new) {
            $field = (string) $field;
            if (self::isSecretField($field)) {
                continue;
            }

            $old = $before[$field] ?? null;
            if ($old === $new || (is_scalar($old) && is_scalar($new) && (string) $old === (string) $new)) {
                continue;
            }

            $changes[] = [
                'field' => $field,
                'label' => $labels[$field] ?? ucfirst(str_replace('_', ' ', $field)),
                'old'   => self::scalarOrNull($old),
                'new'   => self::scalarOrNull($new),
            ];
        }

        return $changes;
    }

    /** Names that must never reach the trail, whether as a metadata key or a changed field. */
    public static function isSecretField(string $field): bool
    {
        return (bool) preg_match('/pass(word|wd)?|token|secret|otp|api[_-]?key|private[_-]?key|\bpin\b|cvv|credential|ses_?key|authorization/i', $field);
    }

    private static function scalarOrNull(mixed $value): mixed
    {
        if (is_scalar($value) || $value === null) {
            return is_string($value) ? mb_substr($value, 0, 500) : $value;
        }

        return 'changed';
    }

    /**
     * Which record a row is about, when the caller did not say.
     *
     * @param  array<string, mixed>                                     $metadata
     * @return array{type: string, id: ?string, label: ?string}|null
     */
    private function deriveEntity(?string $sessionUuid, array $metadata): ?array
    {
        if ($sessionUuid !== null && $sessionUuid !== '') {
            return ['type' => 'session', 'id' => $sessionUuid, 'label' => null];
        }

        if (isset($metadata['deviceUuid']) && is_string($metadata['deviceUuid'])) {
            return [
                'type'  => 'device',
                'id'    => $metadata['deviceUuid'],
                'label' => is_string($metadata['deviceName'] ?? null) ? $metadata['deviceName'] : null,
            ];
        }

        return null;
    }

    /**
     * The actor's stable uuid and name as they are right now. A row keeps what
     * was true when it was written, not whatever the directory says later.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function actorOf(?int $actorUserId, string $actorType): array
    {
        if ($actorUserId !== null) {
            $row = $this->db->query(
                'SELECT platform_uuid, display_name, email FROM remote_identities WHERE id = ?',
                [$actorUserId],
            )->getRowArray();

            if ($row !== null) {
                $label = trim((string) $row['display_name']) !== '' ? (string) $row['display_name'] : (string) ($row['email'] ?? '');

                return [(string) $row['platform_uuid'], $label !== '' ? $label : null];
            }
        }

        return $actorType === 'SYSTEM' ? ['system:remote', 'AICOUNTLY Remote'] : [null, null];
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
