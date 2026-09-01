<?php

declare(strict_types=1);

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Throwable;

/**
 * `GET /api/health` — liveness, and which environment answered.
 *
 * Unauthenticated on purpose: it is what a deploy workflow curls to confirm the
 * API came up, and what tells you whether you are looking at sandbox or
 * production. It therefore reports only what is safe to publish — never a
 * hostname, a credential or a connection string.
 */
class HealthController extends Controller
{
    public function index(): ResponseInterface
    {
        $config = Services::remoteConfig();

        $database = 'unknown';

        try {
            db_connect()->query('SELECT 1');
            $database = 'ok';
        } catch (Throwable $e) {
            $database = 'unavailable';
            log_message('critical', 'Remote: health check could not reach the database: {message}', [
                'message' => $e->getMessage(),
            ]);
        }

        return $this->response
            ->setStatusCode($database === 'ok' ? 200 : 503)
            ->setHeader('Cache-Control', 'no-store')
            ->setJSON([
                'status'   => $database === 'ok' ? 'ok' : 'degraded',
                'app'      => 'AICOUNTLY Remote',
                'env'      => ENVIRONMENT,
                'database' => $database,
                // Whether the deployment is finished being configured. Useful
                // during a first deploy, and reveals nothing: it says that a
                // secret is set, never what it is.
                'signalling' => Services::signallingTokenService()->isConfigured() ? 'configured' : 'unconfigured',
                'relay'      => Services::iceConfigService()->hasRelay() ? 'configured' : 'unconfigured',
                'launchContext' => Services::sourceContextVerifier()->isEnabled() ? 'enabled' : 'disabled',
                'time'       => gmdate('c'),
            ]);
    }
}
