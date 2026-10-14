<?php

declare(strict_types=1);

/**
 * Remote's reviewed company-lifecycle purge manifest (lib/company-lifecycle, kit README §2 step 4).
 *
 * Drafted by lib/company-lifecycle/bin/discover-manifest.php from a freshly migrated database (every
 * app/Database/Migrations file to 2026-10-14) and reviewed line by line; decisions in
 * docs/COMPANY_LIFECYCLE.md.
 *
 *   company_tables  EVERYTHING company-scoped (key company_id): the company's sessions with their
 *                   participants, invitations, messages, file-transfer records, recording records,
 *                   events and feedback; support requests; devices with their challenges; desktop
 *                   sign-in codes; launch-context tokens; policy, entitlements, role and user
 *                   permissions, memberships, the directory projection; and Remote's own audit log (no
 *                   statute keeps it). Archived first, children before parents.
 *   company_retain  none.
 *   user_tables     none in the manifest: the person is a remote_identities row (platform_uuid) that the
 *                   company's records point at with ON DELETE NO ACTION, so it cannot be deleted by the
 *                   engine. The account purge is the account hook (App\Libraries\CompanyLifecycle::
 *                   purgeAccount): the person's PERSONAL sessions (no company), personal devices and
 *                   desktop sign-in codes are deleted and the identity projection is
 *                   anonymised — docs/COMPANY_LIFECYCLE.md.
 *   ignore          remote_identities (see above) and the migration bookkeeping.
 *
 * The `uuid` columns the draft proposed as personal keys are each row's own public id, not a person.
 */

return [
    'app_key' => 'remote',
    'schema'  => 'public',
    'strict'  => true,

    'company_tables' => [
        // A session's children reach the company only through it (session_id, ON DELETE CASCADE).
        ['table' => 'remote_file_transfers', 'via' => ['column' => 'session_id', 'parent' => 'remote_sessions', 'parent_column' => 'id']],
        ['table' => 'remote_messages', 'via' => ['column' => 'session_id', 'parent' => 'remote_sessions', 'parent_column' => 'id']],
        // Recording records: Remote stores no recording object (nothing writes storage_key), so only rows go.
        ['table' => 'remote_recordings', 'via' => ['column' => 'session_id', 'parent' => 'remote_sessions', 'parent_column' => 'id']],
        ['table' => 'remote_session_events', 'via' => ['column' => 'session_id', 'parent' => 'remote_sessions', 'parent_column' => 'id']],
        ['table' => 'remote_session_feedback', 'via' => ['column' => 'session_id', 'parent' => 'remote_sessions', 'parent_column' => 'id']],
        // Participants before invitations: participants.invitation_id points at an invitation (the reverse
        // constraint was dropped to break the cycle — migration 2026-10-14-000200).
        ['table' => 'remote_participants', 'via' => ['column' => 'session_id', 'parent' => 'remote_sessions', 'parent_column' => 'id']],
        ['table' => 'remote_invitations', 'via' => ['column' => 'session_id', 'parent' => 'remote_sessions', 'parent_column' => 'id']],
        ['table' => 'remote_support_requests', 'key' => 'company_id'],
        ['table' => 'remote_sessions', 'key' => 'company_id'],
        ['table' => 'remote_device_challenges', 'via' => ['column' => 'device_id', 'parent' => 'remote_devices', 'parent_column' => 'id']],
        ['table' => 'remote_devices', 'key' => 'company_id'],
        ['table' => 'remote_desktop_signin_codes', 'key' => 'company_id'],
        ['table' => 'remote_context_tokens', 'key' => 'company_id'],
        ['table' => 'remote_company_policies', 'key' => 'company_id'],
        // The company's plan limits as Remote applies them; the subscription itself (billing) is in Manage.
        ['table' => 'remote_entitlements', 'key' => 'company_id'],
        ['table' => 'remote_role_permissions', 'key' => 'company_id'],
        ['table' => 'remote_user_permissions', 'key' => 'company_id'],
        ['table' => 'remote_user_company_access', 'key' => 'company_id'],
        ['table' => 'remote_company_directory', 'key' => 'company_id'],
        // Remote's own audit log (who started, joined or controlled a session): no statute keeps it.
        ['table' => 'remote_audit_logs', 'key' => 'company_id'],
    ],

    'company_retain' => [],

    'user_tables' => [],

    'user_retain' => [],

    'ignore' => [
        ['table' => 'remote_identities', 'reason' => 'the person\'s projection (platform_uuid): referenced by company records ON DELETE NO ACTION — anonymised by the account hook, never deleted by the engine'],
        ['table' => 'migrations', 'reason' => 'CodeIgniter migration bookkeeping'],
    ],
];
