<?php

declare(strict_types=1);

/**
 * The ICE kit's probe (lib/ice-kit/bin/probe.php --bootstrap=config/ice-kit.php).
 *
 * Boots CodeIgniter just far enough for its configuration (no request is run), so the probe tests
 * exactly the nodes, secret and health file the API serves from — Config\Remote reads the same .env.
 */

use App\Domain\Signalling\IceConfigService;
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
final class IceKitBoot extends Boot
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

IceKitBoot::boot($paths);

$config = config('Remote');
$ice    = new IceConfigService($config);

return [
    'topology' => $ice->topology(),
    'health'   => new Aicountly\Ice\HealthStore($config->turnHealthFile),
];
