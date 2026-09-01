<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * CORS, as a strict allowlist (§29).
 *
 * In both deployed environments the app and this API share an origin, so no
 * CORS header is sent at all and none is needed. The allowlist exists for
 * `npm run dev` on localhost against a deployed sandbox API.
 *
 * `Access-Control-Allow-Origin: *` is never emitted, and the origin is echoed
 * only after an exact string match — a `startsWith` check here is how
 * `https://remote.aicountly.com.attacker.example` gets let in.
 */
class CorsFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $origin = $request->getHeaderLine('Origin');
        if ($origin === '') {
            return null;
        }

        $allowed = Services::remoteConfig()->corsAllowedOrigins;
        if (! in_array($origin, $allowed, true)) {
            // No headers, so the browser blocks the response — which is the
            // correct answer for an origin nobody configured.
            return null;
        }

        $response = service('response')
            ->setHeader('Access-Control-Allow-Origin', $origin)
            ->setHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, X-Remote-Context, X-Request-Id')
            ->setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
            ->setHeader('Access-Control-Max-Age', '600')
            ->setHeader('Vary', 'Origin');

        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            return $response->setStatusCode(204)->setBody('');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $origin = $request->getHeaderLine('Origin');
        if ($origin === '' || ! in_array($origin, Services::remoteConfig()->corsAllowedOrigins, true)) {
            return $response;
        }

        return $response
            ->setHeader('Access-Control-Allow-Origin', $origin)
            ->setHeader('Vary', 'Origin');
    }
}
