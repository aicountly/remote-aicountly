<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Rate limiting for the endpoints worth attacking (§29).
 *
 * Applied per route with a bucket name and a budget, e.g.
 * `['rate-limit' => ['join-code,10,60']]` — ten attempts per sixty seconds.
 *
 * The bucket is keyed on the client IP *and* the bucket name, so exhausting the
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
        $ip = method_exists($request, 'getIPAddress') ? (string) $request->getIPAddress() : '0.0.0.0';

        // Hashed so an IP address is not written into a cache filename.
        return substr(hash('sha256', $ip), 0, 32);
    }
}
