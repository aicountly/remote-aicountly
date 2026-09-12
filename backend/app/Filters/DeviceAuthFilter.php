<?php

declare(strict_types=1);

namespace App\Filters;

use App\Domain\Device\DevicePrincipal;
use App\Domain\Support\ApiException;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * Authenticates a desktop agent, on the routes that are for one.
 *
 * The credential is a `device.`-prefixed Bearer token minted by
 * `POST /devices/auth/verify` after the agent proved possession of its enrolled
 * private key. It is deliberately a separate filter from {@see ApiAuthFilter}
 * rather than a fourth mode inside it, because the two authenticate different
 * *kinds of thing* and mixing them is how a machine ends up being treated as
 * the person who enrolled it.
 *
 * Two checks, in this order, on every request:
 *
 *   1. the token verifies and has not expired;
 *   2. the device it names is still ACTIVE, in the company the token claims.
 *
 * The second is what makes revocation immediate. An administrator revoking a
 * device stops it on its very next call — it does not keep working until the
 * token expires, because the token is not the authority; the row is.
 *
 * `arguments` name the scope the route needs, so a presence credential cannot
 * be replayed against a session endpoint.
 */
class DeviceAuthFilter implements FilterInterface
{
    public const PREFIX = 'device.';

    public function before(RequestInterface $request, $arguments = null)
    {
        $token = $this->bearerToken($request);

        if ($token === '' || ! str_starts_with($token, self::PREFIX)) {
            return $this->unauthenticated('This endpoint is for a registered AICOUNTLY Remote device.');
        }

        $principal = DevicePrincipal::verify(
            Services::remoteConfig(),
            substr($token, strlen(self::PREFIX)),
        );

        if ($principal === null) {
            return $this->unauthenticated('This device credential has expired. Authenticate again.');
        }

        foreach ((array) ($arguments ?? []) as $scope) {
            if (! $principal->hasScope((string) $scope)) {
                return $this->refused('DEVICE_SCOPE_DENIED', 'This device credential does not cover that operation.');
            }
        }

        try {
            // Re-read the row, every time. This is the revocation boundary.
            Services::deviceAuthenticationService()->requireActiveDevice($principal);
        } catch (ApiException $exception) {
            return SecurityHeadersFilter::apply($exception->getResponse());
        }

        Services::requestContext()->setDevice($principal);

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
            // unless told to; see ApiAuthFilter for the same handling.
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
        // Stamped explicitly: a response returned from `before()` skips the
        // `after` chain and would otherwise carry no security headers.
        return SecurityHeadersFilter::apply(
            service('response')
                ->setStatusCode(401)
                ->setJSON(['error' => ['code' => 'DEVICE_UNAUTHENTICATED', 'message' => $message]]),
        );
    }

    private function refused(string $code, string $message): ResponseInterface
    {
        return SecurityHeadersFilter::apply(
            service('response')
                ->setStatusCode(403)
                ->setJSON(['error' => ['code' => $code, 'message' => $message]]),
        );
    }
}
