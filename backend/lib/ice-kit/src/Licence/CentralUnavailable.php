<?php

declare(strict_types=1);

namespace Aicountly\Ice\Licence;

/** The central licensing backend could not give a usable answer. Never carries a secret, URL or header. */
final class CentralUnavailable extends \RuntimeException
{
}
