<?php

declare(strict_types=1);

namespace App\Domain\TwoStep;

use App\Domain\Audit\AuditService;
use App\Domain\Audit\EventType;
use App\Domain\Auth\RemoteIdentity;
use App\Domain\Support\ApiException;
use App\Domain\Support\Clock;
use App\Domain\Support\RequestContext;
use CodeIgniter\Database\BaseConnection;
use Config\Remote as RemoteConfig;
use Throwable;

/**
 * Remote's own two-step verification: an authenticator app (TOTP), ten
 * one-time recovery codes, and the record of which browsers have passed it.
 *
 * **Why Remote has one at all.** People sign in through the AICOUNTLY portal,
 * which is another product: Remote cannot see whether the portal asked for a
 * second factor, and cannot ask it to. Since Remote can reach a person's own
 * computers with nobody at them, somebody holding a stolen portal session could
 * otherwise sit down at those computers. This is the step that session alone
 * does not pass.
 *
 * **What a "verified browser" is.** Passing the step mints a random
 * verification token, returned once and sent back by the web on every call in
 * the `X-Remote-Two-Step` header; only its SHA-256 is stored. It is deliberately
 * not hung on the portal `ses_key` — that key lasts about fifteen minutes and
 * lives only in memory, so it changes on every reload and every re-mint, and a
 * verification tied to it would ask for a code every quarter of an hour. The
 * token is bound to the person (`user_id`): presented with anybody else's
 * session it verifies nothing, and without a valid portal session it is never
 * even looked at.
 *
 * **What it refuses.**
 *   * a wrong code, counted: {@see RemoteConfig::$twoStepMaxFailures} in a row
 *     locks verification — authenticator and recovery codes alike — for
 *     {@see RemoteConfig::$twoStepLockoutSeconds}, per person, wherever the
 *     guesses come from;
 *   * a code that was already accepted, or one older than the last one
 *     accepted (`last_used_step`), by a guarded update, so two requests racing
 *     with the same code cannot both pass;
 *   * a recovery code that was used, by the same kind of guarded update.
 *
 * Every change is audited: starting, enabling, each verification and each
 * failure, a lockout, a recovery code spent, new codes issued, switching off.
 * No code, secret or token ever reaches the trail.
 */
class TwoStepService
{
    /** The header the web sends its verification token in. */
    public const HEADER = 'X-Remote-Two-Step';

    public const METHOD_TOTP     = 'TOTP';
    public const METHOD_RECOVERY = 'RECOVERY_CODE';

    /** What a refusal is about, so its sentence says what to do next. */
    public const PURPOSE_UNATTENDED_ENABLE  = 'UNATTENDED_ENABLE';
    public const PURPOSE_UNATTENDED_CONNECT = 'UNATTENDED_CONNECT';
    public const PURPOSE_RECOVERY_CODES     = 'RECOVERY_CODES';

    /** Where the web shows the setting. Sent in a refusal so the page can link to it. */
    public const SETTINGS_PATH = '/settings#two-step';

    public const RECOVERY_CODE_COUNT = 10;

