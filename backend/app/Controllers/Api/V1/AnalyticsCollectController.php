<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use Aicountly\Analytics\Analytics;
use App\Controllers\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * POST v1/remote/analytics/collect — the browser's only destination for estate analytics.
 *
 * Adapted from app/ThirdParty/AicountlyAnalytics/AnalyticsCollectController.php.reference
 * (analytics-aicountly sdk/php/). Three things are Remote's own, and the first is the one that
 * matters most:
 *
 *  1. THE STAFF RULE IS LOAD-BEARING HERE, not theoretical. Remote is a SUPPORT tool: an
 *     AICOUNTLY support agent working in it is AICOUNTLY working, not a customer adopting Remote.
 *     SessionController says the same where it records remote_session.started — "otherwise
 *     Remote's adoption figures would be our own support desk" — and this route has to make the
 *     same distinction, because its page views are mostly OURS. user_kind is 'staff' for a support
 *     agent or platform admin and 'customer' otherwise, and every report excludes 'staff' by
 *     default. This is the one product in the estate where that field is genuinely variable; in
 *     every other collect controller it is the constant 'customer'.
 *
 *     The scope-type half of SessionController's test (AICOUNTLY_SUPPORT) has no meaning here: a
 *     page view belongs to no session, so there is no scope to read. The identity's own two flags
 *     are the whole of it.
 *
 *  2. NO COMPANY COMES FROM THE BROWSER, and none is asked for. Remote takes a company from the
 *     VERIFIED LAUNCH CONTEXT only — "the company comes from the signature, never from the
 *     client (§6C, §12)" — so a beacon that named one would be asserting exactly what this
 *     product refuses to accept from a client. When the person reached Remote through a signed
 *     launch its company is recorded; when they signed in directly there is none, and the event
 *     is recorded against the subject alone rather than against a company nobody verified.
 *
 *  3. identityOrNull(), not identity(). identity() throws ApiException::unauthenticated for a
 *     guest, a device credential or no session at all, and a beacon must not turn any of those
 *     into an error. A guest in a waiting room and a registered device both record nothing: the
 *     first is not a person whose adoption means anything, the second is not a person at all.
 *
 * ANSWERS 204 ON EVERY PATH: analytics is never a reason for a screen to show an error, and a
 * status that varied would tell a foreign page whether a session was real.
 */
final class AnalyticsCollectController extends BaseApiController
{
    /** Most events accepted from one beacon; the rest of the batch is dropped, not an error. */
    private const MAX_EVENTS = 50;

    /** Largest body accepted, before JSON parsing. */
    private const MAX_BYTES = 256 * 1024;

    public function collect(): ResponseInterface
    {
        try {
            if (strlen((string) $this->request->getBody()) > self::MAX_BYTES) {
                return $this->noContent();
            }

            $body   = $this->body();
            $events = $body['events'] ?? null;
            if (! is_array($events) || $events === [] || ! array_is_list($events)) {
                return $this->noContent();
            }

            // A person, or nothing. The remote-cors filter has already decided the origin and
            // api-auth the session, so this is the identity those two left behind.
            $identity = $this->context()->identityOrNull();
            if ($identity === null || $identity->uuid === '') {
                return $this->noContent();
            }

            $isStaff = $identity->isSupportAgent || $identity->isPlatformAdmin;

            $verified = array_filter([
                // From the signature or not at all — see (2).
                'company_id' => $this->context()->sourceContext()?->companyId,
                'user_uuid'  => $identity->uuid,
                'user_kind'  => $isStaff ? 'staff' : 'customer',
                'origin_surface' => 'web',
            ], static fn ($v) => $v !== null);

            // $verified is the COMMON set, applied OVER each event's own fields; since SDK 1.4.0
            // trackMany also strips those keys from the batch, so a support agent's browser
            // cannot relabel itself 'customer' and walk into the adoption figures, and a direct
            // sign-in cannot supply a company the launch context did not verify.
            require_once APPPATH . 'ThirdParty/AicountlyAnalytics/AicountlyAnalytics.php';
            Analytics::shared(
                static fn (string $key): ?string => ($v = env($key)) === false || $v === null || $v === ''
                    ? null
                    : (string) $v
            )->trackMany(array_slice($events, 0, self::MAX_EVENTS), $verified);
        } catch (Throwable $e) {
            log_message('error', 'estate analytics collect: ' . $e->getMessage());

            return $this->noContent();
        }

        return $this->noContent();
    }
}
