<?php

declare(strict_types=1);

/**
 * The company-lifecycle worker's kit (lib/company-lifecycle/bin/worker.php --bootstrap=config/lifecycle-kit.php).
 *
 * Boots CodeIgniter just far enough for its configuration (no request is run), so the kit gets the same
 * database the API uses — Config\Database resolves the name and user from Console when CONSOLE_API_URL
 * and CONSOLE_DB_DETAILS_KEY are set, which the kit's own --env reading would not know — the same .env,
 * and the account hook (App\Libraries\CompanyLifecycle).
 */

use CodeIgniter\Boot;
use Config\Paths;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (! defined('FCPATH')) {
    define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);
}
require_once FCPATH . '../app/Config/Paths.php';
$paths = new Paths();
require_once $paths->systemDirectory . '/Boot.php';

/** Boot's own steps for a console script, without running a command or a request. */
final class LifecycleWorkerBoot extends Boot
{
    public static function boot(Paths $paths): void
    {
        static::definePathConstants($paths);
        if (! defined('APP_NAMESPACE')) {
            static::loadConstants();
        }
        static::checkMissingExtensions();
        static::loadDotEnv($paths);
        static::defineEnvironment();
        static::loadEnvironmentBootstrap($paths);
        static::loadCommonFunctions();
        static::loadAutoloader();
        static::setExceptionHandler();
        static::autoloadHelpers();
    }
}

LifecycleWorkerBoot::boot($paths);

return App\Libraries\CompanyLifecycle::build();