    /** Crockford-like: no 0/O, no 1/I, so a code read off paper is read right. 5 bits a character. */
    private const RECOVERY_ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    /** Ten characters: 50 bits, each code single-use and behind the lockout. */
    private const RECOVERY_LENGTH = 10;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly AuditService $audit,
        private readonly RemoteConfig $config,
        private readonly RequestContext $context,
        private readonly SecretCipher $cipher,
    ) {
    }

    // ------------------------------------------------------------- standing

    /**
     * Where this person, with this verification token, stands — one indexed
     * query, made on every signed-in request.
     *
     * Before the migration has run (the seconds between a deploy's upload and
     * its migration step, or a deploy run with `migrate = skip`) the table does
     * not exist, and nobody can have two-step verification on: that one error
     * is answered "off" rather than with a 500 for every signed-in call. Any
     * other database failure is not guessed at.
     */
    public function standing(RemoteIdentity $identity, ?string $token): TwoStepStanding
    {
        $sql = <<<'SQL'
            SELECT t.confirmed_at, v.verified_at, v.expires_at
              FROM remote_user_two_step t
              LEFT JOIN remote_two_step_verifications v
                     ON v.user_id = t.user_id
                    AND v.token_hash = ?
                    AND v.expires_at > ?
             WHERE t.user_id = ?
               AND t.confirmed_at IS NOT NULL
            SQL;

        $tokenHash = $token !== null && $token !== '' ? hash('sha256', $token) : '';

        try {
            $result = $this->db->query($sql, [$tokenHash, Clock::now(), $identity->id]);
        } catch (Throwable $e) {
            if ($this->isMissingTable($e->getMessage())) {
                return TwoStepStanding::off();
            }

            throw $e;
        }

        if ($result === false) {
            // DBDebug off (production): a failed statement returns false instead of throwing.
            $error = $this->db->error();
            if ($this->isMissingTable((string) ($error['message'] ?? ''))) {
                return TwoStepStanding::off();
            }

            throw ApiException::unavailable(
                'TWO_STEP_UNAVAILABLE',
                'Your two-step verification could not be checked just now. Please try again in a moment.',
            );
        }

        $row = $result->getRowArray();
        if ($row === null) {
            return TwoStepStanding::off();
        }

        return new TwoStepStanding(
            true,
            $row['verified_at'] !== null ? (int) strtotime((string) $row['verified_at']) : null,
            $row['expires_at'] !== null ? (int) strtotime((string) $row['expires_at']) : null,
        );
    }

    /** The standing of the browser making this request. */
    public function currentStanding(RemoteIdentity $identity): TwoStepStanding
    {
        return $this->standing($identity, $this->context->twoStepToken());
    }

    /**
     * Refuse unless this browser has passed two-step verification — within
     * `$withinSeconds` when given (step-up), or at all when null.
     *
     * Two refusals, each saying what to do: `TWO_STEP_SETUP_REQUIRED` when the
     * person has not turned it on (the web links to the setting), and
     * `TWO_STEP_REQUIRED` when they have but this browser has not passed it
     * recently enough (the web asks for a code and tries again).
     */
    public function requireVerified(RemoteIdentity $identity, ?int $withinSeconds, string $purpose): void
    {
        $standing = $this->currentStanding($identity);

        if (! $standing->enabled) {
            throw ApiException::forbidden('TWO_STEP_SETUP_REQUIRED', $this->setupMessage($purpose), [
                'purpose'   => $purpose,
                'setupPath' => self::SETTINGS_PATH,
            ]);
        }

        $passed = $withinSeconds === null ? $standing->sessionVerified() : $standing->verifiedWithin($withinSeconds);
        if (! $passed) {
            throw ApiException::forbidden('TWO_STEP_REQUIRED', $this->stepUpMessage($purpose, $withinSeconds), [
                'reason'        => $withinSeconds === null ? 'SESSION' : 'STEP_UP',
                'purpose'       => $purpose,
                'withinSeconds' => $withinSeconds,
            ]);
        }
    }

    /**
     * `GET me/two-step`, before presentation.
     *
     * @return array{enabled: bool, pending: bool, available: bool, confirmedAt: ?string, recoveryCodesLeft: int,
     *               sessionVerified: bool, verifiedAt: ?int, verifiedUntil: ?int, lockedUntil: ?string}
     */
    public function status(RemoteIdentity $identity): array
    {
        $row      = $this->row($identity->id);
        $standing = $this->currentStanding($identity);

        return [
            'enabled'           => $standing->enabled,
            'pending'           => $row !== null && $row['confirmed_at'] === null,
            'available'         => $this->cipher->available(),
            'confirmedAt'       => $row['confirmed_at'] ?? null,
            'recoveryCodesLeft' => $standing->enabled ? $this->recoveryCodesLeft($identity->id) : 0,
            'sessionVerified'   => $standing->sessionVerified(),
            'verifiedAt'        => $standing->sessionVerified() ? $standing->verifiedAt : null,
            'verifiedUntil'     => $standing->sessionVerified() ? $standing->verifiedUntil : null,
            'lockedUntil'       => $row !== null && $this->isLocked($row) ? (string) $row['locked_until'] : null,
        ];
    }

    // ------------------------------------------------------------ enrolment

    /**
     * Start turning it on: a fresh secret, sealed and pending until a code from
     * it is confirmed. Starting again replaces a pending one — the person may
     * have scanned it into the wrong app — but never a confirmed one, which
     * only switching off (with a code) can remove.
     *
     * @return array{secret: string, otpauthUri: string}
     */
    public function enrol(RemoteIdentity $identity): array
    {
        if (! $this->cipher->available()) {
            // Never store the secret unsealed instead: there is no such fallback.
            throw ApiException::unavailable(
                'TWO_STEP_UNAVAILABLE',
                'Two-step verification cannot be turned on yet: this AICOUNTLY Remote server has no key to protect it with. Please tell AICOUNTLY support.',
            );
        }

        $existing = $this->row($identity->id);
        if ($existing !== null && $existing['confirmed_at'] !== null) {
            throw ApiException::conflict('TWO_STEP_ALREADY_ENABLED', 'Two-step verification is already on. Turn it off first to move it to a new authenticator app.');
        }

        $secret = Totp::generateSecret();
        $sealed = $this->cipher->seal($secret, $identity->id);

        // The WHERE on the update is what keeps a confirmed row from being
        // replaced by a racing request that read it as pending.
        $written = $this->db->query(<<<'SQL'
            INSERT INTO remote_user_two_step (user_id, secret_ciphertext, key_id, confirmed_at, last_used_step, failed_attempts, locked_until, created_at, updated_at)
            VALUES (?, ?, ?, NULL, NULL, 0, NULL, ?, ?)
            ON CONFLICT (user_id) DO UPDATE
                SET secret_ciphertext = EXCLUDED.secret_ciphertext,
                    key_id            = EXCLUDED.key_id,
                    last_used_step    = NULL,
                    failed_attempts   = 0,
                    locked_until      = NULL,
                    created_at        = EXCLUDED.created_at,
                    updated_at        = EXCLUDED.updated_at
                WHERE remote_user_two_step.confirmed_at IS NULL
            SQL, [$identity->id, $sealed['ciphertext'], $sealed['keyId'], Clock::now(), Clock::now()]);

        if ($written === false || $this->db->affectedRows() !== 1) {
            throw ApiException::conflict('TWO_STEP_ALREADY_ENABLED', 'Two-step verification is already on. Turn it off first to move it to a new authenticator app.');
        }

        $this->audit->recordAudit(
            EventType::TWO_STEP_ENROLMENT_STARTED,
            $identity->id,
            'USER',
            metadata: ['restarted' => $existing !== null],
            entity: $this->entity($identity),
            summary: 'Started turning on two-step verification',
        );

        $base32 = Totp::base32Encode($secret);

        return [
            'secret'     => $base32,
            'otpauthUri' => Totp::uri($this->config->twoStepIssuer, $this->accountLabel($identity), $base32),
        ];
    }

    /**
     * Finish turning it on: a code from the new authenticator proves the secret
     * reached it. Ten recovery codes are issued — shown once, here — and this
     * browser counts as verified, so the person is not asked again a second
     * later.
     *
     * @return array{recoveryCodes: list<string>, token: string, verifiedAt: int, verifiedUntil: int}
     */
    public function confirm(RemoteIdentity $identity, ?string $code): array
    {
        $code = $this->normaliseCode($code);
        $row  = $this->row($identity->id);

        if ($row === null) {
            throw ApiException::conflict('TWO_STEP_NOT_STARTED', 'Start turning on two-step verification first, then enter a code from your authenticator app.');
        }
        if ($row['confirmed_at'] !== null) {
            throw ApiException::conflict('TWO_STEP_ALREADY_ENABLED', 'Two-step verification is already on.');
        }

        $this->assertNotLocked($row);
        $this->acceptTotp($identity, $row, $code, 'CONFIRM');

        $this->db->transException(true)->transStart();

        $this->db->table('remote_user_two_step')->where('user_id', $identity->id)->update([
            'confirmed_at'    => Clock::now(),
            'failed_attempts' => 0,
            'locked_until'    => null,
            'updated_at'      => Clock::now(),
        ]);
        $codes        = $this->replaceRecoveryCodes($identity->id);
        $verification = $this->issueVerification($identity, self::METHOD_TOTP);

        $this->audit->recordMaterial(
            EventType::TWO_STEP_ENABLED,
            $identity->id,
            'USER',
            metadata: ['recoveryCodes' => count($codes)],
            entity: $this->entity($identity),
            summary: 'Turned on two-step verification',
        );

        $this->db->transComplete();

        return ['recoveryCodes' => $codes] + $verification;
    }

    // --------------------------------------------------------- verification

    /**
     * Pass the second step on this browser, with an authenticator code or a
     * recovery code. Also how a step-up is given: a fresh verification replaces
     * whatever this browser held.
     *
     * @return array{token: string, verifiedAt: int, verifiedUntil: int, method: string, recoveryCodesLeft: int}
     */
    public function verify(RemoteIdentity $identity, ?string $code, ?string $recoveryCode): array
    {
        $row    = $this->requireEnabled($identity);
        $method = $this->checkSecondStep($identity, $row, $code, $recoveryCode, 'VERIFY');

        $this->db->transException(true)->transStart();
        $verification = $this->issueVerification($identity, $method);
        $this->db->transComplete();

        $left = $this->recoveryCodesLeft($identity->id);

        $this->audit->recordAudit(
            EventType::TWO_STEP_VERIFIED,
            $identity->id,
            'USER',
            metadata: ['method' => $method, 'recoveryCodesLeft' => $left],
            entity: $this->entity($identity),
            summary: $method === self::METHOD_RECOVERY
                ? 'Passed two-step verification with a recovery code'
                : 'Passed two-step verification',
        );

        return $verification + ['method' => $method, 'recoveryCodesLeft' => $left];
    }

    /**
     * Ten new recovery codes; the old ones stop working. Needs a fresh
     * verification, like turning unattended access on: a set of recovery codes
     * is a way in that does not need the phone.
     *
     * @return list<string>
     */
    public function regenerateRecoveryCodes(RemoteIdentity $identity): array
    {
        $this->requireEnabled($identity);
        $this->requireVerified($identity, $this->config->twoStepStepUpSeconds, self::PURPOSE_RECOVERY_CODES);

        $this->db->transException(true)->transStart();
        $codes = $this->replaceRecoveryCodes($identity->id);
        $this->audit->recordMaterial(
            EventType::TWO_STEP_RECOVERY_CODES_REGENERATED,
            $identity->id,
            'USER',
            metadata: ['recoveryCodes' => count($codes)],
            entity: $this->entity($identity),
            summary: 'Issued new two-step recovery codes',
        );
        $this->db->transComplete();

        return $codes;
    }

    /**
     * Switch it off — with a code, never without one, so a session that has
     * not passed the step cannot remove the step. Every recovery code and every
     * verified browser goes with it (they hang off the one row).
     */
    public function disable(RemoteIdentity $identity, ?string $code, ?string $recoveryCode): void
    {
        $row    = $this->requireEnabled($identity);
        $method = $this->checkSecondStep($identity, $row, $code, $recoveryCode, 'DISABLE');

        $this->db->transException(true)->transStart();
        $this->db->table('remote_user_two_step')->where('user_id', $identity->id)->delete();
        $this->audit->recordMaterial(
            EventType::TWO_STEP_DISABLED,
            $identity->id,
            'USER',
            metadata: ['method' => $method],
            entity: $this->entity($identity),
            summary: 'Turned off two-step verification',
        );
        $this->db->transComplete();

        $this->context->setTwoStepToken(null);
    }

    // --------------------------------------------------------- for operators

    /**
     * Support's last resort for somebody who lost their phone *and* their
     * recovery codes (`php spark remote:two-step reset`). Audited as the
     * system, with the reason given, so the person can see it happened.
     */
    public function reset(int $userId, string $reason): bool
    {
        if ($this->row($userId) === null) {
            return false;
        }

        $this->db->transException(true)->transStart();
        $this->db->table('remote_user_two_step')->where('user_id', $userId)->delete();
        $this->audit->recordMaterial(
            EventType::TWO_STEP_RESET,
            null,
            'SYSTEM',
            metadata: ['reason' => mb_substr($reason, 0, 200), 'userId' => $userId],
            entity: ['type' => 'account', 'id' => (string) $userId, 'label' => null],
            summary: 'Two-step verification was reset by AICOUNTLY support',
        );
        $this->db->transComplete();

        return true;
    }

    /**
     * Re-seal every stored secret under the current key, so the previous keys
     * can be removed from `api/.env` (`php spark remote:two-step rekey`).
     *
     * @return array{resealed: int, current: int, unreadable: int}
     */
    public function rekeyAll(): array
    {
        $counts = ['resealed' => 0, 'current' => 0, 'unreadable' => 0];
        if (! $this->cipher->available()) {
            throw new \RuntimeException('No two-step key is configured: set remote.twoStepKey or remote.signallingSecret first.');
        }

        foreach ($this->db->table('remote_user_two_step')->select('user_id, secret_ciphertext, key_id')->get()->getResultArray() as $row) {
            if ($row['key_id'] === $this->cipher->currentKeyId()) {
                $counts['current']++;

                continue;
            }

            $secret = $this->cipher->open((string) $row['secret_ciphertext'], (string) $row['key_id'], (int) $row['user_id']);
            if ($secret === null) {
                $counts['unreadable']++;

                continue;
            }

            $this->reseal((int) $row['user_id'], $secret);
            $counts['resealed']++;
        }

        return $counts;
    }

    // ------------------------------------------------------------- internals

    /** @return array<string, mixed>|null */
    private function row(int $userId): ?array
    {
        return $this->db->table('remote_user_two_step')->where('user_id', $userId)->get()->getRowArray();
    }

    /** @return array<string, mixed> */
    private function requireEnabled(RemoteIdentity $identity): array
    {
        $row = $this->row($identity->id);
        if ($row === null || $row['confirmed_at'] === null) {
            throw ApiException::conflict('TWO_STEP_NOT_ENABLED', 'Two-step verification is not on for your account.');
        }

        return $row;
    }

    /**
     * Exactly one of the two, then the lockout, then the check itself.
     *
     * @param  array<string, mixed> $row
     * @return string the method that passed
     */
    private function checkSecondStep(RemoteIdentity $identity, array $row, ?string $code, ?string $recoveryCode, string $purpose): string
    {
        $hasCode     = $code !== null && trim($code) !== '';
        $hasRecovery = $recoveryCode !== null && trim($recoveryCode) !== '';

        if ($hasCode === $hasRecovery) {
            throw ApiException::badRequest('VALIDATION_FAILED', 'Enter the 6-digit code from your authenticator app, or one of your recovery codes.', [
                'fields' => ['code' => 'Give a code or a recovery code, not both.'],
            ]);
        }

        if ($hasCode) {
            $normalised = $this->normaliseCode($code);
            $this->assertNotLocked($row);
            $this->acceptTotp($identity, $row, $normalised, $purpose);

            return self::METHOD_TOTP;
        }

        $normalised = $this->normaliseRecoveryCode((string) $recoveryCode);
        $this->assertNotLocked($row);
        $this->acceptRecoveryCode($identity, $normalised, $purpose);

        return self::METHOD_RECOVERY;
    }

    /**
     * Accept an authenticator code for this row, or refuse it — counting a
     * wrong one, refusing a reused one without counting it (it is far more
     * often the person pressing Enter twice than an attacker).
     *
     * @param array<string, mixed> $row
     */
    private function acceptTotp(RemoteIdentity $identity, array $row, string $code, string $purpose): void
    {
        $keyId = (string) $row['key_id'];

        if (! $this->cipher->canOpen($keyId)) {
            // Not the person's mistake, so not counted: the server has lost the
            // key the secret was sealed with. Recovery codes do not depend on it.
            log_message('critical', 'Two-step secret for user {id} is sealed under key {key}, which this server does not hold.', [
                'id' => $identity->id, 'key' => $keyId,
            ]);

            throw ApiException::unavailable(
                'TWO_STEP_KEY_UNAVAILABLE',
                'Your authenticator code cannot be checked on this server right now. Use one of your recovery codes instead, and please tell AICOUNTLY support.',
            );
        }

        $secret = $this->cipher->open((string) $row['secret_ciphertext'], $keyId, $identity->id);
        if ($secret === null) {
            log_message('critical', 'Two-step secret for user {id} did not open: damaged, or not this row\'s.', ['id' => $identity->id]);

            throw ApiException::unavailable(
                'TWO_STEP_KEY_UNAVAILABLE',
                'Your authenticator code cannot be checked on this server right now. Use one of your recovery codes instead, and please tell AICOUNTLY support.',
            );
        }

        $step = Totp::match($secret, $code, time());
        if ($step === null) {
            $this->recordFailure($identity, self::METHOD_TOTP, $purpose);
        }

        // Newer than the last code accepted, or not at all — in one statement,
        // so two requests racing with the same code cannot both get through.
        $this->db->query(
            'UPDATE remote_user_two_step SET last_used_step = ?, failed_attempts = 0, locked_until = NULL, updated_at = ?
              WHERE user_id = ? AND (last_used_step IS NULL OR last_used_step < ?)',
            [$step, Clock::now(), $identity->id, $step],
        );

        if ($this->db->affectedRows() !== 1) {
            throw ApiException::conflict('TWO_STEP_CODE_USED', 'That code has already been used. Wait for your authenticator app to show the next one, then enter that.');
        }

        // Sealed under a key that is on its way out: move it to the current one now
        // that it has been read, so the old key can eventually be removed.
        if ($keyId !== $this->cipher->currentKeyId() && $this->cipher->available()) {
            $this->reseal($identity->id, $secret);
        }
    }

    private function acceptRecoveryCode(RemoteIdentity $identity, string $code, string $purpose): void
    {
        $candidates = $this->db->table('remote_user_recovery_codes')
            ->select('id, code_hash')
            ->where('user_id', $identity->id)
            ->where('used_at', null)
            ->get()
            ->getResultArray();

        $matched = null;
        foreach ($candidates as $candidate) {
            if (password_verify($code, (string) $candidate['code_hash'])) {
                $matched = (int) $candidate['id'];

                break;
            }
        }

        if ($matched === null) {
            $this->recordFailure($identity, self::METHOD_RECOVERY, $purpose);
        }

        $this->db->query(
            'UPDATE remote_user_recovery_codes SET used_at = ? WHERE id = ? AND used_at IS NULL',
            [Clock::now(), $matched],
        );
        if ($this->db->affectedRows() !== 1) {
            throw ApiException::conflict('TWO_STEP_CODE_USED', 'That recovery code has already been used. Each one works once.');
        }

        $this->db->query(
            'UPDATE remote_user_two_step SET failed_attempts = 0, locked_until = NULL, updated_at = ? WHERE user_id = ?',
            [Clock::now(), $identity->id],
        );

        $this->audit->recordAudit(
            EventType::TWO_STEP_RECOVERY_CODE_USED,
            $identity->id,
            'USER',
            metadata: ['purpose' => $purpose, 'recoveryCodesLeft' => $this->recoveryCodesLeft($identity->id)],
            entity: $this->entity($identity),
            summary: 'Used a two-step recovery code',
        );
    }

    /**
     * Count a wrong code, lock at the limit, record it, and refuse.
     *
     * One statement does the counting and the locking, reading the old values
     * in its own SET, so concurrent wrong guesses are all counted.
     */
    private function recordFailure(RemoteIdentity $identity, string $method, string $purpose): never
    {
        $max = $this->config->twoStepMaxFailures;

        $row = $this->db->query(<<<'SQL'
            UPDATE remote_user_two_step
               SET failed_attempts = CASE WHEN failed_attempts + 1 >= ? THEN 0 ELSE failed_attempts + 1 END,
                   locked_until    = CASE WHEN failed_attempts + 1 >= ? THEN ?::timestamptz ELSE locked_until END,
                   updated_at      = ?
             WHERE user_id = ?
            RETURNING failed_attempts, locked_until
            SQL, [$max, $max, Clock::in($this->config->twoStepLockoutSeconds), Clock::now(), $identity->id])->getRowArray();

        $locked = $row !== null && $row['locked_until'] !== null && ! Clock::hasPassed($row['locked_until']);
        $left   = $row !== null ? max(0, $max - (int) $row['failed_attempts']) : 0;

        $this->audit->recordAudit(
            EventType::TWO_STEP_VERIFY_FAILED,
            $identity->id,
            'USER',
            metadata: ['method' => $method, 'purpose' => $purpose, 'locked' => $locked],
            entity: $this->entity($identity),
            summary: $method === self::METHOD_RECOVERY ? 'Entered a wrong two-step recovery code' : 'Entered a wrong two-step code',
        );

        if ($locked) {
            $this->audit->recordAudit(
                EventType::TWO_STEP_LOCKED,
                $identity->id,
                'USER',
                metadata: ['failures' => $max, 'lockedUntil' => Clock::iso($row['locked_until'])],
                entity: $this->entity($identity),
                summary: 'Two-step verification locked after ' . $max . ' wrong codes',
            );

            throw $this->lockedException((string) $row['locked_until']);
        }

        throw ApiException::badRequest(
            'TWO_STEP_CODE_INVALID',
            $method === self::METHOD_RECOVERY
                ? 'That recovery code is not right, or it has been used. Check it and try again.'
                : 'That code is not right. Check the time on your phone is set automatically, and enter the code your authenticator app shows now.',
            ['attemptsLeft' => $left],
        );
    }

    /** @param array<string, mixed> $row */
    private function assertNotLocked(array $row): void
    {
        if ($this->isLocked($row)) {
            throw $this->lockedException((string) $row['locked_until']);
        }
    }

    /** @param array<string, mixed> $row */
    private function isLocked(array $row): bool
    {
        return $row['locked_until'] !== null && ! Clock::hasPassed($row['locked_until']);
    }

    private function lockedException(string $lockedUntil): ApiException
    {
        $seconds = max(1, (int) strtotime($lockedUntil) - time());
        $minutes = (int) ceil($seconds / 60);

        return new ApiException(
            'TWO_STEP_LOCKED',
            sprintf(
                'Too many wrong codes. Two-step verification is locked for %d more minute%s; try again after that.',
                $minutes,
                $minutes === 1 ? '' : 's',
            ),
            429,
            ['lockedUntil' => Clock::iso($lockedUntil), 'retryAfterSeconds' => $seconds],
        );
    }

    /**
     * A fresh verification for this browser. The token exists here and in the
     * response, and nowhere else; the row keeps its hash. Whatever this browser
     * held before is superseded, and the person's expired rows are swept.
     *
     * @return array{token: string, verifiedAt: int, verifiedUntil: int}
     */
    private function issueVerification(RemoteIdentity $identity, string $method): array
    {
        $token    = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $now      = time();
        $lifetime = $this->config->twoStepSessionLifetimeSeconds;

        $this->db->table('remote_two_step_verifications')->insert([
            'user_id'     => $identity->id,
            'token_hash'  => hash('sha256', $token),
            'method'      => $method,
            'verified_at' => Clock::now(),
            'expires_at'  => Clock::in($lifetime),
        ]);

        $previous = $this->context->twoStepToken();
        if ($previous !== null && $previous !== '') {
            $this->db->table('remote_two_step_verifications')
                ->where('user_id', $identity->id)
                ->where('token_hash', hash('sha256', $previous))
                ->delete();
        }
        $this->db->table('remote_two_step_verifications')
            ->where('user_id', $identity->id)
            ->where('expires_at <', Clock::now())
            ->delete();

        // The rest of this request is made by a verified browser too.
        $this->context->setTwoStepToken($token);

        return ['token' => $token, 'verifiedAt' => $now, 'verifiedUntil' => $now + $lifetime];
    }

    /** @return list<string> the codes, formatted for a person; only their hashes are kept */
    private function replaceRecoveryCodes(int $userId): array
    {
        $this->db->table('remote_user_recovery_codes')->where('user_id', $userId)->delete();

        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $raw = '';
            for ($c = 0; $c < self::RECOVERY_LENGTH; $c++) {
                $raw .= self::RECOVERY_ALPHABET[random_int(0, strlen(self::RECOVERY_ALPHABET) - 1)];
            }

            $this->db->table('remote_user_recovery_codes')->insert([
                'user_id'    => $userId,
                'code_hash'  => password_hash($raw, PASSWORD_DEFAULT),
                'created_at' => Clock::now(),
            ]);

            $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5);
        }

        return $codes;
    }

    private function recoveryCodesLeft(int $userId): int
    {
        return $this->db->table('remote_user_recovery_codes')
            ->where('user_id', $userId)
            ->where('used_at', null)
            ->countAllResults();
    }

    private function reseal(int $userId, string $secret): void
    {
        $sealed = $this->cipher->seal($secret, $userId);

        $this->db->table('remote_user_two_step')->where('user_id', $userId)->update([
            'secret_ciphertext' => $sealed['ciphertext'],
            'key_id'            => $sealed['keyId'],
            'updated_at'        => Clock::now(),
        ]);
    }

    /** Six digits, with whatever spaces a person or an app put between them. */
    private function normaliseCode(?string $code): string
    {
        $digits = preg_replace('/[\s-]+/', '', (string) $code) ?? '';

        if (preg_match('/^\d{' . Totp::DIGITS . '}$/', $digits) !== 1) {
            throw ApiException::badRequest('VALIDATION_FAILED', 'Enter the 6-digit code your authenticator app shows.', [
                'fields' => ['code' => 'Six digits.'],
            ]);
        }

        return $digits;
    }

    /** Case and the dash do not matter; a character that could not be in a code does. */
    private function normaliseRecoveryCode(string $code): string
    {
        $clean = strtoupper(preg_replace('/[\s-]+/', '', $code) ?? '');

        if (strlen($clean) !== self::RECOVERY_LENGTH || strspn($clean, self::RECOVERY_ALPHABET) !== self::RECOVERY_LENGTH) {
            throw ApiException::badRequest('VALIDATION_FAILED', 'A recovery code is ten letters and numbers, like ABCDE-23456.', [
                'fields' => ['recoveryCode' => 'Ten letters and numbers.'],
            ]);
        }

        return $clean;
    }

    /** What the authenticator app lists the entry as: the e-mail when there is one. */
    private function accountLabel(RemoteIdentity $identity): string
    {
        return $identity->email !== null && $identity->email !== '' ? $identity->email : $identity->displayName;
    }

    /** @return array{type: string, id: string, label: string} */
    private function entity(RemoteIdentity $identity): array
    {
        return ['type' => 'account', 'id' => $identity->uuid, 'label' => $identity->displayName];
    }

    private function setupMessage(string $purpose): string
    {
        return match ($purpose) {
            self::PURPOSE_UNATTENDED_ENABLE  => 'Unattended access needs two-step verification. Turn it on in Settings, under Two-step verification, then turn unattended access on again.',
            self::PURPOSE_UNATTENDED_CONNECT => 'Connecting to a computer with nobody at it needs two-step verification. Turn it on in Settings, under Two-step verification, then connect again.',
            default                          => 'This needs two-step verification. Turn it on in Settings, under Two-step verification.',
        };
    }

    private function stepUpMessage(string $purpose, ?int $withinSeconds): string
    {
        $recent = $withinSeconds !== null
            ? sprintf(' entered in the last %d minutes', max(1, intdiv($withinSeconds, 60)))
            : '';

        return match ($purpose) {
            self::PURPOSE_UNATTENDED_ENABLE  => "Turning on unattended access needs a code from your authenticator app{$recent}. Enter a code, then try again.",
            self::PURPOSE_UNATTENDED_CONNECT => "Connecting to a computer with nobody at it needs a code from your authenticator app{$recent}. Enter a code, then try again.",
            self::PURPOSE_RECOVERY_CODES     => "New recovery codes need a code from your authenticator app{$recent}. Enter a code, then try again.",
            default                          => "Enter a code from your authenticator app{$recent} to continue.",
        };
    }

    private function isMissingTable(string $message): bool
    {
        return str_contains($message, 'does not exist')
            && (str_contains($message, 'remote_user_two_step') || str_contains($message, 'remote_two_step_verifications'));
    }
}
