<?php

namespace App\Modules\Notifications\Application\Delivery;

use App\Modules\Identity\Application\Profiles\RecipientProfile;
use App\Modules\Identity\Application\Sessions\SessionStatus;
use App\Modules\Notifications\Domain\Delivery\DeliveryRecord;
use App\Modules\Notifications\Domain\Delivery\DeliveryState;
use App\Modules\Notifications\Domain\Delivery\GatewayOutcome;
use App\Modules\Notifications\Domain\Delivery\NotificationRecord;
use App\Modules\Notifications\Domain\Delivery\PushDestination;
use App\Modules\Notifications\Domain\Delivery\PushGateway;
use App\Modules\Notifications\Domain\Delivery\SkipReason;
use App\Modules\Notifications\Domain\Messages\PayloadBuilder;
use App\Modules\Notifications\Domain\Messages\PushMessage;
use App\Modules\Notifications\Domain\Ports\DeliveryRepository;
use App\Modules\Notifications\Domain\Ports\NotificationRepository;
use App\Modules\Notifications\Domain\Ports\RegistrationRepository;
use App\Modules\Notifications\Domain\Ports\TokenSealer;
use App\Modules\Notifications\Domain\Registration\PushRegistrationRecord;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one notification to every live registration of its recipient
 * (spec 023, FR-032 to FR-037, research R-9). Rerunnable: a delivery already
 * sent, pruned or failed is never sent again, so a crash or a retry repeats
 * nothing. The token is opened only here, immediately before the gateway is
 * called; logs carry the notification id, the type and the provider's code
 * and nothing else.
 */
