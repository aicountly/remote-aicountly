<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Domain\Support\Presenter;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * Getting into someone else's session (§6E, §6F).
 *
 * Both endpoints are rate limited in the route definition, and both are
 * deliberately vague about failure: "that code is not valid" covers a code that
 * never existed and a code whose session has finished, so the space cannot be
 * walked to discover which sessions are live.
 */
class JoinController extends BaseApiController
{
    /** `POST /join/code` — nine digits, signed-in AICOUNTLY users only. */
    public function byCode(): ResponseInterface
    {
        $code = $this->requiredString($this->body(), 'code', 32);

        $result = Services::joinService()->joinByCode(
            $code,
            $this->identity(),
            $this->userAgent(),
            $this->clientIp(),
        );

        return $this->created([
            'session'     => Presenter::session($result['session']),
            'participant' => Presenter::participant($result['participant']),
        ]);
    }

    /**
     * `POST /join/redeem` — a one-time invitation link.
     *
     * The route allows an anonymous caller, because an external guest has no
     * AICOUNTLY account: that is the whole purpose of a guest invitation. An
     * INTERNAL or SUPPORT invitation still requires a signed-in user, which
     * {@see \App\Domain\Session\JoinService::redeemInvitation()} enforces.
     */
    public function redeem(): ResponseInterface
    {
        $body   = $this->body();
        $secret = $this->requiredString($body, 'token', 128);

        $result = Services::joinService()->redeemInvitation(
            $secret,
            $this->context()->identityOrNull(),
            $this->optionalString($body, 'displayName', 120),
            $this->optionalString($body, 'email', 190),
            $this->userAgent(),
            $this->clientIp(),
        );

        $payload = [
            'session'     => Presenter::session($result['session']),
            'participant' => Presenter::participant($result['participant']),
        ];

        // The guest's credential for the rest of this session. Bound to one
        // participant in one session, and expiring with it (§23).
        if ($result['guestToken'] !== null) {
            $payload['guestToken'] = 'guest.' . $result['guestToken'];
        }

        return $this->created($payload);
    }
}
