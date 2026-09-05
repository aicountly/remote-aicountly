<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Domain\Device\DeviceService;
use App\Domain\Policy\PermissionCatalog;
use App\Domain\Support\ApiException;
use App\Domain\Support\Presenter;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * Devices, as seen by a signed-in AICOUNTLY user (§52).
 *
 * Thin, like every other controller here: it reads input, calls
 * {@see DeviceService}, and formats the answer. Every decision — whether this
 * person may enrol into this company, whether they may see this device,
 * whether unattended access may be turned on — is made in the service, where
 * it can be tested without HTTP and cannot be forgotten on one route.
 *
 * The device's own endpoints (presence, its pending sessions, switching its own
 * unattended access off) live in {@see DeviceAgentController}, behind a
 * different filter, because a machine is not a person.
 */
class DeviceController extends BaseApiController
{
    /**
     * `POST /devices/enrol`
     *
     * The agent sends its **public** key. The private half is generated on the
     * machine, protected by the operating system's own key store, and never
     * transmitted — so there is nothing in this request body that would be
     * worth intercepting.
     */
    public function enrol(): ResponseInterface
    {
        $body      = $this->body();
        $companyId = $this->optionalInt($body, 'companyId');

        if ($companyId === null || $companyId <= 0) {
            throw ApiException::badRequest('COMPANY_REQUIRED', 'Choose the organisation to register this device in.');
        }

        $device = Services::deviceService()->enrol($this->identity(), $companyId, [
            'deviceName'      => $this->requiredString($body, 'deviceName', 160),
            'publicKey'       => $this->requiredString($body, 'publicKey', 512),
            'deviceType'      => $this->optionalString($body, 'deviceType', 24),
            'operatingSystem' => $this->optionalString($body, 'operatingSystem', 60),
            'osVersion'       => $this->optionalString($body, 'osVersion', 60),
            'architecture'    => $this->optionalString($body, 'architecture', 16),
            'hostname'        => $this->optionalString($body, 'hostname', 160),
            'agentVersion'    => $this->optionalString($body, 'agentVersion', 32),
            'capabilities'    => is_array($body['capabilities'] ?? null) ? $body['capabilities'] : [],
        ]);

        return $this->created([
            'device' => Presenter::device($device, Services::deviceService()->isOnline($device)),
        ]);
    }

    /**
     * `GET /devices?companyId=`
     *
     * With `remote.device.manage`, the organisation's devices; without it, only
     * the ones this person registered themselves.
     */
    public function index(): ResponseInterface
    {
        $companyId = $this->request->getGet('companyId');
        if (! is_numeric($companyId)) {
            throw ApiException::badRequest('COMPANY_REQUIRED', 'Choose an organisation to list devices for.');
        }

        $devices = Services::deviceService();
        $policy  = $this->policyFor('COMPANY', (int) $companyId);

        $page = $devices->forCompany(
            $this->identity(),
            (int) $companyId,
            (int) ($this->request->getGet('limit') ?? 50),
            (int) ($this->request->getGet('offset') ?? 0),
        );

        $includeAudit = $policy->can(PermissionCatalog::AUDIT_VIEW);

        return $this->ok([
            'devices' => array_map(
                static fn (array $row) => Presenter::device($row, $devices->isOnline($row), $includeAudit),
                $page['items'],
            ),
            'canEnrol'  => $policy->can(PermissionCatalog::DEVICE_ENROL),
            'canManage' => $policy->can(PermissionCatalog::DEVICE_MANAGE),
            'canConnectUnattended' => $policy->allowUnattendedAccess
                && $policy->can(PermissionCatalog::UNATTENDED_ACCESS),
            'policy' => [
                'allowRemoteControl'    => $policy->allowRemoteControl,
                'allowUnattendedAccess' => $policy->allowUnattendedAccess,
                'allowClipboardSync'    => $policy->allowClipboardSync,
                'allowDeviceReboot'     => $policy->allowDeviceReboot,
                'restrictions'          => $policy->restrictions,
            ],
        ], ['total' => $page['total']]);
    }

