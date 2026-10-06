<?php

namespace App\Modules\Notifications\Infrastructure\Push;

use App\Modules\Notifications\Domain\Delivery\GatewayOutcome;
use App\Modules\Notifications\Domain\Delivery\PushDestination;
use App\Modules\Notifications\Domain\Delivery\PushGateway;
use App\Modules\Notifications\Domain\Messages\PushMessage;

/**
 * Sends nothing and pretends nothing: every notification is recorded as
 * skipped (`disabled`) before this is called (spec 023, FR-042). Allowed in
 * every environment, so production can ship before Firebase credentials exist.
 */
final class DisabledPushGateway implements PushGateway
{
    public function delivers(): bool
    {
        return false;
    }

    public function send(PushMessage $message, PushDestination $destination): GatewayOutcome
    {
        return GatewayOutcome::failed('DISABLED');
    }
}
