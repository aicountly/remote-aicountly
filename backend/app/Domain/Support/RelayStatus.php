<?php
declare(strict_types=1);

namespace App\Domain\Support;

use CodeIgniter\HTTP\Response;

/**
 * The status a relay (the Manage registry relay, the portal relay) may hand the browser for an
 * upstream answer. Adapted from books-react-app app/Services/RelayStatus.php.
 *
 * CodeIgniter's Response::setStatusCode() throws for a code it has no reason phrase for, so a relay
 * that copied the upstream status turned, say, a CDN's 520-527 or a host's 509 into an uncaught
 * exception: a 500 from Remote in place of the upstream's answer. Such a code is a gateway failure
 * as far as the browser is concerned: 502.
 */
final class RelayStatus
{
    /** @var array<int, string>|null */
    private static ?array $known = null;

    public static function forClient(int $status): int
    {
        return self::isKnown($status) ? $status : 502;
    }

    public static function isKnown(int $status): bool
    {
        if ($status < 100 || $status > 599) {
            return false;
        }
        if (self::$known === null) {
            try {
                self::$known = (array) (new \ReflectionProperty(Response::class, 'statusCodes'))->getValue();
            } catch (\Throwable) {
                self::$known = [];
            }
        }

        // Unreadable table (a framework change): pass the code on, as the relays always did.
        return self::$known === [] || isset(self::$known[$status]);
    }
}
