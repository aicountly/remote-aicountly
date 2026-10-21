<?php

declare(strict_types=1);

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * `/api/manage/v1/*` — Aicountly Manage's company registry, relayed for the shared company-access
 * screens (Users & access, My workspaces, the company picker).
 *
 * Production hosting answers a browser it distrusts (common Indian CGNAT broadband) with a challenge
 * page that carries no CORS headers, so those screens cannot call manage.aicountly.com from the
 * browser; they call this API on their own origin instead. The allow-list, the header filter and
 * Manage's address live in {@see \App\Domain\Directory\ManageRegistryRelay}. No `api-auth` here on
 * purpose: Manage checks the person's own ses_key on every call, and this relay grants nothing.
 */
class ManageRegistryController extends Controller
{
    public function relay(string ...$segments): ResponseInterface
    {
        $method = strtoupper($this->request->getMethod());

        $authorization = $this->request->getHeaderLine('Authorization');
        if ($authorization === '') {
            $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        }

        $answer = Services::manageRegistryRelay()->relay(
            $method,
            array_merge(['v1'], $segments),
            (string) $this->request->getUri()->getQuery(),
            [
                'Authorization'    => $authorization,
                'Content-Type'     => $this->request->getHeaderLine('Content-Type'),
                'Accept'           => $this->request->getHeaderLine('Accept'),
                'Idempotency-Key'  => $this->request->getHeaderLine('Idempotency-Key'),
                'X-Correlation-Id' => $this->request->getHeaderLine('X-Correlation-Id'),
            ],
            $method === 'GET' ? '' : (string) $this->request->getBody(),
        );

        $response = $this->response
            ->setStatusCode($answer['status'])
            ->setContentType($answer['contentType'])
            ->setHeader('Cache-Control', 'no-store');
        if ($answer['correlationId'] !== '') {
            $response = $response->setHeader('X-Correlation-Id', $answer['correlationId']);
        }

        return $response->setBody($answer['body']);
    }
}