final class NotificationDelivery
{
    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly DeliveryRepository $deliveries,
        private readonly RegistrationRepository $registrations,
        private readonly DeliveryDecider $decider,
        private readonly SessionStatus $sessions,
        private readonly RecipientProfile $recipients,
        private readonly TokenSealer $sealer,
        private readonly PushGateway $gateway,
        private readonly PushText $text,
    ) {}

    /**
     * @param  int  $attempt  1-based attempt of the job
     * @return int|null null when finished; otherwise the provider's Retry-After in seconds (0 for none) for a retry
     */
    public function deliver(string $notificationId, int $attempt, int $maxAttempts): ?int
    {
        $notification = $this->notifications->find($notificationId);

        if ($notification === null || $notification->state->isTerminal()) {
            return null;
        }

        $now = now()->toDateTimeImmutable();
        $decision = $this->decider->decide($notification, $now);

        if ($decision->isSkip() && $decision->reason !== null) {
            $this->notifications->markSkipped($notification->id, $decision->reason);

            return null;
        }

        if ($decision->isHold() && $decision->until !== null) {
            $this->notifications->markHeld($notification->id, $decision->until);

            return null;
        }

        if (! $this->gateway->delivers()) {
            $this->notifications->markSkipped($notification->id, SkipReason::Disabled);

            return null;
        }

        $this->notifications->markSending($notification->id, $now);

        $live = $this->liveRegistrations($notification);

        if ($live === [] && $this->deliveries->forNotification($notification->id) === []) {
            $this->notifications->markSkipped($notification->id, SkipReason::NoDevice);

            return null;
        }

        $retry = $this->sendToEach($notification, $live);

        if ($retry !== null && $attempt < $maxAttempts) {
            $this->notifications->markSending($notification->id, now()->toDateTimeImmutable());

            return $retry;
        }

        $this->finish($notification);

        return null;
    }

    /**
     * Gives up on a notification whose job failed for good.
     */
    public function fail(string $notificationId): void
    {
        $notification = $this->notifications->find($notificationId);

        if ($notification === null || $notification->state->isTerminal()) {
            return;
        }

        $this->finish($notification);
    }

    /**
     * Registrations whose session is still active; the others are deleted
     * rather than sent to (FR-011).
     *
     * @return list<PushRegistrationRecord>
     */
    private function liveRegistrations(NotificationRecord $notification): array
    {
        $registrations = $this->registrations->liveFor($notification->userId);
        $active = $this->sessions->activeAmong($notification->userId, array_map(fn (PushRegistrationRecord $r): string => $r->sessionId, $registrations));
        $live = [];

        foreach ($registrations as $registration) {
            if (in_array($registration->sessionId, $active, true)) {
                $live[] = $registration;

                continue;
            }

            $this->registrations->deleteById($registration->id);
        }

        return $live;
    }

    /**
     * @param  list<PushRegistrationRecord>  $live
     * @return int|null the longest Retry-After wanted (0 for none) when any send should be retried; null otherwise
     */
    private function sendToEach(NotificationRecord $notification, array $live): ?int
    {
        $deliveries = $this->deliveries->ensureFor($notification->id, array_map(fn (PushRegistrationRecord $r): string => $r->id, $live));
        $byRegistration = [];

        foreach ($live as $registration) {
            $byRegistration[$registration->id] = $registration;
        }

        $message = null;
        $retry = null;

        foreach ($deliveries as $delivery) {
            if ($delivery->state->isTerminal()) {
                continue;
            }

            $registration = $byRegistration[$delivery->registrationId] ?? null;

            if ($registration === null) {
                $this->deliveries->record($delivery->id, DeliveryState::Pruned, 'SESSION_ENDED');

                continue;
            }

            $message ??= $this->messageFor($notification);
            $outcome = $this->send($notification, $registration, $message);
            $retry = $this->apply($notification, $delivery, $registration, $outcome, $retry);
        }

        return $retry;
    }

    private function send(NotificationRecord $notification, PushRegistrationRecord $registration, PushMessage $message): GatewayOutcome
    {
        try {
            $token = $this->sealer->open($registration->tokenSealed, $registration->sessionId);

            return $this->gateway->send($message, new PushDestination($registration->id, $token));
        } catch (Throwable $failure) {
            Log::warning('push_delivery_failed', [
                'notification_id' => $notification->id,
                'type' => $notification->type->value,
                'status' => 0,
                'reason' => class_basename($failure),
            ]);

            return GatewayOutcome::failed('EXCEPTION');
        }
    }

    private function apply(NotificationRecord $notification, DeliveryRecord $delivery, PushRegistrationRecord $registration, GatewayOutcome $outcome, ?int $retry): ?int
    {
        if ($outcome->isAccepted()) {
            $this->deliveries->record($delivery->id, DeliveryState::Sent, null);

            return $retry;
        }

        if ($outcome->isInvalidToken()) {
            $this->registrations->deleteById($registration->id);
            $this->deliveries->record($delivery->id, DeliveryState::Pruned, $outcome->code);
            Log::info('push_registration_pruned', ['registration_id' => $registration->id]);

            return $retry;
        }

        if ($outcome->isRetryable()) {
            $this->deliveries->record($delivery->id, DeliveryState::Pending, $outcome->code);

            return max($retry ?? 0, $outcome->retryAfterSeconds ?? 0);
        }

        $this->deliveries->record($delivery->id, DeliveryState::Failed, $outcome->code);
        Log::warning('push_delivery_failed', [
            'notification_id' => $notification->id,
            'type' => $notification->type->value,
            'status' => 0,
            'reason' => $outcome->code ?? 'FAILED',
        ]);

        return $retry;
    }

    /**
     * Ends the notification: sent if any device accepted it, failed otherwise.
     * A delivery still waiting for a retry that will not come is failed.
     */
    private function finish(NotificationRecord $notification): void
    {
        $deliveries = $this->deliveries->forNotification($notification->id);
        $sent = false;

        foreach ($deliveries as $delivery) {
            if ($delivery->state === DeliveryState::Sent) {
                $sent = true;
            } elseif ($delivery->state === DeliveryState::Pending) {
                $this->deliveries->record($delivery->id, DeliveryState::Failed, $delivery->lastError ?? 'ABANDONED');
                Log::warning('push_delivery_failed', [
                    'notification_id' => $notification->id,
                    'type' => $notification->type->value,
                    'status' => 0,
                    'reason' => $delivery->lastError ?? 'ABANDONED',
                ]);
            }
        }

        if ($sent) {
            $this->notifications->markSent($notification->id, now()->toDateTimeImmutable());

            return;
        }

        $this->notifications->markFailed($notification->id);
    }

    private function messageFor(NotificationRecord $notification): PushMessage
    {
        $text = $this->text->render($notification->type, $this->recipients->of($notification->userId)->locale);

        return new PushMessage(
            $notification->type,
            $notification->id,
            $notification->collapseKey,
            $text['title'],
            $text['body'],
            PayloadBuilder::build($notification->type, $notification->id, $notification->data),
            $notification->type->category()->value,
            $notification->type->priority(),
        );
    }
}
