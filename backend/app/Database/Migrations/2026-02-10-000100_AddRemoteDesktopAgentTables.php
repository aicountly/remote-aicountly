<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Everything the Windows desktop agent needs, added to the tables that already
 * exist rather than beside them.
 *
 * `remote_devices` was created empty in `…000600_CreateRemotePlatformTables`
 * precisely so this migration would not have to invent a second device concept.
 * What it lacked was the machinery of proof-of-possession: a fingerprint to
 * index, a challenge table to spend, an enroller, a revoker, and the separate
 * record that unattended access is a deliberate, dated, attributable act rather
 * than a checkbox somebody ticked inside a session.
 *
 * Four rules are enforced by the database, not only by code:
 *
 *   * **A public key belongs to one device, platform-wide.** Two devices
 *     presenting the same key would both pass a signature check, so the
 *     fingerprint is globally unique.
 *   * **A revoked or suspended device cannot hold unattended access.** The
 *     administrator's revocation and the device's standing invitation cannot
 *     drift apart, whichever code path did the revoking.
 *   * **A challenge is spent once.** `nonce` is unique, and redemption is an
 *     `UPDATE … WHERE consumed_at IS NULL`, so two simultaneous replays of one
 *     nonce produce one success.
 *   * **An unattended session names its device.** `access_mode = 'UNATTENDED'`
 *     without a `device_id` is not a state the schema will store.
 */
