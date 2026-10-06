<?php

namespace App\Modules\Notifications\Infrastructure\Push;

use App\Modules\Notifications\Domain\Delivery\GatewayOutcome;
use App\Modules\Notifications\Domain\Delivery\PushDestination;
use App\Modules\Notifications\Domain\Delivery\PushGateway;
use App\Modules\Notifications\Domain\Messages\PushMessage;
use Illuminate\Support\Facades\Log;

/**
 * Local development: writes what would have been sent, and sends nothing.
 * Logs the message type, category, notification id and the data identifiers;
 * never the token, the title or the body. Refused at boot outside local and
 * testing (spec 023, FR-041).
 */
final class LogPushGateway implements PushGateway
{
    public function delivers(): bool
    {
        return true;
    }

    public function send(PushMessage $message, PushDestination $destination): GatewayOutcome
    {
        Log::info('push_logged', [
            'type' => $message->type->value,
            'category' => $message->channel,
            'notification_id' => $message->notificationId,
            'data' => array_diff_key($message->data, array_flip(['schema', 'type', 'category', 'notification_id'])),
        ]);

        return GatewayOutcome::accepted();
    }
}
