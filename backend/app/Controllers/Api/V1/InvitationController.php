<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Domain\Support\Presenter;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * Invitation links (§6F).
 *
 * The creation response is the **only** place the secret ever appears. It is
 * not stored, not logged, not in the audit trail and not returned by any later
 * read — so if the host loses the link, the answer is to issue a new one.
 */
class InvitationController extends BaseApiController
{
    /** `POST /sessions/{uuid}/invitations` */
    public function create(string $uuid): ResponseInterface
    {
        $session  = Services::sessionService()->findForUser($uuid, $this->identity());
        $body     = $this->body();

        $policy = Services::policyResolver()->resolve(
            $this->identity(),
            (string) $session['scope_type'],
            $session['company_id'] !== null ? (int) $session['company_id'] : null,
        );

        $result = Services::invitationService()->create(
            $session,
            $this->identity(),
            $policy,
            $this->enum($body, 'invitationType', ['INTERNAL', 'EXTERNAL_GUEST', 'SUPPORT'], 'INTERNAL'),
            $this->optionalString($body, 'inviteeEmail', 190),
            $this->optionalInt($body, 'expiryMinutes'),
        );

        return $this->created([
            'invitation' => Presenter::invitation($result['invitation']),
            'url'        => $result['url'],
        ]);
    }

    /** `GET /sessions/{uuid}/invitations` */
    public function index(string $uuid): ResponseInterface
    {
        $session = Services::sessionService()->findForUser($uuid, $this->identity());

        return $this->ok(array_map(
            static fn (array $row) => Presenter::invitation($row),
            Services::invitationService()->forSession((int) $session['id']),
        ));
    }

    /** `DELETE /sessions/{uuid}/invitations/{invitationUuid}` */
    public function revoke(string $uuid, string $invitationUuid): ResponseInterface
    {
        $session = Services::sessionService()->findForUser($uuid, $this->identity());

        Services::invitationService()->revoke($session, $invitationUuid, $this->identity());

        return $this->noContent();
    }
}
