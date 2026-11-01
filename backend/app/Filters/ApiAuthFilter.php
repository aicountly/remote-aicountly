<?php

declare(strict_types=1);

namespace App\Filters;

use App\Domain\Auth\GuestPrincipal;
use App\Domain\Auth\PortalUnavailableException;
use App\Domain\Support\ApiException;
use App\Domain\TwoStep\TwoStepService;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * Authenticates every Remote API call.
 *
 * Two credentials are accepted on the `Authorization: Bearer` header:
 *
 *   * a portal **`ses_key`**, validated server-to-server against
 *     `my.aicountly.com/api/validatesession`;
 *   * a **guest token** (prefixed `guest.`), minted by this API when a one-time
 *     invitation was redeemed.
 *
 * Nothing is inferred from a cookie, a query parameter or a header the client
 * chose — and a portal that cannot be reached results in *denial*, never in a
 * fallback that lets the caller through (§29).
 *
 * `arguments` on the route decide strictness:
 *   * no argument — a signed-in AICOUNTLY user is required;
 *   * `guest`     — a guest token is accepted as well;
 *   * `optional`  — anonymous is allowed through, for endpoints that behave
 *                   differently when someone happens to be signed in;
 *   * `allow-unverified` — a signed-in user who has two-step verification on
 *                   is let through before this browser has passed it (below).
 *
 * **Two-step verification is enforced here**, straight after the portal has
 * said who the caller is, rather than by a filter each route has to remember:
 * a route added next year is covered without anybody thinking about it, and
 * the exemptions are the ones written down in Routes.php with `allow-unverified`
 * — the two-step routes themselves, bootstrap (so the web learns it has to
 * ask), the legacy sign-in check, and switching unattended access off (which
 * must never be harder than switching it on). Sign-out never reaches this
 * filter: it is the portal relay.
 *
 * A person with it on whose browser has not passed it — no
 * `X-Remote-Two-Step` token, an expired one, or somebody else's — is answered
 * `403 TWO_STEP_REQUIRED`, and the web asks for a code and tries again. Guests
 * and devices never get here: a guest has no account to protect, and a device
 * is the machine itself, proven by its own key ({@see DeviceAuthFilter}).
 */
class ApiAuthFilter implements FilterInterface
{
    private const GUEST_PREFIX = 'guest.';

    public function before(RequestInterface $request, $arguments = null)
    {
        $context = Services::requestContext();
        $modes   = array_map('strtolower', (array) ($arguments ?? []));

        // Whatever an earlier request in the same process left is not this one's.
        $context->setTwoStepToken(null);
        $context->setTwoStep(null);

        $token = $this->bearerToken($request);

        if ($token === '') {
            if (in_array('optional', $modes, true)) {
                return null;
            }

            return $this->unauthenticated('Sign in to continue.');
        }

        if (str_starts_with($token, self::GUEST_PREFIX)) {
            if (! in_array('guest', $modes, true) && ! in_array('optional', $modes, true)) {
                return $this->unauthenticated('Guests cannot use this part of AICOUNTLY Remote.');
            }

            $guest = GuestPrincipal::verify(
                Services::remoteConfig(),
                substr($token, strlen(self::GUEST_PREFIX)),
            );

            if ($guest === null) {
                return $this->unauthenticated('This guest session has expired. Ask for a new invitation.');
            }

            $context->setGuest($guest);

            return null;
        }

        try {
            $identity = Services::identityResolver()->resolveFromSesKey($token);
        } catch (PortalUnavailableException) {
            if (in_array('optional', $modes, true)) {
                // An endpoint that merely welcomes someone signed in (a guest opening an
                // invitation link) carries on anonymously while the portal cannot answer.
                return null;
            }

            return $this->unavailable();
        }

        if ($identity === null) {
            if (in_array('optional', $modes, true)) {
                // An expired key on an optional endpoint is treated as
                // anonymous rather than as an error, so a guest opening an
                // invitation link with a stale AICOUNTLY session still gets in.
                return null;
            }

            return $this->unauthenticated('Your session has expired. Sign in again.');
        }

        $context->setIdentity($identity);
        $context->setSesKey($token);
        $context->setTwoStepToken(trim($request->getHeaderLine(TwoStepService::HEADER)));

        try {
            $standing = Services::twoStep()->standing($identity, $context->twoStepToken());
        } catch (ApiException $e) {
            return SecurityHeadersFilter::apply($e->getResponse());
        }
        $context->setTwoStep($standing);

        if ($standing->blocksRequest() && ! in_array('allow-unverified', $modes, true)) {
            return $this->twoStepRequired();
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }

    private function bearerToken(RequestInterface $request): string
    {
        $header = $request->getHeaderLine('Authorization');

        if ($header === '') {
            // Apache under CGI/FastCGI does not pass Authorization to PHP
            // unless told to; the API's .htaccess copies it into the request
            // environment, and after an internal rewrite it arrives with the
            // REDIRECT_ prefix. Missing this is why an otherwise correct
            // deployment answers 401 to everyone.
            $header = (string) ($_SERVER['HTTP_AUTHORIZATION']
                ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
                ?? '');
        }

        if ($header === '' || preg_match('/Bearer\s+(.+)/i', $header, $matches) !== 1) {
            return '';
        }

        return trim($matches[1]);
    }

    /**
     * The portal could not be asked: not a dead session (I-16). 503 with
     * Retry-After, so the browser keeps its sign-in and retries.
     */
    private function unavailable(): ResponseInterface
    {
        return SecurityHeadersFilter::apply(
            service('response')
                ->setStatusCode(503)
                ->setHeader('Retry-After', '30')
                ->setJSON(['error' => [
                    'code'    => 'AUTH_UNAVAILABLE',
                    'message' => 'The AICOUNTLY sign-in service did not answer, so your session could not be checked. You are still signed in; please retry in a moment.',
                    'details' => ['retryable' => true],
                ]]),
        );
    }

    /**
     * Signed in, but this browser has not passed two-step verification. Not a
     * 401: the portal session is good and must be kept; what is missing is the
     * second step, which the web asks for and then retries the same call.
     */
    private function twoStepRequired(): ResponseInterface
    {
        return SecurityHeadersFilter::apply(
            service('response')
                ->setStatusCode(403)
                ->setJSON(['error' => [
                    'code'    => 'TWO_STEP_REQUIRED',
                    'message' => 'Two-step verification is on for your AICOUNTLY Remote account. Enter the code from your authenticator app, or a recovery code, to continue.',
                    'details' => ['reason' => 'SESSION'],
                ]]),
        );
    }

    private function unauthenticated(string $message): ResponseInterface
    {
        // Stamped explicitly: returning a response from `before()` skips the
        // `after` chain, so this would otherwise be the one response in the API
        // without security headers.
        return SecurityHeadersFilter::apply(
            service('response')
                ->setStatusCode(401)
                ->setJSON(['error' => ['code' => 'UNAUTHENTICATED', 'message' => $message]]),
        );
    }
}
