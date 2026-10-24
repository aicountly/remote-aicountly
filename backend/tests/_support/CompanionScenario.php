<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Auth\RemoteIdentity;
use App\Domain\Device\DevicePrincipal;
use App\Domain\Policy\EffectivePolicy;
use App\Domain\Policy\PermissionCatalog;
use App\Domain\Session\ParticipantService;
use Config\Services;

/**
 * A control-companion session, built through the real services (docs/desktop/CONTROL_COMPANION.md).
 *
 * A (`sharer`) shares an entire screen from the browser in a company session; B (`viewer`) is admitted
 * and joined; A's own desktop app (`device`) is enrolled, online and declares remote control.
 *
 * @mixin RemoteTestCase
 */
trait CompanionScenario
{
    protected int $companyId = 1300;

    /**
     * @param  array<string, bool|int|string> $policy
     * @return array{session: array<string, mixed>, sharer: RemoteIdentity, viewer: RemoteIdentity,
     *               device: array<string, mixed>, principal: DevicePrincipal,
     *               sharerParticipant: array<string, mixed>, viewerParticipant: array<string, mixed>}
     */
    protected function companionScenario(array $policy = [], ?string $surface = 'monitor'): array
    {
        $sharer = $this->makeIdentity('Asha sharing');
        $viewer = $this->makeIdentity('Bala viewing');

        $this->makeDesktopCompany($this->companyId, 'Companion Co', array_merge([
            'allow_entire_monitor'     => true,
            'allow_application_window' => true,
        ], $policy));
        $this->grantCompanyAccess($sharer, $this->companyId);
        $this->grantCompanyAccess($viewer, $this->companyId);
        $this->setUserPermission($viewer, $this->companyId, PermissionCatalog::CONTROL_REQUEST, 'ALLOW');
        foreach ([PermissionCatalog::MONITOR_SHARE, PermissionCatalog::WINDOW_SHARE] as $permission) {
            $this->setUserPermission($sharer, $this->companyId, $permission, 'ALLOW');
        }

        ['device' => $device, 'secretKey' => $secretKey] = $this->enrolDevice($sharer, $this->companyId, ['deviceName' => 'Asha MacBook']);
        $this->markDeviceOnline((string) $device['uuid']);

        $session = $this->makeSession($sharer, 'COMPANY', $this->companyId);

        $participants = Services::participantService();
        $joining      = $participants->requestJoin($session, $viewer, $viewer->displayName, ParticipantService::ROLE_VIEWER);
        $participants->approve($session, (string) $joining['uuid'], $sharer);
        $participants->markJoined($session, (string) $joining['uuid']);

        if ($surface !== null) {
            $this->share($session, $sharer, $surface);
        }

        return [
            'session'           => $this->reload($session),
            'sharer'            => $sharer,
            'viewer'            => $viewer,
            'device'            => Services::deviceService()->findByUuidOrFail((string) $device['uuid']),
            'principal'         => $this->principalFor((string) $device['uuid']),
            'secretKey'         => $secretKey,
            'sharerParticipant' => $participants->findByUser((int) $session['id'], $sharer->id),
            'viewerParticipant' => $participants->findByUuidOrFail((string) $joining['uuid']),
        ];
    }

    protected function principalFor(string $deviceUuid, ?int $companyId = null): DevicePrincipal
    {
        return new DevicePrincipal($deviceUuid, $companyId ?? $this->companyId, DevicePrincipal::agentScopes(), time() + 300);
    }

    protected function policyOf(RemoteIdentity $identity): EffectivePolicy
    {
        return Services::policyResolver()->resolve($identity, 'COMPANY', $this->companyId);
    }

    /** @param array<string, mixed> $session */
    protected function share(array $session, RemoteIdentity $sharer, string $surface = 'monitor', int $width = 1920, int $height = 1080): array
    {
        return Services::sessionService()->recordShareStarted(
            $this->reload($session),
            $sharer,
            $surface,
            $this->policyOf($sharer),
            $width,
            $height,
        );
    }

    /**
     * Link, join and choose a monitor: an ACTIVE companion.
     *
     * @param  array<string, mixed> $fixture
     * @return array<string, mixed> the companion participant
     */
    protected function activeCompanion(array $fixture): array
    {
        $companions = Services::companionService();

        $link   = $companions->link($fixture['session'], $fixture['sharer'], $this->policyOf($fixture['sharer']), (string) $fixture['device']['uuid'])['link'];
        $joined = $companions->join($fixture['principal'], (string) $link['uuid']);
        $companions->selectMonitor($fixture['principal'], (string) $link['uuid'], $this->monitor(), 'AUTO_SINGLE');

        return $joined['participant'];
    }

    /** @return array<string, mixed> */
    protected function monitor(int $width = 1512, int $height = 982): array
    {
        return ['id' => 1, 'name' => 'Built-in Retina Display', 'x' => 0, 'y' => 0, 'width' => $width, 'height' => $height, 'scale' => 2];
    }

    /**
     * B asks, A grants: control through the companion.
     *
     * @param  array<string, mixed> $fixture
     * @return array<string, mixed> B's participant, GRANTED
     */
    protected function grantViewer(array $fixture): array
    {
        $control = Services::controlService();
        $session = $this->reload($fixture['session']);

        $control->request($session, $fixture['viewer'], $this->policyOf($fixture['viewer']));

        return $control->grant($session, $fixture['sharer'], (string) $fixture['viewerParticipant']['uuid'], $this->policyOf($fixture['sharer']));
    }

    /** @return array<string, mixed>|null the newest audit row for an event */
    protected function lastAudit(string $event): ?array
    {
        $row = $this->db->table('remote_audit_logs')->where('event', $event)->orderBy('id', 'DESC')->get()->getRowArray();
        if ($row !== null && is_string($row['metadata'] ?? null)) {
            $row['metadata'] = json_decode($row['metadata'], true);
        }

        return $row;
    }

    /** @return array<string, mixed> */
    protected function linkRow(string $uuid): array
    {
        return $this->db->table('remote_control_companions')->where('uuid', $uuid)->get()->getRowArray();
    }
}
