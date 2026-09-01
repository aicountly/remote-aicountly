<?php

declare(strict_types=1);

namespace App\Domain\Support;

/**
 * Database rows → API resources.
 *
 * Three rules hold for everything here:
 *
 *   * **No serial ids leave the server.** A resource is identified by its UUID
 *     or its display id; `id` columns stay internal (§26).
 *   * **Timestamps are ISO-8601 UTC**, formatted once, here (§96). The client
 *     renders them in the viewer's own timezone.
 *   * **IP addresses and user agents are privileged.** They appear only when
 *     the caller holds `remote.audit.view`, which is what `$includeAudit` means
 *     (§42).
 */
final class Presenter
{
    /** Human-readable AICOUNTLY product names (§69). */
    private const PRODUCT_LABELS = [
        'BOOKS'     => 'AICOUNTLY Books',
        'HRMS'      => 'AICOUNTLY HRMS',
        'AUDITOR'   => 'AICOUNTLY Auditor',
        'INVENTORY' => 'AICOUNTLY Inventory',
        'ADVISOR'   => 'AICOUNTLY Advisor',
        'MANAGE'    => 'AICOUNTLY Manage',
        'PULSE'     => 'AICOUNTLY Pulse',
        'CONNECT'   => 'AICOUNTLY Connect',
        'REMOTE'    => 'AICOUNTLY Remote',
    ];

    public static function productLabel(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        return self::PRODUCT_LABELS[strtoupper($code)] ?? $code;
    }

