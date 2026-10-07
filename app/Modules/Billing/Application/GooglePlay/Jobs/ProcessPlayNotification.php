<?php

namespace App\Modules\Billing\Application\GooglePlay\Jobs;

use App\Modules\Billing\Application\GooglePlay\ApplyPlayState;
use App\Modules\Billing\Application\GooglePlay\PlayLog;
use App\Modules\Billing\Application\GooglePlay\ReconcilePlayPurchase;
use App\Modules\Billing\Application\GooglePlay\SyncOutcome;
use App\Modules\Billing\Domain\GooglePlay\PlayProductKind;
use App\Modules\Billing\Domain\GooglePlay\PlayState;
use App\Modules\Billing\Domain\Ports\PlayNotificationRepository;
use App\Modules\Billing\Domain\Ports\PlayPurchaseRepository;
use App\Modules\Billing\Domain\Ports\PlayTokenSealer;
use App\Modules\Identity\Application\Accounts;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Processes one inbox row (spec 024, R-7, FR-036 to FR-039): re-reads the
 * purchase from Google and applies its state, whatever the notification said
 * and in whatever order they arrived; a voided purchase revokes. Idempotent:
 * a terminal row is left alone and a repeat changes nothing twice. Five
 * attempts with backoff, then `failed`. Carries the inbox id only (FR-055).
 * The sealed token is erased when the row is terminal.
 */
final class ProcessPlayNotification implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $uniqueFor = 600;

    public function __construct(public readonly string $notificationId)
    {
        $this->onQueue('billing');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function uniqueId(): string
    {
        return $this->notificationId;
    }

    public function handle(
        PlayNotificationRepository $notifications,
        PlayPurchaseRepository $purchases,
        PlayTokenSealer $sealer,
        ReconcilePlayPurchase $reconcile,
        ApplyPlayState $apply,
        Accounts $accounts,
    ): void {
        $notification = $notifications->find($this->notificationId);

        if ($notification === null || $notification->isTerminal()) {
            return;
        }

        $notifications->incrementAttempts($notification->id);

        if ($notification->tokenSealed === null) {
            $notifications->markIgnored($notification->id, 'malformed');

            return;
        }

        $token = $sealer->open($notification->tokenSealed, 'play_notification', $notification->id);

        if ($notification->kind === 'voided') {
            $this->revoke($notification->id, $notification->tokenLookup, $notification->notificationType, $notifications, $purchases, $apply, $accounts);

            return;
        }

        $kind = $notification->kind === 'one_time' ? PlayProductKind::OneTime : PlayProductKind::Subscription;
        $outcome = $reconcile->reconcile($token, $kind, null, null, $notification->productId);

        match ($outcome->kind) {
            SyncOutcome::UNAVAILABLE => throw new RuntimeException('Google Play could not be read yet.'),
            SyncOutcome::PURGED, SyncOutcome::ELSEWHERE => $notifications->markIgnored($notification->id, 'purged_account'),
            default => $notifications->markProcessed($notification->id),
        };
    }

    public function failed(Throwable $exception): void
    {
        app(PlayNotificationRepository::class)->markFailed($this->notificationId);
        PlayLog::notificationFailed($this->notificationId);
    }

    private function revoke(string $notificationId, ?string $lookup, ?int $refundType, PlayNotificationRepository $notifications, PlayPurchaseRepository $purchases, ApplyPlayState $apply, Accounts $accounts): void
    {
        $purchase = $lookup === null ? null : $purchases->findByLookup($lookup);

        if ($purchase === null || $purchase->userId === null) {
            $notifications->markProcessed($notificationId);

            return;
        }

        if ($accounts->departedAmong([$purchase->userId]) !== []) {
            $notifications->markIgnored($notificationId, 'purged_account');

            return;
        }

        DB::transaction(function () use ($purchase, $refundType, $apply): void {
            $locked = app(PlayPurchaseRepository::class)->lock($purchase->id) ?? $purchase;
            $apply->revoke($locked, (string) $locked->userId, in_array($refundType, [1, 2], true) ? 'refunded' : 'revoked_by_provider', now()->toDateTimeImmutable());
            app(PlayPurchaseRepository::class)->update($locked->id, ['play_state' => PlayState::Revoked->value]);
        });

        $notifications->markProcessed($notificationId);
    }
}
