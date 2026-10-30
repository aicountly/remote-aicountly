<?php

namespace App\Services;

/**
 * Estate analytics — a one-line wrapper around the copy-don't-edit SDK in
 * app/ThirdParty/AicountlyAnalytics/ (analytics-aicountly sdk/README.md).
 *
 * TEMPLATE for CodeIgniter 4 products. Copy unchanged from
 * analytics-aicountly sdk/php/templates/. If the product keeps shared classes in app/Libraries/
 * rather than app/Services/, change only the namespace on the line above.
 *
 * AICOUNTLY's own view of how the estate is used, for product, marketing and sales. Not the same
 * thing as Manage usage metering, which tells a company what it consumed from AI and third-party
 * vendors. Same shape, no shared data.
 *
 * Nothing here can fail a request: every call is wrapped, the SDK never throws, and with
 * AICOUNTLY_ANALYTICS_ENABLED unset nothing is recorded at all.
 *
 * RECORD POST-COMMIT. A spooled event is a file write and does not roll back with the database
 * transaction that produced it, so calling this inside a transaction records things that may
 * never have happened. Call it from the controller, after the service has returned.
 */
final class EstateAnalytics
{
    /**
     * Record one event. The name must be registered in the Analytics tracking plan and allowed
     * for this product, or the server refuses it (analytics-aicountly docs/04_event_taxonomy.md).
     *
     * @param array<string,mixed> $attributes company_id, user_uuid, properties, …
     */
    public static function track(string $event, array $attributes = []): void
    {
        try {
            require_once APPPATH . 'ThirdParty/AicountlyAnalytics/AicountlyAnalytics.php';

            \Aicountly\Analytics\Analytics::shared(
                static fn (string $key): ?string => ($v = env($key)) === false || $v === null || $v === ''
                    ? null
                    : (string) $v
            )->track($event, $attributes);
        } catch (\Throwable $e) {
            log_message('error', 'estate analytics: ' . $e->getMessage());
        }
    }
}
