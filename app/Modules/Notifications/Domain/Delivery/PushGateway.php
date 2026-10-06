<?php

namespace App\Modules\Notifications\Domain\Delivery;

use App\Modules\Notifications\Domain\Messages\PushMessage;

/**
 * The only way push leaves the application (ADR-0025 rule 1, spec 023
 * FR-038). Adapters: `fcm`, `log`, `disabled`; tests bind a fake. Replacing
 * the FCM adapter with an official SDK changes nothing outside the adapter
 * and its binding (FR-040).
 */
interface PushGateway
{
    /**
     * Whether this gateway delivers anything. The `disabled` adapter does
     * not: its notifications are recorded as skipped before it is called.
     */
    public function delivers(): bool;

    public function send(PushMessage $message, PushDestination $destination): GatewayOutcome;
}
