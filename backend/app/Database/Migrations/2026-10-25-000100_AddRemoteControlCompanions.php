<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Control companions (docs/desktop/CONTROL_COMPANION.md).
 *
 * A person shares their screen from Chrome or Edge; their own AICOUNTLY Remote desktop app joins the same
 * session as an input-only CONTROL_COMPANION participant, linked to their browser participant. The picture
 * still comes from the browser; the companion only applies the input a viewer was granted.
 *
 *   remote_control_companions   one row per link request: which session, which of the sharer's own
 *                               devices, which browser participant it serves, and where the link stands
 *                               (PENDING → JOINED → ACTIVE, or DECLINED / EXPIRED / ENDED).
 *
 * The rules the database enforces rather than only the code:
 *
 *   * **One live link per session and per device.** Two companions for one screen, or one machine
 *     serving two sessions, are not states the schema will store (partial unique indexes over the live
 *     statuses).
 *   * **An ACTIVE link knows its monitor.** Input is mapped onto a monitor; a link without one cannot be
 *     the thing input flows through.
 *   * **A companion participant is a machine linked to a person.** role CONTROL_COMPANION ⇔ it names the
 *     participant it serves, and it always carries its device. Never a host.
 *   * The browser's capture size is a plausible size, or nothing.
 */
final class AddRemoteControlCompanions extends Migration
{
    public function up(): void
    {
        // --- Participants: the companion role and its link -----------------
        $this->db->query('ALTER TABLE remote_participants DROP CONSTRAINT IF EXISTS remote_participants_role_chk');
        $this->db->query(<<<'SQL'
            ALTER TABLE remote_participants
                ADD CONSTRAINT remote_participants_role_chk
                    CHECK (participant_role IN ('SHARER', 'VIEWER', 'SUPPORT_TECHNICIAN', 'OBSERVER', 'GUEST', 'CONTROL_COMPANION')),
                ADD COLUMN companion_of_participant_id BIGINT NULL REFERENCES remote_participants (id) ON DELETE CASCADE
            SQL);
        $this->db->query(<<<'SQL'
            ALTER TABLE remote_participants
                ADD CONSTRAINT remote_participants_companion_link_chk
                    CHECK ((participant_role = 'CONTROL_COMPANION') = (companion_of_participant_id IS NOT NULL)),
                ADD CONSTRAINT remote_participants_companion_device_chk
                    CHECK (participant_role <> 'CONTROL_COMPANION'
                           OR (device_id IS NOT NULL AND client_type = 'DESKTOP_AGENT' AND NOT is_host))
            SQL);

        // --- Sessions: the captured surface's size ---------------------------
        // track.getSettings() on the shared video track, as the browser reported it with share-started. The
        // companion matches it against its own monitors; it decides nothing on its own.
        $this->db->query(<<<'SQL'
            ALTER TABLE remote_sessions
                ADD COLUMN actual_surface_width  INTEGER NULL,
                ADD COLUMN actual_surface_height INTEGER NULL
            SQL);
        $this->db->query(<<<'SQL'
            ALTER TABLE remote_sessions
                ADD CONSTRAINT remote_sessions_surface_size_chk
                    CHECK ((actual_surface_width IS NULL OR actual_surface_width BETWEEN 1 AND 16384)
                           AND (actual_surface_height IS NULL OR actual_surface_height BETWEEN 1 AND 16384))
            SQL);

        // --- The links --------------------------------------------------------
        $this->db->query(<<<'SQL'
            CREATE TABLE remote_control_companions (
                id                       BIGSERIAL    PRIMARY KEY,
                uuid                     UUID         NOT NULL UNIQUE,
                session_id               BIGINT       NOT NULL REFERENCES remote_sessions (id) ON DELETE CASCADE,
                device_id                BIGINT       NOT NULL REFERENCES remote_devices (id) ON DELETE CASCADE,
                sharer_participant_id    BIGINT       NOT NULL REFERENCES remote_participants (id) ON DELETE CASCADE,
                companion_participant_id BIGINT       NULL REFERENCES remote_participants (id) ON DELETE SET NULL,
                requested_by_user_id     BIGINT       NOT NULL REFERENCES remote_identities (id),

                status                   VARCHAR(16)  NOT NULL DEFAULT 'PENDING',
                -- The monitor input is mapped onto, in the companion's injection space (points on macOS,
                -- physical pixels on Windows): {id, name, x, y, width, height, scale}.
                monitor                  JSONB        NULL,
                monitor_match            VARCHAR(16)  NULL,
                end_reason               VARCHAR(40)  NULL,

                expires_at               TIMESTAMPTZ  NOT NULL,
                created_at               TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                joined_at                TIMESTAMPTZ  NULL,
                ended_at                 TIMESTAMPTZ  NULL,
                updated_at               TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

                CONSTRAINT remote_control_companions_status_chk
                    CHECK (status IN ('PENDING', 'JOINED', 'ACTIVE', 'DECLINED', 'ENDED', 'EXPIRED')),
                CONSTRAINT remote_control_companions_match_chk
                    CHECK (monitor_match IS NULL OR monitor_match IN ('AUTO_SINGLE', 'AUTO_ASPECT', 'USER')),
                -- Input is only ever mapped onto a monitor somebody (or the single-monitor rule) chose.
                CONSTRAINT remote_control_companions_active_monitor_chk
                    CHECK (status <> 'ACTIVE' OR (monitor IS NOT NULL AND monitor_match IS NOT NULL)),
                -- Joined means there is a participant that joined.
                CONSTRAINT remote_control_companions_joined_participant_chk
                    CHECK (status NOT IN ('JOINED', 'ACTIVE') OR (companion_participant_id IS NOT NULL AND joined_at IS NOT NULL)),
                -- A finished link says when it finished.
                CONSTRAINT remote_control_companions_ended_chk
                    CHECK ((status IN ('DECLINED', 'ENDED', 'EXPIRED')) = (ended_at IS NOT NULL))
            )
            SQL);

