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
 * (analytics-aicountly sdk/php/). Three things are Remote's own:
 *
 *  1. EVERY SUBJECT IS A CUSTOMER. Remote is still used as a support tool, but for estate
 *     analytics the business has settled that there is no staff/customer distinction left to
 *     draw — every end user, and every user, counts as a customer. So user_kind is the constant
 *     'customer' here, exactly as it is in every other collect controller in the estate, and the
 *     identity's isSupportAgent / isPlatformAdmin flags are not consulted for analytics at all.
 *     No page view is excluded from adoption reporting any more.
 *
 *     It is stated rather than omitted: the SDK does default an absent user_kind to 'customer'
 *     (AicountlyAnalytics.php::track), so omitting it would record the same value, but stating it
 *     keeps the decision readable where it is made and independent of a re-vendor that changed
 *     that default. SessionController states it the same way for remote_session.started.
 *
 *  2. NO COMPANY IS RECORDED AT ALL, by anyone. Organisations have been wiped from Remote, so
 *     attribution is always user-wise: the event carries the subject and nothing else about who
 *     they belong to. Nothing here reads a company from the browser, and nothing reads one from
 *     the verified launch context either — there is no company to record, not a company this
 *     route declines to believe. The SDK's trackMany() strips company_id from every event in the
 *     batch unconditionally (see below), so leaving it out cannot be read as leaving it open.
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

            $verified = [
                // The subject, and nothing about any organisation — see (1) and (2).
                'user_uuid'      => $identity->uuid,
                'user_kind'      => 'customer',
                'origin_surface' => 'web',
            ];

            // $verified is the COMMON set, applied OVER each event's own fields; since SDK 1.4.0
            // trackMany also strips the caller-owned keys from every event in the batch whether
            // or not $verified supplies them. That unconditional strip is what makes the two
            // decisions above hold from the browser's side: a beacon cannot label itself anything
            // other than 'customer', and it cannot fill in the company_id we deliberately no
            // longer send.
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
