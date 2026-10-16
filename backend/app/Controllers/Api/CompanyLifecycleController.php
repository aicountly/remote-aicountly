<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Libraries\CompanyLifecycle;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * POST /api/internal/manage/company-lifecycle — Manage's signed company-lifecycle events
 * (lib/company-lifecycle; docs/COMPANY_LIFECYCLE.md). The route carries no filter: no session, no
 * company context, no rate limit — the HMAC over the raw body is its only credential. Restricted /
 * unrestricted are applied here; purge requests are queued for the cron worker.
 */
final class CompanyLifecycleController extends Controller
{
    public function receive(): ResponseInterface
    {
        try {
            [$status, $json] = CompanyLifecycle::kit()->receiver()->handle((string) $this->request->getBody(), $this->request->headers());
        } catch (\Throwable $e) {
            // The database could not be reached: not recorded, and Manage retries a 503.
            CompanyLifecycle::log('error', 'event not recorded: ' . $e->getMessage());
            [$status, $json] = [503, ['status' => 'error', 'code' => 'event_not_recorded', 'message' => 'This application could not record the event. Retry later.']];
        }

        return $this->response->setStatusCode($status)->setHeader('Cache-Control', 'no-store')->setJSON($json);
    }
}
