<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Domain\Policy\PermissionCatalog;
use App\Domain\Support\ApiException;
use App\Domain\Support\Presenter;
use App\Libraries\CompanyLifecycle;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * `GET /api/v1/remote/bootstrap` — everything the app needs to render its first
 * screen, in one request (§65).
 *
 * The alternative — user, then companies, then policy, then metrics, then
 * support count — is five round trips before anything appears. This is one, and
 * it is also the only place the frontend learns what it is allowed to do: the
 * permissions and policy in this response are what the UI gates on, and the
 * same values the API re-checks on every subsequent call (§9).
 */
class BootstrapController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $identity  = $this->identity();
        $context   = $this->context()->sourceContext();
        $directory = Services::platformDirectory();
        $sessions  = Services::sessionService();
        $support   = Services::supportRequestService();

        $companies = $directory->companiesFor($identity);

        // Which scope should the app open in? A verified launch context decides
        // it; otherwise the user chooses. Never a query parameter (§6C).
        $scopeType = 'PERSONAL';
        $companyId = null;

        if ($context !== null && $context->companyId !== null) {
            $scopeType = $context->supportTicketId !== null ? 'AICOUNTLY_SUPPORT' : 'COMPANY';
            $companyId = $context->companyId;
        }

        $policy = $this->policyFor($scopeType, $companyId);

        $companyWide = [];
        foreach ($companies as $company) {
            try {
                if (Services::policyResolver()
                    ->resolve($identity, 'COMPANY', $company['companyId'])
                    ->can(PermissionCatalog::SESSION_HISTORY_COMPANY)) {
                    $companyWide[] = $company['companyId'];
                }
            } catch (ApiException) {
                // Membership vanished between reads; nothing to report.
            }
        }

        $metrics = $sessions->dashboardMetrics($identity, $companyWide);
        $metrics['pendingSupportRequests'] = $support->pendingCount($identity);

        $recent = $sessions->history($identity, ['limit' => 5]);

        $config = Services::remoteConfig();

        return $this->ok([
            'user' => [
                'uuid'           => $identity->uuid,
                'displayName'    => $identity->displayName,
                'email'          => $identity->email,
                'isSupportAgent' => $support->canHandleSupport($identity),
            ],
            'companies' => $companies,
            'activeScope' => [
                'scopeType' => $scopeType,
                'companyId' => $companyId,
                // Manage's company lifecycle as Remote enforces it (active | restricted | purge_pending |
                // purged, restricted_until): the SPA's read-only banner.
                'lifecycle' => $companyId !== null ? CompanyLifecycle::lifecycle($companyId) : null,
            ],
            'launchContext' => $context?->toArray(),
            'policy'  => $policy->toArray(),
            'metrics' => $metrics,
            'recentSessions' => array_map(
                static fn (array $row) => Presenter::session($row),
                $recent['items'],
            ),
            'features' => [
                'fileTransfer' => $config->featureFileTransfer,
                'recording'    => $config->featureRecording,
                'externalGuest' => $config->featureExternalGuest,
                'safeShare'    => $config->featureSafeShare,
                'microphone'   => $config->featureMicrophone,
                'multiViewer'  => $config->featureMultiViewer,
                // AICOUNTLY Remote for Windows (remote.allowDesktopAgent): whether the web
                // offers the download where it needs the app, or says it is coming soon.
                'desktopAgent' => $config->featureDesktopAgent,
                'fileTransferMaxBytes' => $config->fileTransferMaxBytes,
            ],
            'realtime' => [
                'signallingConfigured' => Services::signallingTokenService()->isConfigured(),
                'relayAvailable'       => Services::iceConfigService()->hasRelay(),
            ],
        ]);
    }

    /**
     * `GET /api/v1/remote/policy/effective?scopeType=&companyId=`
     *
     * Recomputed whenever the user switches organisation in the header, so the
     * sharing options on screen always match the organisation they are in.
     */
    public function effectivePolicy(): ResponseInterface
    {
        $scopeType = strtoupper((string) ($this->request->getGet('scopeType') ?? 'PERSONAL'));

        if (! in_array($scopeType, ['PERSONAL', 'COMPANY', 'AICOUNTLY_SUPPORT'], true)) {
            throw ApiException::badRequest('SCOPE_INVALID', 'That is not a Remote session scope.');
        }

        $companyIdRaw = $this->request->getGet('companyId');
        $companyId    = is_numeric($companyIdRaw) ? (int) $companyIdRaw : null;

        return $this->ok($this->policyFor($scopeType, $companyId)->toArray() + [
            // Manage's company lifecycle for the organisation switched to: the SPA's read-only banner.
            'lifecycle' => $companyId !== null ? CompanyLifecycle::lifecycle($companyId) : null,
        ]);
    }
}