    /** `GET /devices/{uuid}` */
    public function show(string $uuid): ResponseInterface
    {
        $devices = Services::deviceService();
        $device  = $devices->findForUser($this->identity(), $uuid);
        $policy  = $this->policyFor('COMPANY', (int) $device['company_id']);

        return $this->ok([
            'device' => Presenter::device(
                $device,
                $devices->isOnline($device),
                $policy->can(PermissionCatalog::AUDIT_VIEW),
            ),
            // The declaration ∧ the organisation's ceiling. This is what a
            // session with this device would actually be able to do, which is
            // not the same thing as what the agent says it can do.
            'effectiveCapabilities' => $devices->effectiveCapabilities($device, $policy),
            'sessions' => array_map(
                static fn (array $row) => Presenter::session($row),
                Services::deviceSessionService()->recentFor($device),
            ),
        ]);
    }

    /** `PATCH /devices/{uuid}` — rename, suspend or resume. */
    public function update(string $uuid): ResponseInterface
    {
        $body   = $this->body();
        $device = Services::deviceService()->update($this->identity(), $uuid, [
            'deviceName' => $this->optionalString($body, 'deviceName', 160) ?? '',
            'status'     => $this->optionalString($body, 'status', 16) ?? '',
        ]);

        return $this->ok(['device' => Presenter::device($device, Services::deviceService()->isOnline($device))]);
    }

    /**
     * `POST /devices/{uuid}/revoke`
     *
     * Server-side and immediate: the device's next call fails whatever
     * unexpired credential it is holding, because every device-authenticated
     * request re-reads this row.
     */
    public function revoke(string $uuid): ResponseInterface
    {
        $device = Services::deviceService()->revoke(
            $this->identity(),
            $uuid,
            $this->optionalString($this->body(), 'reason', 120),
        );

        return $this->ok(['device' => Presenter::device($device)]);
    }

    /**
     * `POST /devices/{uuid}/unattended/enable`
     *
     * Its own endpoint, its own permission, its own audit event, its own
     * confirmation. Unattended access is never a field inside another call.
     */
    public function enableUnattended(string $uuid): ResponseInterface
    {
        $device = Services::deviceService()->enableUnattended(
            $this->identity(),
            $uuid,
            $this->boolean($this->body(), 'confirm'),
        );

        return $this->ok(['device' => Presenter::device($device, Services::deviceService()->isOnline($device))]);
    }

    /** `POST /devices/{uuid}/unattended/disable` */
    public function disableUnattended(string $uuid): ResponseInterface
    {
        $device = Services::deviceService()->disableUnattended($this->identity(), $uuid);

        return $this->ok(['device' => Presenter::device($device, Services::deviceService()->isOnline($device))]);
    }

    /**
     * `POST /devices/{uuid}/connect` — start an unattended session.
     *
     * Everything this needs to refuse is refused in
     * {@see \App\Domain\Device\DeviceSessionService::startUnattended()}; what
     * comes back is an ordinary session resource, because that is what it is.
     */
    public function connect(string $uuid): ResponseInterface
    {
        $result = Services::deviceSessionService()->startUnattended(
            $this->identity(),
            $uuid,
            $this->optionalString($this->body(), 'issueSummary', 500),
        );

        $participant = $result['participant'];

        return $this->created([
            'session'     => Presenter::session($result['session']),
            'device'      => Presenter::device($result['device'], true),
            'participant' => $participant === [] ? null : Presenter::participant($participant),
            'host'        => $result['hostParticipant'] === []
                ? null
                : Presenter::participant($result['hostParticipant']),
            // A short-lived token for the device's presence room, so the
            // browser can tell the agent to join now rather than waiting for
            // its next poll. It names one room and expires in minutes.
            'deviceInvite' => Services::devicePresenceService()->inviteToken(
                $this->identity(),
                $result['device'],
                (string) $result['session']['uuid'],
            ),
        ]);
    }

    /**
     * `POST /devices/{uuid}/reboot`
     *
     * Restarting somebody's computer is a separately authorised action, not a
     * button that comes free with control (§16). It needs the company switch,
     * the permission, and a live session on that device — so there is no way to
     * power-cycle a machine nobody is connected to.
     */
    public function reboot(string $uuid): ResponseInterface
    {
        $result = Services::devicePresenceService()->requestReboot(
            $this->identity(),
            $uuid,
            $this->optionalString($this->body(), 'sessionUuid', 64),
        );

        return $this->ok($result);
    }
}
