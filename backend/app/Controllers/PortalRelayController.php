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

        return $this->response
            ->setStatusCode($result['status'])
            ->setContentType($result['contentType'])
            ->setHeader('Cache-Control', 'no-store')
            ->setBody($result['body']);
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
