<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use RuntimeException;

/**
 * The sign-in portal could not say whether a session is valid (I-16, spec 3.8).
 *
 * Not a refusal: the caller answers 503 AUTH_UNAVAILABLE and the browser keeps
 * its sign-in and retries. Only the portal's own refusal is a 401.
 */
final class PortalUnavailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The AICOUNTLY sign-in service did not answer.');
    }
}