    /**
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function session(array $row, bool $includeAudit = false): array
    {
        $startedAt = $row['started_at'] ?? null;
        $endedAt   = $row['ended_at'] ?? null;

        $duration = null;
        if ($startedAt !== null && $endedAt !== null) {
            $duration = max(0, (int) (strtotime((string) $endedAt) - strtotime((string) $startedAt)));
        }

        $resource = [
            'uuid'            => (string) $row['uuid'],
            'displayId'       => (string) $row['display_id'],
            'sessionCode'     => $row['session_code'] !== null ? Ids::formatJoinCode((string) $row['session_code']) : null,
            'scopeType'       => (string) $row['scope_type'],
            'companyId'       => $row['company_id'] !== null ? (int) $row['company_id'] : null,
            'companyName'     => $row['company_name'] ?? null,
            'branchId'        => $row['branch_id'] !== null ? (int) $row['branch_id'] : null,
            'financialYearId' => $row['financial_year_id'] !== null ? (int) $row['financial_year_id'] : null,
            'sessionType'     => (string) $row['session_type'],
            'status'          => (string) $row['status'],
            'requestedShareMode'   => (string) $row['requested_share_mode'],
            'actualDisplaySurface' => $row['actual_display_surface'] ?? null,
            'sourceProduct'      => $row['source_product'] ?? null,
            'sourceProductLabel' => self::productLabel($row['source_product'] ?? null),
            'sourceRoute'        => $row['source_route'] ?? null,
            'supportTicketId'    => $row['support_ticket_id'] ?? null,
            'issueSummary'       => $row['issue_summary'] ?? null,
            'ownerName'          => $row['owner_name'] ?? null,
            'capabilities'    => [
                'audio'        => self::bool($row['allow_audio'] ?? false),
                'systemAudio'  => self::bool($row['allow_system_audio'] ?? false),
                'chat'         => self::bool($row['allow_chat'] ?? false),
                'annotation'   => self::bool($row['allow_annotation'] ?? false),
                'fileTransfer' => self::bool($row['allow_file_transfer'] ?? false),
                'recording'    => self::bool($row['allow_recording'] ?? false),
                'externalGuest' => self::bool($row['allow_external_guest'] ?? false),
            ],
            'maxDurationMinutes' => (int) $row['max_duration_minutes'],
            'startedAt'  => Clock::iso($startedAt),
            'endedAt'    => Clock::iso($endedAt),
            'expiresAt'  => Clock::iso($row['expires_at'] ?? null),
            'createdAt'  => Clock::iso($row['created_at'] ?? null),
            'durationSeconds' => $duration,
            'endReason'  => $row['end_reason'] ?? null,
        ];

        if ($includeAudit) {
            $resource['audit'] = [
                'createdIp'        => $row['created_ip'] ?? null,
                'createdUserAgent' => $row['created_user_agent'] ?? null,
            ];
        }

        return $resource;
    }

    /**
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function participant(array $row, bool $includeAudit = false): array
    {
        $capabilities = $row['capabilities'] ?? '{}';
        if (is_string($capabilities)) {
            $capabilities = json_decode($capabilities, true) ?: [];
        }

        $resource = [
            'uuid'            => (string) $row['uuid'],
            'displayName'     => (string) $row['display_name'],
            'role'            => (string) $row['participant_role'],
            'clientType'      => (string) $row['client_type'],
            'status'          => (string) $row['status'],
            'isHost'          => self::bool($row['is_host'] ?? false),
            'isSharing'       => self::bool($row['is_sharing'] ?? false),
            'microphoneEnabled' => self::bool($row['microphone_enabled'] ?? false),
            'connectionState' => (string) ($row['connection_state'] ?? 'IDLE'),
            'capabilities'    => $capabilities,
            'requestedAt'     => Clock::iso($row['requested_at'] ?? null),
            'joinedAt'        => Clock::iso($row['joined_at'] ?? null),
            'leftAt'          => Clock::iso($row['left_at'] ?? null),
        ];

        if ($includeAudit) {
            $resource['audit'] = [
                'ip'        => $row['ip'] ?? null,
                'userAgent' => $row['user_agent'] ?? null,
                'email'     => $row['email'] ?? null,
            ];
        }

        return $resource;
    }

    /**
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function invitation(array $row): array
    {
        return [
            'uuid'           => (string) $row['uuid'],
            'invitationType' => (string) $row['invitation_type'],
            'inviteeEmail'   => $row['invitee_email'] ?? null,
            'usedCount'      => (int) $row['used_count'],
            'maxUses'        => (int) $row['max_uses'],
            'redeemedAt'     => Clock::iso($row['redeemed_at'] ?? null),
            'revokedAt'      => Clock::iso($row['revoked_at'] ?? null),
            'expiresAt'      => Clock::iso($row['expires_at'] ?? null),
            'createdAt'      => Clock::iso($row['created_at'] ?? null),
            // The secret is never here. It exists once, in the creation
            // response, and only its hash is stored.
        ];
    }

    /**
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function supportRequest(array $row): array
    {
        return [
            'uuid'          => (string) $row['uuid'],
            'status'        => (string) $row['status'],
            'priority'      => (string) $row['priority'],
            'requesterName' => (string) $row['requester_name'],
            'companyId'     => $row['company_id'] !== null ? (int) $row['company_id'] : null,
            'companyName'   => $row['company_name'] ?? null,
            'sourceProduct'      => $row['source_product'] ?? null,
            'sourceProductLabel' => self::productLabel($row['source_product'] ?? null),
            'sourceRoute'        => $row['source_route'] ?? null,
            'supportTicketId'    => $row['support_ticket_id'] ?? null,
            'issueSummary'       => $row['issue_summary'] ?? null,
            'requestedShareMode' => (string) $row['requested_share_mode'],
            'sessionUuid'        => $row['session_uuid'] ?? null,
            'sessionDisplayId'   => $row['session_display_id'] ?? null,
            'sessionStatus'      => $row['session_status'] ?? null,
            'acceptedAt'         => Clock::iso($row['accepted_at'] ?? null),
            'expiresAt'          => Clock::iso($row['expires_at'] ?? null),
            'createdAt'          => Clock::iso($row['created_at'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function event(array $row): array
    {
        $metadata = $row['metadata'] ?? '{}';
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true) ?: [];
        }

        return [
            'eventType'  => (string) $row['event_type'],
            'actorType'  => (string) $row['actor_type'],
            'actorName'  => $row['actor_name'] ?? null,
            'metadata'   => $metadata,
            'occurredAt' => Clock::iso($row['occurred_at'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function auditEntry(array $row): array
    {
        $metadata = $row['metadata'] ?? '{}';
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true) ?: [];
        }

        return [
            'uuid'            => (string) $row['uuid'],
            'event'           => (string) $row['event'],
            'actorType'       => (string) $row['actor_type'],
            'actorName'       => $row['actor_name'] ?? null,
            'companyId'       => $row['company_id'] !== null ? (int) $row['company_id'] : null,
            'sessionUuid'     => $row['session_uuid'] ?? null,
            'sourceProduct'   => $row['source_product'] ?? null,
            'ip'              => $row['ip'] ?? null,
            'userAgent'       => $row['user_agent'] ?? null,
            'metadata'        => $metadata,
            'createdAt'       => Clock::iso($row['created_at'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function message(array $row): array
    {
        return [
            'uuid'        => (string) $row['uuid'],
            'authorName'  => (string) $row['author_name'],
            'messageType' => (string) $row['message_type'],
            'body'        => (string) $row['body'],
            'createdAt'   => Clock::iso($row['created_at'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function fileTransfer(array $row): array
    {
        $size  = (int) $row['file_size'];
        $moved = (int) ($row['bytes_transferred'] ?? 0);

        return [
            'uuid'      => (string) $row['uuid'],
            'fileName'  => (string) $row['file_name'],
            'fileSize'  => $size,
            'mimeType'  => $row['mime_type'] ?? null,
            'status'    => (string) $row['status'],
            'bytesTransferred' => $moved,
            // Computed here so every screen shows the same number rather than
            // each one dividing it slightly differently.
            'progress'  => $size > 0 ? min(100, (int) round(($moved / $size) * 100)) : 0,
            'errorCode' => $row['error_code'] ?? null,
            'from'      => [
                'uuid' => $row['from_uuid'] ?? null,
                'name' => $row['from_name'] ?? null,
            ],
            'to' => [
                'uuid' => $row['to_uuid'] ?? null,
                'name' => $row['to_name'] ?? null,
            ],
            'startedAt'   => Clock::iso($row['started_at'] ?? null),
            'completedAt' => Clock::iso($row['completed_at'] ?? null),
            'createdAt'   => Clock::iso($row['created_at'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function companyPolicy(array $row): array
    {
        $out = ['companyId' => (int) $row['company_id'], 'policyPreset' => (string) $row['policy_preset']];

        foreach ([
            'remote_enabled'             => 'remoteEnabled',
            'allow_safe_share'           => 'allowSafeShare',
            'allow_browser_tab'          => 'allowBrowserTab',
            'allow_application_window'   => 'allowApplicationWindow',
            'allow_entire_monitor'       => 'allowEntireMonitor',
            'allow_microphone'           => 'allowMicrophone',
            'allow_system_audio'         => 'allowSystemAudio',
            'allow_text_chat'            => 'allowTextChat',
            'allow_annotation'           => 'allowAnnotation',
            'allow_file_transfer'        => 'allowFileTransfer',
            'allow_external_guest'       => 'allowExternalGuest',
            'allow_internal_sessions'    => 'allowInternalSessions',
            'allow_aicountly_support'    => 'allowAicountlySupport',
            'allow_recording'            => 'allowRecording',
            'recording_requires_consent' => 'recordingRequiresConsent',
        ] as $column => $key) {
            $out[$key] = self::bool($row[$column] ?? false);
        }

        $out['maxSessionDurationMinutes'] = (int) $row['max_session_duration_minutes'];
        $out['guestLinkExpiryMinutes']    = (int) $row['guest_link_expiry_minutes'];
        $out['updatedAt']                 = Clock::iso($row['updated_at'] ?? null);

        return $out;
    }

    public static function bool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }
}
