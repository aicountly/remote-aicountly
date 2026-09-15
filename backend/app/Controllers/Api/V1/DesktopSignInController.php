<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Domain\Support\ApiException;
use App\Domain\Support\Presenter;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * Device-code sign-in (docs/desktop/DEVICE_ENROLMENT.md).
 *
 * `start` and `poll` are called by the desktop agent, which at this point in
 * the flow holds no credential of any kind — that is the problem this exists
 * to solve. They are unauthenticated by design, exactly like
 * {@see DeviceAgentController::challenge()}: the long, unguessable `deviceCode`
 * each returns is the proof, not a header.
 *
 * `confirm` and `deny` are called by the *browser*, from a page the person is
 * already signed in to AICOUNTLY on — ordinary `api-auth`, nothing new.
 */
class DesktopSignInController extends BaseApiController
{
    /** `POST /desktop-signin/start` */
    public function start(): ResponseInterface
    {
        $body = $this->body();

        $input = [
            'deviceName'      => $this->requiredString($body, 'deviceName', 160),
            'publicKey'       => $this->requiredString($body, 'publicKey', 512),
            'deviceType'      => $this->optionalString($body, 'deviceType', 24),
            'operatingSystem' => $this->optionalString($body, 'operatingSystem', 60),
            'osVersion'       => $this->optionalString($body, 'osVersion', 60),
            'architecture'    => $this->optionalString($body, 'architecture', 16),
            'hostname'        => $this->optionalString($body, 'hostname', 160),
            'agentVersion'    => $this->optionalString($body, 'agentVersion', 32),
            'capabilities'    => is_array($body['capabilities'] ?? null) ? $body['capabilities'] : [],
        ];

        return $this->created(
            Services::desktopSignInService()->start($input, $this->clientIp()),
        );
    }

    /** `POST /desktop-signin/poll` */
    public function poll(): ResponseInterface
    {
        $deviceCode = $this->requiredString($this->body(), 'deviceCode', 64);

        $outcome = Services::desktopSignInService()->poll($deviceCode);

        // The service works in the raw storage shape, like every other
        // domain service; presenting it — camelCase, the fingerprint
        // formatted for a person to read — is the controller's job, exactly
        // as DeviceController::enrol() does for the same device row.
        if (isset($outcome['device'])) {
            $outcome['device'] = Presenter::device($outcome['device']);
        }

        return $this->ok($outcome);
    }

    /** `POST /desktop-signin/confirm` */
    public function confirm(): ResponseInterface
    {
        $body      = $this->body();
        $companyId = $this->optionalInt($body, 'companyId');

        if ($companyId === null || $companyId <= 0) {
            throw ApiException::badRequest('COMPANY_REQUIRED', 'Choose the organisation to register this device in.');
        }

        return $this->ok(Services::desktopSignInService()->confirmByUserCode(
            $this->identity(),
            $this->requiredString($body, 'userCode', 16),
            $companyId,
            $this->clientIp(),
        ));
    }

    /** `POST /desktop-signin/deny` */
    public function deny(): ResponseInterface
    {
        $userCode = $this->requiredString($this->body(), 'userCode', 16);

        return $this->ok(Services::desktopSignInService()->denyByUserCode($this->identity(), $userCode));
    }
}
