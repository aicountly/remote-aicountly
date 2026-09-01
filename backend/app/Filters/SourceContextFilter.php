<?php

declare(strict_types=1);

namespace App\Filters;

use App\Domain\Support\ApiException;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * Consumes the signed launch context another AICOUNTLY product sent (§6C).
 *
 * The token arrives on `X-Remote-Context`, and is verified — signature, issuer,
 * audience, expiry, product allowlist, one-time `jti` — before anything reads a
 * company id from it. A request without the header is perfectly normal and
 * passes straight through; the context is how a session *acquires* a company,
 * not how every request proves one.
 *
 * A malformed or replayed token fails the request rather than being ignored:
 * silently continuing without context would drop the user into a personal
 * session when they meant to be in their organisation's (§13).
 */
class SourceContextFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $token = trim($request->getHeaderLine('X-Remote-Context'));

        if ($token === '') {
            return null;
        }

        $context = Services::requestContext();

        try {
            $verified = Services::sourceContextVerifier()->verify($token);
        } catch (ApiException $e) {
            return SecurityHeadersFilter::apply(
                service('response')
                    ->setStatusCode($e->status())
                    ->setJSON([
                        'error' => [
                            'code'    => $e->errorCode(),
                            'message' => $e->getMessage(),
                        ],
                    ]),
            );
        }

        $context->setSourceContext($verified);

        // The verified token is also the strongest membership signal there is:
        // an AICOUNTLY product has just asserted, over a signature, that this
        // person is working in this company. Record it so the company is
        // selectable afterwards without a directory API.
        $identity = $context->identityOrNull();
        if ($identity !== null && $verified->companyId !== null) {
            Services::platformDirectory()->rememberFromContext($identity, $verified);
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
