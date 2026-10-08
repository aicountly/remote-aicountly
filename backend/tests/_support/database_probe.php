<?php

declare(strict_types=1);

/**
 * Child process of the Console database tests: boots CodeIgniter's test bootstrap in a NON-testing environment
 * (Config\Database only consults Console outside "testing"), and prints what one piece of Remote does with the
 * environment it was given, as JSON.
 *
 *   PROBE_MODE=config   Config\Database as db_connect() builds it: the default group, or the failure
 *   PROBE_MODE=health   the real HealthController::index() answer (status code and body)
 *
 * Settings come from the process environment, so a test can set database.default.* to junk and the Console
 * variables to a stub. The .env file of a developer's checkout is not read (envDirectory is an empty dir).
 */

use CodeIgniter\Boot;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Paths;

$home = dirname(__DIR__, 2);
chdir($home);

$_SERVER['CI_ENVIRONMENT'] = 'production';
define('ENVIRONMENT', 'production');
define('CI_DEBUG', false);
define('HOMEPATH', $home . DIRECTORY_SEPARATOR);
define('CONFIGPATH', $home . '/app/Config/');
define('PUBLICPATH', $home . '/public/');

require CONFIGPATH . 'Paths.php';
$paths               = new Paths();
$paths->envDirectory = sys_get_temp_dir() . '/remote-no-dotenv';
@mkdir($paths->envDirectory);

define('APPPATH', realpath($paths->appDirectory) . DIRECTORY_SEPARATOR);
define('ROOTPATH', $home . DIRECTORY_SEPARATOR);
define('SYSTEMPATH', realpath($paths->systemDirectory) . DIRECTORY_SEPARATOR);
define('WRITEPATH', realpath($paths->writableDirectory) . DIRECTORY_SEPARATOR);
define('FCPATH', $home . DIRECTORY_SEPARATOR);
define('COMPOSER_PATH', $home . '/vendor/autoload.php');
define('VENDORPATH', $home . '/vendor/');

require $paths->systemDirectory . '/Boot.php';
Boot::bootTest($paths);

$mode = (string) getenv('PROBE_MODE');

try {
    switch ($mode) {
        case 'config':
            echo json_encode(['ok' => true, 'group' => (new Config\Database())->default]);

            break;

        case 'health':
            $controller = new App\Controllers\HealthController();
            $controller->initController(service('request'), service('response'), service('logger'));
            $response = $controller->index();
            echo json_encode(['ok' => true, 'code' => $response->getStatusCode(), 'body' => json_decode((string) $response->getBody(), true)]);

            break;

        default:
            echo json_encode(['ok' => false, 'message' => 'unknown PROBE_MODE']);
    }
} catch (DatabaseException $e) {
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
