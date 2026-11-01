<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Remote's own two-step verification (TOTP, RFC 6238), its recovery codes, and
 * the record of which browsers have passed it (docs/SECURITY.md).
 *
 * Remote signs people in through the AICOUNTLY portal and cannot see — let
 * alone require — whatever second factor the portal asks for. Once a person can
 * reach their own computers with nobody at them, a stolen portal session is
 * worth a great deal more than a list of screen-sharing sessions, so Remote
 * keeps a second step of its own. Three tables:
 *
 *   * `remote_user_two_step` — one row per person who has started or finished
 *     turning it on. The authenticator secret is **sealed** (XChaCha20-Poly1305,
 *     a key the database never sees — see App\Domain\TwoStep\SecretCipher) and
 *     bound to this row's `user_id`, so a database reader learns nothing and a
 *     database writer cannot copy their own secret onto somebody else's row.
 *     `confirmed_at` is null while an enrolment is pending; `last_used_step` is
 *     the RFC 6238 time step of the last code accepted, which is what refuses a
 *     code a second time; `failed_attempts` / `locked_until` are the lockout.
 *   * `remote_user_recovery_codes` — ten one-time codes, stored only as
 *     `password_hash()` output, so they survive a lost phone *and* a lost
 *     encryption key: they are the way back in either way.
 *   * `remote_two_step_verifications` — a browser that passed the second step.
 *     It is identified by the SHA-256 of a random verification token Remote
 *     issued it, never the token. Not by the portal `ses_key`: that key lives
 *     about fifteen minutes and only in memory (docs/auth/AICOUNTLY_AUTH_WORKFLOW.md),
 *     so a verification hung on it would be lost on every reload and every
 *     re-mint, and the person asked for a code every quarter of an hour.
 *
 * `user_id` is `remote_identities.id`, a BIGINT, as on every other table that
 * names a person (`remote_devices.user_id`). The children hang off
 * `remote_user_two_step` rather than off the identity, so switching two-step
 * verification off — deleting that one row — takes every recovery code and
 * every verified browser with it, and nothing can outlive it by accident.
 *
 * None of the three has a `company_id`: two-step verification is a person's,
 * not an organisation's, so a company purge never reaches it; the account purge
 * (App\Libraries\CompanyLifecycle::purgeAccount) removes it with the person.
 */
class AddRemoteTwoStepVerification extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE TABLE remote_user_two_step (
                user_id           BIGINT       PRIMARY KEY REFERENCES remote_identities (id) ON DELETE CASCADE,
                secret_ciphertext TEXT         NOT NULL,
                key_id            VARCHAR(16)  NOT NULL,
                confirmed_at      TIMESTAMPTZ  NULL,
                last_used_step    BIGINT       NULL,
                failed_attempts   INTEGER      NOT NULL DEFAULT 0,
                locked_until      TIMESTAMPTZ  NULL,
                created_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                CONSTRAINT remote_user_two_step_failed_attempts_chk CHECK (failed_attempts >= 0)
            )
            SQL);

        $this->db->query(<<<'SQL'
            CREATE TABLE remote_user_recovery_codes (
                id         BIGSERIAL    PRIMARY KEY,
                user_id    BIGINT       NOT NULL REFERENCES remote_user_two_step (user_id) ON DELETE CASCADE,
                code_hash  VARCHAR(255) NOT NULL,
                used_at    TIMESTAMPTZ  NULL,
                created_at TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            )
            SQL);
        // The only lookup: this person's codes that are still good.
        $this->db->query('CREATE INDEX remote_user_recovery_codes_unused_idx ON remote_user_recovery_codes (user_id) WHERE used_at IS NULL');

        $this->db->query(<<<'SQL'
            CREATE TABLE remote_two_step_verifications (
                id          BIGSERIAL    PRIMARY KEY,
                user_id     BIGINT       NOT NULL REFERENCES remote_user_two_step (user_id) ON DELETE CASCADE,
                token_hash  CHAR(64)     NOT NULL UNIQUE,
                method      VARCHAR(16)  NOT NULL,
                verified_at TIMESTAMPTZ  NOT NULL,
                expires_at  TIMESTAMPTZ  NOT NULL,
                created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                CONSTRAINT remote_two_step_verifications_method_chk CHECK (method IN ('TOTP', 'RECOVERY_CODE')),
                CONSTRAINT remote_two_step_verifications_window_chk CHECK (expires_at > verified_at)
            )
            SQL);
        $this->db->query('CREATE INDEX remote_two_step_verifications_user_idx ON remote_two_step_verifications (user_id, expires_at)');
    }

    public function down(): void
    {
        // Children first. Dropping these switches two-step verification off for
        // everybody, which is what rolling the feature back means.
        $this->db->query('DROP TABLE IF EXISTS remote_two_step_verifications');
        $this->db->query('DROP TABLE IF EXISTS remote_user_recovery_codes');
        $this->db->query('DROP TABLE IF EXISTS remote_user_two_step');
    }
}
