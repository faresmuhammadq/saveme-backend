<?php

namespace App\Http\Api\Logging;

use Illuminate\Log\Logger;
use Monolog\Logger as Monolog;

/**
 * Channel tap: pushes RedactSecrets onto a channel's Monolog instance.
 * Listed under `tap` for every channel in config/logging.php.
 */
final class AttachRedaction
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if ($monolog instanceof Monolog) {
            $monolog->pushProcessor(new RedactSecrets);
        }
    }
}
