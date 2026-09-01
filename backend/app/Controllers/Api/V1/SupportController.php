<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Domain\Support\Presenter;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * AICOUNTLY Support requests (§24).
 *
 * Note the scope handling in {@see create()}: a request that arrives with a
 * verified launch context stays attached to that company. There is no path
 * here by which a company-scoped "Need help?" becomes a personal session and
 * escapes the organisation's policy (§13).
 */
class SupportController extends BaseApiController
{
    /** `POST /support/requests` */
    public function create(): ResponseInterface
    {
        $body    = $this->body();
        $context = $this->context()->sourceContext();

        $companyId = $context?->companyId ?? $this->optionalInt($body, 'companyId');
        $scopeType = $companyId !== null ? 'AICOUNTLY_SUPPORT' : 'PERSONAL';

        $policy = $this->policyFor($scopeType, $companyId);

        $result = Services::supportRequestService()->create(
            $this->identity(),
            [
                'branchId'           => $context?->branchId ?? $this->optionalInt($body, 'branchId'),
                'financialYearId'    => $context?->financialYearId ?? $this->optionalInt($body, 'financialYearId'),
                'requestedShareMode' => $this->enum($body, 'requestedShareMode', ['SAFE_SHARE', 'BROWSER_TAB', 'APPLICATION_WINDOW', 'ENTIRE_MONITOR'], 'SAFE_SHARE'),
                'allowAudio'         => $this->boolean($body, 'allowAudio'),
                'issueSummary'       => $this->optionalString($body, 'issueSummary', 2000),
                'supportTicketId'    => $this->optionalString($body, 'supportTicketId', 64),
                'priority'           => $this->optionalString($body, 'priority', 10) ?? 'NORMAL',
                'ip'                 => $this->clientIp(),
                'userAgent'          => $this->userAgent(),
            ],
            $policy,
            $context,
        );

        return $this->created([
            'request' => Presenter::supportRequest($result['request']),
            'session' => Presenter::session($result['session']),
        ]);
    }

    /** `GET /support/requests` */
    public function index(): ResponseInterface
    {
        $result = Services::supportRequestService()->queue($this->identity(), [
            'status' => $this->request->getGet('status'),
            'mine'   => $this->request->getGet('mine') === '1',
            'limit'  => (int) ($this->request->getGet('limit') ?? 25),
            'offset' => (int) ($this->request->getGet('offset') ?? 0),
        ]);

        return $this->ok(
            array_map(static fn (array $row) => Presenter::supportRequest($row), $result['items']),
            ['total' => $result['total']],
        );
    }

    /** `GET /support/requests/{uuid}` */
    public function show(string $uuid): ResponseInterface
    {
        $request = Services::supportRequestService()->findByUuidOrFail($uuid);
        $support = Services::supportRequestService();

        // A request is visible to the person who raised it, the technician who
        // took it, and anyone who may take one. Nobody else — a company
        // administrator included (§24).
        $identity = $this->identity();
        $mayView  = (int) $request['requester_user_id'] === $identity->id
            || (int) ($request['accepted_by_user_id'] ?? 0) === $identity->id
            || $support->canHandleSupport($identity);

        if (! $mayView) {
            throw \App\Domain\Support\ApiException::notFound('That support request could not be found.');
        }

        return $this->ok(Presenter::supportRequest($request));
    }

    /** `POST /support/requests/{uuid}/accept` */
    public function accept(string $uuid): ResponseInterface
    {
        $result = Services::supportRequestService()->accept($uuid, $this->identity());

        return $this->ok([
            'request' => Presenter::supportRequest($result['request']),
            'session' => Presenter::session($result['session']),
        ]);
    }

    /** `POST /support/requests/{uuid}/decline` */
    public function decline(string $uuid): ResponseInterface
    {
        $request = Services::supportRequestService()->decline(
            $uuid,
            $this->identity(),
            $this->optionalString($this->body(), 'reason', 200),
        );

        return $this->ok(Presenter::supportRequest($request));
    }

    /** `POST /support/requests/{uuid}/cancel` */
    public function cancel(string $uuid): ResponseInterface
    {
        $request = Services::supportRequestService()->cancel($uuid, $this->identity());

        return $this->ok(Presenter::supportRequest($request));
    }
}
