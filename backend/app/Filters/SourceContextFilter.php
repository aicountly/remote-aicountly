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

        $context  = Services::requestContext();
        $identity = $context->identityOrNull();

        // A launch token is for a person (G28#6). Without a signed-in caller there
        // is nobody to bind it to, so it is not read at all: the routes that take
        // one all sit behind `api-auth`, which has already run.
        if ($identity === null) {
            return SecurityHeadersFilter::apply(
                service('response')
                    ->setStatusCode(401)
                    ->setJSON(['error' => [
                        'code'    => 'UNAUTHENTICATED',
                        'message' => 'Sign in to AICOUNTLY to continue.',
                    ]]),
            );
        }

        try {
            $verified = Services::sourceContextVerifier()->verify($token, $identity, $this->roomOf($request));
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
        if ($verified->companyId !== null) {
            Services::platformDirectory()->rememberFromContext($identity, $verified);
        }

        return null;
    }

    /**
     * The session a request is about, when its path names one
     * (`sessions/{uuid}/…`); null on a route that creates or lists.
     */
    private function roomOf(RequestInterface $request): ?string
    {
        $segments = $request->getUri()->getSegments();
        $at       = array_search('sessions', $segments, true);

        return $at !== false && isset($segments[$at + 1]) && $segments[$at + 1] !== 'history'
            ? $segments[$at + 1]
            : null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