        $live = "status IN ('PENDING', 'JOINED', 'ACTIVE')";
        $this->db->query("CREATE UNIQUE INDEX remote_control_companions_session_live_uniq ON remote_control_companions (session_id) WHERE {$live}");
        $this->db->query("CREATE UNIQUE INDEX remote_control_companions_device_live_uniq ON remote_control_companions (device_id) WHERE {$live}");
        $this->db->query('CREATE INDEX remote_control_companions_session_idx ON remote_control_companions (session_id, created_at DESC)');
        $this->db->query('CREATE INDEX remote_control_companions_device_idx ON remote_control_companions (device_id, created_at DESC)');
        $this->db->query('CREATE INDEX remote_control_companions_sharer_idx ON remote_control_companions (sharer_participant_id)');
        $this->db->query('CREATE INDEX remote_control_companions_companion_idx ON remote_control_companions (companion_participant_id) WHERE companion_participant_id IS NOT NULL');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS remote_control_companions');

        $this->db->query('ALTER TABLE remote_sessions DROP CONSTRAINT IF EXISTS remote_sessions_surface_size_chk');
        $this->db->query('ALTER TABLE remote_sessions DROP COLUMN IF EXISTS actual_surface_height, DROP COLUMN IF EXISTS actual_surface_width');

        $this->db->query("DELETE FROM remote_participants WHERE participant_role = 'CONTROL_COMPANION'");
        $this->db->query('ALTER TABLE remote_participants DROP CONSTRAINT IF EXISTS remote_participants_companion_device_chk');
        $this->db->query('ALTER TABLE remote_participants DROP CONSTRAINT IF EXISTS remote_participants_companion_link_chk');
        $this->db->query('ALTER TABLE remote_participants DROP COLUMN IF EXISTS companion_of_participant_id');
        $this->db->query('ALTER TABLE remote_participants DROP CONSTRAINT IF EXISTS remote_participants_role_chk');
        $this->db->query(<<<'SQL'
            ALTER TABLE remote_participants
                ADD CONSTRAINT remote_participants_role_chk
                    CHECK (participant_role IN ('SHARER', 'VIEWER', 'SUPPORT_TECHNICIAN', 'OBSERVER', 'GUEST'))
            SQL);
    }
}
