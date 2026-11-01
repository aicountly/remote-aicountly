<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Domain\Support\Presenter;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * `v1/remote/me/two-step` — the caller's own two-step verification.
 *
 * Thin, like every controller here: everything that decides anything — the
 * lockout, replay, whether a step-up is fresh enough — is in
 * {@see \App\Domain\TwoStep\TwoStepService}. Each route acts on the signed-in
 * person and takes no id of anybody's, so there is no other person's setting a
 * caller could name.
 *
 * The secret (on enrol), the recovery codes (on confirm and regeneration) and
 * the verification token (on confirm and verify) each appear in exactly one
 * response and are never readable again.
 */
class TwoStepController extends BaseApiController
{
    /** `GET me/two-step` → `{enabled, confirmedAt, recoveryCodesLeft, sessionVerified, verifiedUntil, …}` */
    public function show(): ResponseInterface
    {
        return $this->ok(Presenter::twoStepStatus(Services::twoStep()->status($this->identity())));
    }

    /** `POST me/two-step/enrol` → `{secret, otpauthUri}`, pending until confirmed. */
    public function enrol(): ResponseInterface
    {
        return $this->created(Services::twoStep()->enrol($this->identity()));
    }

    /** `POST me/two-step/confirm` `{code}` → `{recoveryCodes, verificationToken, verifiedAt, verifiedUntil}` */
    public function confirm(): ResponseInterface
    {
        $result = Services::twoStep()->confirm($this->identity(), $this->codeField('code'));

        return $this->ok(['recoveryCodes' => $result['recoveryCodes']] + Presenter::twoStepVerification($result));
    }

    /** `POST me/two-step/verify` `{code}` or `{recoveryCode}` → `{verificationToken, verifiedAt, verifiedUntil, method, recoveryCodesLeft}` */
    public function verify(): ResponseInterface
    {
        $result = Services::twoStep()->verify(
            $this->identity(),
            $this->codeField('code'),
            $this->codeField('recoveryCode'),
        );

        return $this->ok(Presenter::twoStepVerification($result) + [
            'method'            => $result['method'],
            'recoveryCodesLeft' => $result['recoveryCodesLeft'],
        ]);
    }

    /** `POST me/two-step/recovery-codes` → `{recoveryCodes}`; the old set stops working. */
    public function recoveryCodes(): ResponseInterface
    {
        return $this->ok(['recoveryCodes' => Services::twoStep()->regenerateRecoveryCodes($this->identity())]);
    }

    /** `POST me/two-step/disable` `{code}` or `{recoveryCode}` → the status, now off. */
    public function disable(): ResponseInterface
    {
        $twoStep = Services::twoStep();
        $twoStep->disable($this->identity(), $this->codeField('code'), $this->codeField('recoveryCode'));

        return $this->ok(Presenter::twoStepStatus($twoStep->status($this->identity())));
    }

    /** A code as a string, whatever JSON type it arrived as — `123456` and `"123 456"` alike. */
    private function codeField(string $key): ?string
    {
        $value = $this->body()[$key] ?? null;

        if (is_int($value)) {
            // An integer loses a leading zero; put it back.
            return str_pad((string) $value, 6, '0', STR_PAD_LEFT);
        }

        return is_string($value) ? mb_substr($value, 0, 64) : null;
    }
}
