<?php
declare(strict_types=1);

namespace App\Controllers\Internal;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

require_once __DIR__ . '/MaintenanceControl.php';

use Aicountly\Ops\MaintenanceControl;

/**
 * Maintenance endpoint — CodeIgniter 4 family.
 *
 * CANONICAL COPY: console-react-app/ops/estate/kit/php/adapters/MaintenanceController.ci4.php
 * COPY, DON'T EDIT. Installed as app/Controllers/Internal/MaintenanceController.php, with
 * MaintenanceControl.php beside it.
 *
 * Register in app/Config/Routes.php:
 *
 *   $routes->post('internal/maintenance', 'Internal\MaintenanceController::index');
 *   $routes->get('internal/maintenance',  'Internal\MaintenanceController::index');
 *
 * Products whose routes are behind a capability registry (auditor) must also allow this route
 * there, or it fails closed with 403 before ever reaching the controller. That is the correct
 * default — an unmapped route should not answer — but it means the registry entry is part of
 * adding the endpoint, not an afterthought.
 *
 * MaintenanceControl is required by path rather than autoloaded: CI4 maps App\ onto app/ and the
 * shared class deliberately keeps its own namespace, identical in all four backend shapes.
 */
class MaintenanceController extends Controller
{
    public function index(): ResponseInterface
    {
        $auth = (string) ($this->request->getHeaderLine('Authorization') ?: '');

        [$status, $body] = (new MaintenanceControl())->handle(
            $this->request->getMethod(),
            $auth,
            (string) $this->request->getBody(),
        );

        return $this->response->setStatusCode($status)->setJSON($body);
    }
}
