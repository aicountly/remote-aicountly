<?php

declare(strict_types=1);

namespace App\Filters;

use App\Domain\Auth\GuestPrincipal;
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
 *                   differently when someone happens to be signed in.
 */
class ApiAuthFilter implements FilterInterface
{
    private const GUEST_PREFIX = 'guest.';

    public function before(RequestInterface $request, $arguments = null)
    {
        $context = Services::requestContext();
        $modes   = array_map('strtolower', (array) ($arguments ?? []));

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

        $identity = Services::identityResolver()->resolveFromSesKey($token);

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