final class AddRemoteDesktopAgentTables extends Migration
{
    public function up(): void
    {
        // --- Company policy: the four desktop switches ----------------------
        // Every one defaults FALSE. Remote control, unattended access,
        // clipboard synchronisation and reboot are the capabilities that turn
        // assistance into administration, so an organisation opts in to each
        // one on purpose or does not have it.
        $this->db->query(<<<'SQL'
            ALTER TABLE remote_company_policies
                ADD COLUMN allow_remote_control    BOOLEAN NOT NULL DEFAULT FALSE,
                ADD COLUMN allow_unattended_access BOOLEAN NOT NULL DEFAULT FALSE,
                ADD COLUMN allow_clipboard_sync    BOOLEAN NOT NULL DEFAULT FALSE,
                ADD COLUMN allow_device_reboot     BOOLEAN NOT NULL DEFAULT FALSE
            SQL);

        // Unattended access without remote control would be a device that can
        // be connected to but not used; reboot without control is a machine
        // anyone entitled could power-cycle with no session to justify it.
        // Both are refused by the database rather than only by the admin UI.
        $this->db->query(<<<'SQL'
            ALTER TABLE remote_company_policies
                ADD CONSTRAINT remote_company_policies_desktop_chk
                    CHECK (
                        remote_enabled
                        OR NOT (allow_remote_control OR allow_unattended_access
                                OR allow_clipboard_sync OR allow_device_reboot)
                    ),
                ADD CONSTRAINT remote_company_policies_unattended_chk
                    CHECK (allow_remote_control OR NOT allow_unattended_access),
                ADD CONSTRAINT remote_company_policies_reboot_chk
                    CHECK (allow_remote_control OR NOT allow_device_reboot)
            SQL);

        // --- Devices --------------------------------------------------------
        $this->db->query(<<<'SQL'
            ALTER TABLE remote_devices
                ADD COLUMN enrolled_by_user_id           BIGINT       NULL REFERENCES remote_identities (id) ON DELETE SET NULL,
                ADD COLUMN key_algorithm                 VARCHAR(16)  NOT NULL DEFAULT 'ED25519',
                -- SHA-256 of the raw 32-byte public key, hex. Indexed instead
                -- of the key itself so the uniqueness guard is a fixed width.
                ADD COLUMN public_key_fingerprint        CHAR(64)     NULL,
                ADD COLUMN hostname                      VARCHAR(160) NULL,
                ADD COLUMN os_version                    VARCHAR(60)  NULL,
                ADD COLUMN architecture                  VARCHAR(16)  NULL,
                ADD COLUMN enrolment_source              VARCHAR(24)  NOT NULL DEFAULT 'DESKTOP_AGENT',
                ADD COLUMN last_ip                       INET         NULL,
                ADD COLUMN last_authenticated_at         TIMESTAMPTZ  NULL,
                ADD COLUMN presence_state                VARCHAR(16)  NOT NULL DEFAULT 'OFFLINE',
                ADD COLUMN revoked_at                    TIMESTAMPTZ  NULL,
                ADD COLUMN revoked_by_user_id            BIGINT       NULL,
                ADD COLUMN unattended_enabled_at         TIMESTAMPTZ  NULL,
                ADD COLUMN unattended_enabled_by_user_id BIGINT       NULL,
                ADD COLUMN unattended_last_used_at       TIMESTAMPTZ  NULL
            SQL);

        $this->db->query(<<<'SQL'
            ALTER TABLE remote_devices
                ADD CONSTRAINT remote_devices_algorithm_chk
                    CHECK (key_algorithm IN ('ED25519')),
                ADD CONSTRAINT remote_devices_presence_chk
                    CHECK (presence_state IN ('OFFLINE', 'ONLINE')),
                -- An ACTIVE device has proved it holds a key. A device without
                -- one cannot be ACTIVE, so nothing can authenticate as it.
                ADD CONSTRAINT remote_devices_active_needs_key_chk
                    CHECK (status <> 'ACTIVE' OR public_key_fingerprint IS NOT NULL),
                -- Revoking a device revokes its unattended access in the same
                -- statement or the write is refused. The two cannot drift.
                ADD CONSTRAINT remote_devices_unattended_status_chk
                    CHECK (NOT unattended_access_enabled OR status = 'ACTIVE'),
                -- A device belongs to a tenant. A device with no company could
                -- not be governed by any company's policy.
                ADD CONSTRAINT remote_devices_company_required_chk
                    CHECK (company_id IS NOT NULL)
            SQL);

        $this->db->query('CREATE UNIQUE INDEX remote_devices_fingerprint_uniq ON remote_devices (public_key_fingerprint) WHERE public_key_fingerprint IS NOT NULL');
        $this->db->query('CREATE INDEX remote_devices_company_status_idx ON remote_devices (company_id, status)');
        $this->db->query('CREATE INDEX remote_devices_user_idx ON remote_devices (user_id, created_at DESC)');
        $this->db->query("CREATE INDEX remote_devices_unattended_idx ON remote_devices (company_id) WHERE unattended_access_enabled");

        // --- Device authentication challenges -------------------------------
        // A challenge is a single-use nonce with an expiry. The device signs a
        // canonical representation of it; the API verifies against the enrolled
        // public key and spends the row. Nothing here is a bearer credential:
        // the nonce is worthless without the private key, and worthless twice.
        $this->db->query(<<<'SQL'
            CREATE TABLE remote_device_challenges (
                id          BIGSERIAL   PRIMARY KEY,
                device_id   BIGINT      NOT NULL REFERENCES remote_devices (id) ON DELETE CASCADE,
                nonce       CHAR(64)    NOT NULL UNIQUE,
                issued_ip   INET        NULL,
                consumed_at TIMESTAMPTZ NULL,
                expires_at  TIMESTAMPTZ NOT NULL,
                created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL);
        $this->db->query('CREATE INDEX remote_device_challenges_device_idx ON remote_device_challenges (device_id, created_at DESC)');
        // Drives the sweep: a consumed or expired nonce is only interesting
        // until the moment it could no longer have been replayed anyway.
        $this->db->query('CREATE INDEX remote_device_challenges_expiry_idx ON remote_device_challenges (expires_at)');

        // --- Participants: which device, and the state of control -----------
        $this->db->query(<<<'SQL'
            ALTER TABLE remote_participants
                ADD COLUMN device_id                   BIGINT      NULL REFERENCES remote_devices (id) ON DELETE SET NULL,
                -- Control state belongs to the participant who wants to
                -- control, not to the session: two viewers can be in different
                -- states at the same moment, and only one of them controlling.
                ADD COLUMN control_state               VARCHAR(16) NOT NULL DEFAULT 'NONE',
                ADD COLUMN control_requested_at        TIMESTAMPTZ NULL,
                ADD COLUMN control_granted_at          TIMESTAMPTZ NULL,
                ADD COLUMN control_revoked_at          TIMESTAMPTZ NULL,
                ADD COLUMN control_granted_by_user_id  BIGINT      NULL,
                ADD COLUMN clipboard_enabled           BOOLEAN     NOT NULL DEFAULT FALSE
            SQL);

        $this->db->query(<<<'SQL'
            ALTER TABLE remote_participants
                ADD CONSTRAINT remote_participants_control_chk
                    CHECK (control_state IN ('NONE', 'REQUESTED', 'GRANTED', 'DENIED', 'REVOKED')),
                -- Clipboard synchronisation is not a side effect of control:
                -- it is switched on separately, and only while control holds.
                ADD CONSTRAINT remote_participants_clipboard_chk
                    CHECK (NOT clipboard_enabled OR control_state = 'GRANTED')
            SQL);
        $this->db->query("CREATE INDEX remote_participants_control_idx ON remote_participants (session_id) WHERE control_state IN ('REQUESTED', 'GRANTED')");

        // An unattended session has two participants for one person: the
        // machine (a DESKTOP_AGENT participant carrying its device) and the
        // colleague who connected to it — who is often the machine's own owner
        // connecting from a browser. The original index would call that a
        // duplicate join and refuse it.
        //
        // Narrowing it to human participants keeps exactly what it was for:
        // a repeated join request from one person is still idempotent, because
        // `ParticipantService::findByUser()` looks only at rows with no device.
        $this->db->query('DROP INDEX IF EXISTS remote_participants_session_user_uniq');
        $this->db->query('CREATE UNIQUE INDEX remote_participants_session_user_uniq ON remote_participants (session_id, user_id) WHERE user_id IS NOT NULL AND device_id IS NULL');
        // …and one machine appears at most once in a session.
        $this->db->query('CREATE UNIQUE INDEX remote_participants_session_device_uniq ON remote_participants (session_id, device_id) WHERE device_id IS NOT NULL');

        // --- A device is an actor in its own right --------------------------
        // Revoking control from the tray, or reporting a session ended because
        // the machine is shutting down, is not the enrolling user acting and it
        // is not the system acting. Filing it as either would make the audit
        // trail say something untrue about who did it.
        foreach (['remote_session_events' => 'remote_session_events_actor_chk', 'remote_audit_logs' => 'remote_audit_logs_actor_chk'] as $table => $constraint) {
            $this->db->query("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
            $this->db->query("ALTER TABLE {$table} ADD CONSTRAINT {$constraint} CHECK (actor_type IN ('USER', 'GUEST', 'SUPPORT', 'SYSTEM', 'DEVICE'))");
        }

        // --- Sessions: attended or unattended, and the device it reached ----
        $this->db->query(<<<'SQL'
            ALTER TABLE remote_sessions
                ADD COLUMN device_id   BIGINT      NULL REFERENCES remote_devices (id) ON DELETE SET NULL,
                ADD COLUMN access_mode VARCHAR(16) NOT NULL DEFAULT 'ATTENDED'
            SQL);

        $this->db->query(<<<'SQL'
            ALTER TABLE remote_sessions
                ADD CONSTRAINT remote_sessions_access_mode_chk
                    CHECK (access_mode IN ('ATTENDED', 'UNATTENDED')),
                -- An unattended session with no device is a session nobody can
                -- point at afterwards and say which machine was reached.
                ADD CONSTRAINT remote_sessions_unattended_device_chk
                    CHECK (access_mode <> 'UNATTENDED' OR device_id IS NOT NULL)
            SQL);
        $this->db->query('CREATE INDEX remote_sessions_device_idx ON remote_sessions (device_id, created_at DESC) WHERE device_id IS NOT NULL');
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX IF EXISTS remote_sessions_device_idx');
        $this->db->query('ALTER TABLE remote_sessions DROP CONSTRAINT IF EXISTS remote_sessions_unattended_device_chk');
        $this->db->query('ALTER TABLE remote_sessions DROP CONSTRAINT IF EXISTS remote_sessions_access_mode_chk');
        $this->db->query('ALTER TABLE remote_sessions DROP COLUMN IF EXISTS access_mode, DROP COLUMN IF EXISTS device_id');

        foreach (['remote_session_events' => 'remote_session_events_actor_chk', 'remote_audit_logs' => 'remote_audit_logs_actor_chk'] as $table => $constraint) {
            $this->db->query("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
            $this->db->query("ALTER TABLE {$table} ADD CONSTRAINT {$constraint} CHECK (actor_type IN ('USER', 'GUEST', 'SUPPORT', 'SYSTEM'))");
        }

        $this->db->query('DROP INDEX IF EXISTS remote_participants_session_device_uniq');
        $this->db->query('DROP INDEX IF EXISTS remote_participants_session_user_uniq');
        $this->db->query('CREATE UNIQUE INDEX remote_participants_session_user_uniq ON remote_participants (session_id, user_id) WHERE user_id IS NOT NULL');

        $this->db->query('DROP INDEX IF EXISTS remote_participants_control_idx');
        $this->db->query('ALTER TABLE remote_participants DROP CONSTRAINT IF EXISTS remote_participants_clipboard_chk');
        $this->db->query('ALTER TABLE remote_participants DROP CONSTRAINT IF EXISTS remote_participants_control_chk');
        $this->db->query(<<<'SQL'
            ALTER TABLE remote_participants
                DROP COLUMN IF EXISTS clipboard_enabled,
                DROP COLUMN IF EXISTS control_granted_by_user_id,
                DROP COLUMN IF EXISTS control_revoked_at,
                DROP COLUMN IF EXISTS control_granted_at,
                DROP COLUMN IF EXISTS control_requested_at,
                DROP COLUMN IF EXISTS control_state,
                DROP COLUMN IF EXISTS device_id
            SQL);

        $this->db->query('DROP TABLE IF EXISTS remote_device_challenges');

        $this->db->query('DROP INDEX IF EXISTS remote_devices_unattended_idx');
        $this->db->query('DROP INDEX IF EXISTS remote_devices_user_idx');
        $this->db->query('DROP INDEX IF EXISTS remote_devices_company_status_idx');
        $this->db->query('DROP INDEX IF EXISTS remote_devices_fingerprint_uniq');
        foreach ([
            'remote_devices_company_required_chk',
            'remote_devices_unattended_status_chk',
            'remote_devices_active_needs_key_chk',
            'remote_devices_presence_chk',
            'remote_devices_algorithm_chk',
        ] as $constraint) {
            $this->db->query("ALTER TABLE remote_devices DROP CONSTRAINT IF EXISTS {$constraint}");
        }
        $this->db->query(<<<'SQL'
            ALTER TABLE remote_devices
                DROP COLUMN IF EXISTS unattended_last_used_at,
                DROP COLUMN IF EXISTS unattended_enabled_by_user_id,
                DROP COLUMN IF EXISTS unattended_enabled_at,
                DROP COLUMN IF EXISTS revoked_by_user_id,
                DROP COLUMN IF EXISTS revoked_at,
                DROP COLUMN IF EXISTS presence_state,
                DROP COLUMN IF EXISTS last_authenticated_at,
                DROP COLUMN IF EXISTS last_ip,
                DROP COLUMN IF EXISTS enrolment_source,
                DROP COLUMN IF EXISTS architecture,
                DROP COLUMN IF EXISTS os_version,
                DROP COLUMN IF EXISTS hostname,
                DROP COLUMN IF EXISTS public_key_fingerprint,
                DROP COLUMN IF EXISTS key_algorithm,
                DROP COLUMN IF EXISTS enrolled_by_user_id
            SQL);

        foreach ([
            'remote_company_policies_reboot_chk',
            'remote_company_policies_unattended_chk',
            'remote_company_policies_desktop_chk',
        ] as $constraint) {
            $this->db->query("ALTER TABLE remote_company_policies DROP CONSTRAINT IF EXISTS {$constraint}");
        }
        $this->db->query(<<<'SQL'
            ALTER TABLE remote_company_policies
                DROP COLUMN IF EXISTS allow_device_reboot,
                DROP COLUMN IF EXISTS allow_clipboard_sync,
                DROP COLUMN IF EXISTS allow_unattended_access,
                DROP COLUMN IF EXISTS allow_remote_control
            SQL);
    }
}
