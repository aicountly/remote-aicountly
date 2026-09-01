<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Security headers on every API response (§29).
 *
 * The API only ever returns JSON, which is what makes this set appropriate: a
 * `default-src 'none'` policy would break a page, but there is no page here.
 * If a response is somehow coerced into being rendered, the CSP below leaves it
 * with nothing to execute.
 *
 * The SPA's own CSP is a separate matter and ships with the web build, in
 * `web/public/.htaccess` — it has to permit the app's own scripts and its
 * WebSocket connection to the signalling service.
 */
class SecurityHeadersFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return self::apply($response);
    }

    /**
     * Stamp the headers onto a response.
     *
     * Also called directly by the filters that short-circuit a request — an
     * authentication failure and a rate limit both return from `before()`, and
     * CodeIgniter does not run the `after` chain for those. Without this, the
     * two most common error responses in the API would be the only ones
     * without security headers.
     */
    public static function apply(ResponseInterface $response): ResponseInterface
    {
        // setHeader appends to a header that is already present, and CodeIgniter
        // has usually set its own Cache-Control by now — removing first is what
        // stops the value becoming a concatenation of both.
        $response->removeHeader('Cache-Control');
        $response->removeHeader('Pragma');

        return $response
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('X-Frame-Options', 'DENY')
            ->setHeader('Referrer-Policy', 'no-referrer')
            ->setHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'")
            ->setHeader('Permissions-Policy', 'display-capture=(), microphone=(), camera=()')
            // Every API response carries session state or policy decisions.
            // None of it may sit in a shared cache.
            ->setHeader('Cache-Control', 'no-store, private')
            ->setHeader('Pragma', 'no-cache');
    }
}
