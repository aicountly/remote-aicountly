<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\HTTP\RequestInterface;

/**
 * {@see DeviceAuthFilter}, except that offering no device credential at all is
 * not an error — it simply leaves {@see \App\Domain\Support\RequestContext::device()}
 * null and lets the route decide.
 *
 * Exactly one route uses it, and only for as long as it has to.
 * `POST /desktop-signin/handoff` answers with a person's estate-wide
 * `auth_token`, so it must be redeemed by the machine the hand-off was issued
 * to — but an agent already installed in the field redeems with the one-time
 * code alone, and a hard refusal would leave those machines unable to finish a
 * sign-in. So a credential is *required by the service* from the agents that
 * can send one, *demanded of everybody* after
 * `DesktopSignInService::HANDOFF_UNAUTHENTICATED_SUNSET`, and this filter plus
 * that constant are deleted together on that date.
 *
 * It is not a general-purpose "authenticate if you feel like it" filter and
 * must not be put on another route. A credential that is optional is a
 * credential that is absent, and every route that answers with anything worth
 * having should say so with {@see DeviceAuthFilter} instead.
 */
final class OptionalDeviceAuthFilter extends DeviceAuthFilter
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $token = $this->bearerToken($request);

        if ($token === '' || ! str_starts_with($token, self::PREFIX)) {
            return null;
        }

        // Offered, so it is held to the full standard: a credential that does
        // not verify, has expired, or names a device that is no longer active
        // is a refusal, not a fallback to the unauthenticated path.
        return parent::before($request, $arguments);
    }
}
