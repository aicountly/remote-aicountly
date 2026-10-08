<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\ConsoleDatabaseDetails;
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

        $database  = 'unknown';
        $dbFailure = null;

        try {
            // Inside the try: with CONSOLE_DB_DETAILS_KEY set, building the database config asks Console for the
            // name and username and throws a DatabaseException when it cannot be answered.
            db_connect()->query('SELECT 1');
            $database = 'ok';
        } catch (Throwable $e) {
            $database = 'unavailable';
            // A failure to get the database name / username from Console (or none named at all) is reported by its
            // own reason and a fixed sentence. This answer is public, so those sentences come from a fixed list
            // (ConsoleDatabaseDetails::hint) and are never built from the exception.
            $dbFailure = ConsoleDatabaseDetails::diagnose($e);
            if ($dbFailure === null && ! ConsoleDatabaseDetails::isConfigured()) {
                $dbFailure = ConsoleDatabaseDetails::diagnoseUnused((array) (config('Database')->default ?? []));
            }
            log_message('critical', 'Remote: health check could not reach the database: {message}', [
                'message' => $e->getMessage(),
            ]);
        }

        $body = [
            'status'   => $database === 'ok' ? 'ok' : 'degraded',
            'app'      => 'AICOUNTLY Remote',
            'env'      => ENVIRONMENT,
            'database' => $database,
            // Where the database name and username come from ("console" or "env": database.default.* in .env), and
            // why the database is not usable when Console or its missing key is the reason.
            'databaseSource' => ConsoleDatabaseDetails::source(),
        ];
        if ($dbFailure !== null) {
            $body['databaseReason'] = $dbFailure['reason'];
            $body['databaseHint']   = $dbFailure['hint'];
        }

        return $this->response
            ->setStatusCode($database === 'ok' ? 200 : 503)
            ->setHeader('Cache-Control', 'no-store')
            ->setJSON($body + [
                // Whether the deployment is finished being configured. Useful
                // during a first deploy, and reveals nothing: it says that a
                // secret is set, never what it is.
                'signalling' => Services::signallingTokenService()->isConfigured() ? 'configured' : 'unconfigured',
                'relay'      => Services::iceConfigService()->hasRelay() ? 'configured' : 'unconfigured',
                'launchContext' => $this->launchContext(),
                'time'       => gmdate('c'),
            ]);
    }

    /**
     * The verifier is built over the database connection. With Console naming the database, building that
     * connection's config can throw (Console refused or unreachable), and the health answer must still be given:
     * that failure is already reported above, by its own reason.
     */
    private function launchContext(): string
    {
        try {
            return Services::sourceContextVerifier()->isEnabled() ? 'enabled' : 'disabled';
        } catch (Throwable) {
            return 'unknown';
        }
    }
}
