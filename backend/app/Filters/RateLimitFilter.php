<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * Rate limiting for the endpoints worth attacking (§29).
 *
 * Applied per route with a bucket name and a budget, e.g.
 * `['rate-limit' => ['join-code,10,60']]` — ten attempts per sixty seconds.
 *
 * # Whose budget it is
 *
 * **Whoever an earlier filter authenticated**: a registered computer, a
 * signed-in person, or a guest in one session. Only a call nobody has
 * authenticated yet — a device asking for a challenge, a sign-in being
 * relayed — is counted against the address it came from, because there is
 * nothing else to count it against.
 *
 * It used to be the address for everything, and an office is one address.
 * Every computer in it shared one budget for its once-a-minute check-in, its
 * session polls and its help-session window: four people being helped at the
 * same time in one office was enough to have the fifth refused, and a second
 * helper typing a join code could be refused because a colleague had just
 * typed several. A limit that follows the caller is also the better limit
 * against the thing it exists for: somebody walking join codes can change
 * address far more cheaply than they can change account.
 *
 * The bucket is keyed on the caller *and* the bucket name, so exhausting the
 * join-code budget does not also lock the caller out of signing in. Join codes
 * matter most: nine digits is a small space, and without a limit here it can be
 * walked in an afternoon.
 *
 * The limiter uses the framework throttler, which is backed by whatever cache
 * the deployment has. On a cPanel host that is the file cache — enough to blunt
 * a single-source attempt, and honest about not being a distributed limiter.
 */
class RateLimitFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        // CodeIgniter splits a filter's arguments on commas, so
        // `rate-limit:join-code,10,60` arrives as three entries — not as one
        // string. Reading only $arguments[0] silently discards the configured
        // limits and gives every bucket the default instead.
        [$bucket, $capacity, $seconds] = array_pad(array_values((array) ($arguments ?? [])), 3, null);

        $bucket   = is_string($bucket) && $bucket !== '' ? $bucket : 'api';
        $capacity = max(1, (int) ($capacity ?? 120));
        $seconds  = max(1, (int) ($seconds ?? 60));

        // No ':' or '/' — CodeIgniter's cache layer reserves `{}()/\@:` and
        // throws on a key containing one, which would turn every rate-limited
        // endpoint into a 500 rather than a limit.
        $key = sprintf('remote_%s_%s', preg_replace('/[^a-z0-9_-]/i', '', $bucket), $this->clientKey($request));

        $throttler = service('throttler');

        // check() returns false once the bucket is empty. `$seconds / $capacity`
        // is the refill interval the throttler expects for "capacity per
        // seconds" semantics.
        if ($throttler->check($key, $capacity, $seconds) === false) {
            $retryAfter = max(1, (int) $throttler->getTokenTime());

            return SecurityHeadersFilter::apply(service('response'))
                ->setStatusCode(429)
                ->setHeader('Retry-After', (string) $retryAfter)
                ->setJSON([
                    'error' => [
                        'code'    => 'RATE_LIMITED',
                        'message' => 'Too many attempts. Please wait a moment and try again.',
                        'details' => ['retryAfterSeconds' => $retryAfter],
                    ],
                ]);
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }

    private function clientKey(RequestInterface $request): string
    {
        // Hashed so neither an address nor an identifier is written into a cache filename.
        return substr(hash('sha256', $this->caller($request)), 0, 32);
    }

    /**
     * Who this request's budget belongs to.
     *
     * The principal an auth filter put in the request context — but only when it
     * is the kind this request's own `Authorization` header presented. The
     * context is one object per process; under PHP-FPM that is one per request,
     * but anything longer-lived (the test suite, a worker) would otherwise count
     * an unauthenticated call against whoever happened to call before it.
     */
    private function caller(RequestInterface $request): string
    {
        $context = Services::requestContext();
        $bearer  = $this->bearerToken($request);

        if (str_starts_with($bearer, DeviceAuthFilter::PREFIX)) {
            if (($device = $context->device()) !== null) {
                return 'device:' . $device->deviceUuid;
            }
        } elseif (str_starts_with($bearer, 'guest.')) {
            if (($guest = $context->guest()) !== null) {
                return 'guest:' . $guest->participantUuid;
            }
        } elseif ($bearer !== '') {
            if (($identity = $context->identityOrNull()) !== null) {
                return 'user:' . $identity->id;
            }
        }

        return 'address:' . (method_exists($request, 'getIPAddress') ? (string) $request->getIPAddress() : '0.0.0.0');
    }

    /**
     * The bearer token as the auth filters read it — the `$_SERVER` fallbacks
     * included. Apache under CGI/FastCGI does not hand PHP the Authorization
     * header itself (see ApiAuthFilter); reading only the header here would see
     * no token on exactly the deployment this runs on, and count everybody
     * against their address again.
     */
    private function bearerToken(RequestInterface $request): string
    {
        $header = method_exists($request, 'getHeaderLine') ? (string) $request->getHeaderLine('Authorization') : '';

        if ($header === '') {
            $header = (string) ($_SERVER['HTTP_AUTHORIZATION']
                ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
                ?? '');
        }

        if ($header === '' || preg_match('/Bearer\s+(.+)/i', $header, $matches) !== 1) {
            return '';
        }

        return trim($matches[1]);
    }
}
