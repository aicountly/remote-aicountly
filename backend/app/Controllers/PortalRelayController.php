<?php

declare(strict_types=1);

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * The AICOUNTLY portal auth relay.
 *
 * The browser calls `/api/global/seskey` on its **own origin** and this
 * forwards it to `my.aicountly.com` server-to-server. A brand-new product
 * domain is not in the portal's CORS allowlist on day one, so a direct call
 * from the page would fail with nothing but a CORS message to show for it.
 *
 * It is an **allowlist and must stay one**. Forwarding arbitrary paths would
 * turn this host into an open proxy for the portal's whole auth surface —
 * login, signup, OTP, user lookup — with the portal seeing this server's IP
 * instead of the caller's, so anything it rate-limits per IP could be driven
 * through here instead.
 *
 * See docs/auth/AICOUNTLY_AUTH_WORKFLOW.md. This behaviour is carried over
 * unchanged from the `server-php` API it replaces.
 */
class PortalRelayController extends Controller
{
    private const RELAYED_PATHS = [
        'seskey',
        'seskey/refresh',
        'refresh_authtoken',
        // The one-time sign-in code redemption (I-17): the portal's CORS list is
        // static, so the browser reaches `POST /api/auth/exchange` through here.
        'auth/exchange',
        // The registry products' one-time code (`sso_code`), which is what the
        // portal hands this app (IDN-01). Relayed with no Origin of our own: the
        // portal compares an Origin only when one is sent, and ours would not match.
        'sso/exchange',
        // Sign-out. The browser used to POST my.aicountly.com/api/logout itself;
        // production hosting answers a browser it distrusts (common Indian CGNAT
        // broadband) with a challenge page that has no CORS headers, so that
        // cross-site call failed and the portal token was never revoked.
        'logout',
    ];

    public function relay(string ...$segments): ResponseInterface
    {
        $path = strtolower(implode('/', array_map(
            static fn (string $segment) => rawurldecode($segment),
            $segments,
        )));

        // Decoded before matching so `%2e%2e` cannot smuggle a traversal
        // segment past the allowlist; exact matching does the rest.
        $path = trim(str_replace('\\', '/', $path), '/');

        if (! in_array($path, self::RELAYED_PATHS, true)) {
            return $this->response
                ->setStatusCode(404)
                ->setHeader('Cache-Control', 'no-store')
                ->setJSON(['message' => 'This path is not relayed. Call the portal API directly.']);
        }

        $headers = [];

        $authorization = $this->request->getHeaderLine('Authorization');
        if ($authorization === '') {
            $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        }
        if ($authorization !== '') {
            $headers[] = 'Authorization: ' . $authorization;
        }

        $contentType = $this->request->getHeaderLine('Content-Type');
        if ($contentType !== '') {
            $headers[] = 'Content-Type: ' . $contentType;
        }

        $result = Services::portalClient()->forward(
            $this->request->getMethod(),
            $path,
            $headers,
            (string) $this->request->getBody(),
        );

        if ($result['status'] === 504) {
            return $this->response
                ->setStatusCode(504)
                ->setHeader('Cache-Control', 'no-store')
                ->setJSON(['message' => 'Auth service unavailable — please retry.']);
        }

        // The portal answers JSON and only JSON. HTML here is something in
        // front of it — a WAF's “checking your browser” page, a maintenance
        // page — and it arrives with the portal's own 200, which passed straight
        // through to the browser as a successful sign-in carrying no token. The
        // app then reported a 200 it could not parse as the sign-in service
        // being unavailable, which is true but unactionable. Say so properly
        // instead: the portal never saw the request, so a one-time code is
        // unspent and the same call may be made again.
        if (self::isNotJson($result['contentType'])) {
            log_message('error', 'portal relay: ' . $path . ' was answered by an interceptor, not the portal ('
                . $result['contentType'] . ', HTTP ' . $result['status'] . ')');

            return $this->response
                ->setStatusCode(503)
                ->setHeader('Cache-Control', 'no-store')
                ->setHeader('Retry-After', '5')
                ->setJSON([
                    'status'    => 0,
                    'error'     => 'auth_unavailable',
                    'retryable' => true,
                    'message'   => 'Sign-in service temporarily unavailable. Please retry.',
                ]);
        }

        $response = $this->response
            ->setStatusCode($result['status'])
            ->setContentType($result['contentType'])
            ->setHeader('Cache-Control', 'no-store');

        // sso/exchange 503 auth_unavailable: when the same code may be tried again.
        if (($result['retryAfter'] ?? '') !== '') {
            $response = $response->setHeader('Retry-After', $result['retryAfter']);
        }

        return $response->setBody($result['body']);
    }

    /**
     * True when a content type is not the portal's JSON.
     *
     * Deliberately positive about JSON rather than looking for `text/html`: an
     * interceptor may answer `text/plain`, or nothing at all, and anything that
     * is not JSON is equally not an answer this relay may hand on as one.
     */
    private static function isNotJson(string $contentType): bool
    {
        $type = strtolower(trim($contentType));

        return $type === '' || (! str_contains($type, 'application/json') && ! str_contains($type, '+json'));
    }

    /**
     * `GET /api/session` — who the caller is, per the portal.
     *
     * Kept from the previous API so the auth flow can still be verified end to
     * end in each environment with one curl. The product API is under
     * `/api/v1/remote`.
     */
    public function whoami(): ResponseInterface
    {
        $identity = Services::requestContext()->identity();

        return $this->response
            ->setHeader('Cache-Control', 'no-store')
            ->setJSON([
                'authenticated' => true,
                'uuid'          => $identity->uuid,
                'displayName'   => $identity->displayName,
            ]);
    }
}
