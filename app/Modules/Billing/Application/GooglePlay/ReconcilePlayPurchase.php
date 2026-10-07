<?php

namespace App\Modules\Billing\Application\GooglePlay;

use App\Modules\Billing\Application\GooglePlay\Jobs\AcknowledgePlayPurchase;
use App\Modules\Billing\Application\Plans\PlanCatalogue;
use App\Modules\Billing\Domain\GooglePlay\GatewayOutcome;
use App\Modules\Billing\Domain\GooglePlay\LinkDecision;
use App\Modules\Billing\Domain\GooglePlay\LinkOutcome;
use App\Modules\Billing\Domain\GooglePlay\PlayBillingGateway;
use App\Modules\Billing\Domain\GooglePlay\PlayProductKind;
use App\Modules\Billing\Domain\GooglePlay\PlayPurchaseRecord;
use App\Modules\Billing\Domain\GooglePlay\PlayState;
use App\Modules\Billing\Domain\GooglePlay\StoredPlayPurchase;
use App\Modules\Billing\Domain\Plans\PlanRecord;
use App\Modules\Billing\Domain\Ports\PlayAccountReferences;
use App\Modules\Billing\Domain\Ports\PlayPurchaseRepository;
use App\Modules\Billing\Domain\Ports\PlayTokenSealer;
use App\Modules\Billing\Domain\Ports\SubscriptionRepository;
use App\Modules\Billing\Domain\Ports\TokenLookup;
use App\Modules\Billing\Domain\Subscriptions\SubscriptionStatus;
use App\Modules\Identity\Application\Accounts;
use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The one place a Google read becomes local state (spec 024, R-6, R-7,
 * R-10): read the purchase, check it is for the product and a mapped plan,
 * decide who may own it, record it, write the subscription, then — after
 * commit, never before — acknowledge a linked paid purchase. Verify,
 * restore, notification processing and both passes all come through here,
 * so they cannot disagree. A per-purchase lock makes a pass and a
 * notification safe to run together (FR-047).
 */
final class ReconcilePlayPurchase
{
    public function __construct(
        private readonly PlayBillingGateway $gateway,
        private readonly PlayPurchaseRepository $purchases,
        private readonly PlayTokenSealer $sealer,
        private readonly TokenLookup $lookup,
        private readonly PlayAccountReferences $references,
        private readonly Accounts $accounts,
        private readonly PlanCatalogue $catalogue,
        private readonly SubscriptionRepository $subscriptions,
        private readonly ApplyPlayState $apply,
    ) {}

    /**
     * Re-reads a purchase this service already knows.
     */
    public function sync(string $purchaseId): SyncOutcome
    {
        $purchase = $this->purchases->find($purchaseId);

        if ($purchase === null || $purchase->tokenSealed === null) {
            return SyncOutcome::of(SyncOutcome::PURGED, $purchaseId);
        }

        return $this->reconcile($this->sealer->open($purchase->tokenSealed, 'play_purchase', $purchase->id), $purchase->kind, null, null, $purchase->productId);
    }

    /**
     * @param  ?string  $productHint  the product the caller named (verify): Google must agree
     * @param  ?string  $callerId  the signed-in account (verify, restore), or null for a notification or a pass
     * @param  ?string  $sku  the product to read a one-time purchase by, when no hint is given
     */
    public function reconcile(#[\SensitiveParameter] string $token, PlayProductKind $kind, ?string $productHint, ?string $callerId, ?string $sku = null): SyncOutcome
    {
        $lookup = $this->lookup->for($token);
        $existing = $this->purchases->findByLookup($lookup);

        if ($existing?->userId !== null) {
            if ($callerId !== null && $existing->userId !== $callerId) {
                return SyncOutcome::of(SyncOutcome::ELSEWHERE, $existing->id);
            }

            if ($this->accounts->departedAmong([$existing->userId]) !== []) {
                return SyncOutcome::of($callerId === null ? SyncOutcome::PURGED : SyncOutcome::ELSEWHERE, $existing->id);
            }
        }

        $outcome = Cache::lock('billing:play:sync:'.$lookup, 30)->block(10, fn (): SyncOutcome => $this->locked($token, $kind, $productHint, $callerId, $sku ?? $productHint, $lookup));

        return $outcome->isSynced() ? $this->acknowledgeAfterCommit($outcome, $token, $kind) : $outcome;
    }

    private function locked(#[\SensitiveParameter] string $token, PlayProductKind $kind, ?string $productHint, ?string $callerId, ?string $sku, string $lookup): SyncOutcome
    {
        $existing = $this->purchases->findByLookup($lookup);
        $read = $kind === PlayProductKind::Subscription
            ? $this->gateway->readSubscription($token)
            : ($sku === null ? GatewayOutcome::notFound() : $this->gateway->readProduct($sku, $token));
        $now = now()->toDateTimeImmutable();

        if ($read->isUnavailable()) {
            if ($existing !== null) {
                $this->recordUnreachable($existing, $now);
            }

            return SyncOutcome::of(SyncOutcome::UNAVAILABLE, $existing?->id, $read->retryAfterSeconds);
        }

        $record = $read->record;

        if ($record === null) {
            return SyncOutcome::of(SyncOutcome::NOT_FOUND, $existing?->id);
        }

        if ($productHint !== null && $record->productId !== $productHint) {
            return SyncOutcome::of(SyncOutcome::MISMATCH, $existing?->id);
        }

        if ($record->state === PlayState::PendingCanceled && $existing === null) {
            return SyncOutcome::of(SyncOutcome::CANCELED_UNPAID);
        }

        $plan = $this->catalogue->planForPlayProduct($record->productId);

        if ($plan === null) {
            if ($existing !== null) {
                PlayLog::productUnmapped($existing->id);
            }

            return SyncOutcome::of(SyncOutcome::UNMAPPED, $existing?->id);
        }

        return DB::transaction(fn (): SyncOutcome => $this->link($token, $kind, $record, $plan, $callerId, $lookup, $now));
    }

    private function link(#[\SensitiveParameter] string $token, PlayProductKind $kind, PlayPurchaseRecord $record, PlanRecord $plan, ?string $callerId, string $lookup, DateTimeImmutable $now): SyncOutcome
    {
        $id = (string) Str::uuid7();

        $this->purchases->insertIfAbsent([
            'id' => $id,
            'token_lookup' => $lookup,
            'token_sealed' => $this->sealer->seal($token, 'play_purchase', $id),
            'kind' => $kind->value,
            'product_id' => $record->productId,
            'obfuscated_ref' => $record->obfuscatedRef,
            'replaces_lookup' => $record->linkedToken === null ? null : $this->lookup->for($record->linkedToken),
            'play_state' => $record->state->value,
            'is_test' => $record->isTest,
            'base_plan_id' => $record->basePlanId,
            'purchased_at' => $record->purchasedAt,
        ]);

        $stored = $this->purchases->findByLookup($lookup);
        $row = $stored === null ? null : $this->purchases->lock($stored->id);

        if ($row === null) {
            return SyncOutcome::of(SyncOutcome::NOT_FOUND);
        }

        // Revoked is terminal (FR-025): a later read never revives the purchase, nor gets it acknowledged.
        if ($row->playState === PlayState::Revoked) {
            $record = $record->withState(PlayState::Revoked);
        }

        $referenced = $record->obfuscatedRef === null ? null : $this->references->userFor($record->obfuscatedRef);

        if ($referenced !== null && $this->accounts->departedAmong([$referenced]) !== []) {
            $referenced = null;
        }

        $replaced = $record->linkedToken === null ? null : $this->purchases->findByLookup($this->lookup->for($record->linkedToken));
        $decision = LinkDecision::decide($row->userId, $callerId, $record->obfuscatedRef !== null, $referenced, $record->linkedToken !== null, $replaced?->userId);

        $newlyLinked = false;
        $owner = null;

        switch ($decision->outcome) {
            case LinkOutcome::LinkedElsewhere:
                return SyncOutcome::of(SyncOutcome::ELSEWHERE, $row->id);
            case LinkOutcome::Link:
                if ($this->accounts->departedAmong([(string) $decision->userId]) !== []) {
                    return SyncOutcome::of(SyncOutcome::ELSEWHERE, $row->id);
                }

                if (! $this->purchases->linkTo($row->id, (string) $decision->userId, $now)) {
                    return SyncOutcome::of(SyncOutcome::ELSEWHERE, $row->id);
                }

                $newlyLinked = true;
                $owner = $decision->userId;
                break;
            case LinkOutcome::AlreadyYours:
                $owner = $decision->userId;
                break;
            case LinkOutcome::Unlinked:
                break;
        }

        $this->purchases->update($row->id, [
            'product_id' => $record->productId,
            'play_state' => $record->state->value,
            'is_test' => $record->isTest,
            'base_plan_id' => $record->basePlanId,
            'obfuscated_ref' => $record->obfuscatedRef,
            'replaces_lookup' => $record->linkedToken === null ? null : $this->lookup->for($record->linkedToken),
            'synced_at' => $now,
            'unreachable_since' => null,
        ]);

        $subscriptionId = null;

        if ($owner !== null) {
            $fresh = $this->purchases->find($row->id) ?? $row;
            $subscriptionId = $this->apply->apply($fresh, $record, $plan, $owner, $now);

            if ($newlyLinked && $replaced !== null && $replaced->userId === $owner) {
                $this->apply->expire($replaced, $owner, $now);
            }
        }

        PlayLog::verified($row->id, $newlyLinked ? 'linked' : ($owner === null ? 'unlinked' : 'synced'));

        return new SyncOutcome(SyncOutcome::SYNCED, $row->id, $owner, $newlyLinked, $record, $record->acknowledged || $row->acknowledgedAt !== null, $subscriptionId);
    }

    /**
     * Acknowledges a linked purchase Google reports paid and unacknowledged,
     * after the grant committed. A failure never undoes the grant: a job and
     * the hourly pass retry (FR-013, FR-014).
     */
    private function acknowledgeAfterCommit(SyncOutcome $outcome, #[\SensitiveParameter] string $token, PlayProductKind $kind): SyncOutcome
    {
        $record = $outcome->record;

        if ($record === null || $outcome->userId === null || $outcome->purchaseId === null || ! $record->state->isPaid()) {
            return $outcome;
        }

        if ($outcome->acknowledged) {
            $stored = $this->purchases->find($outcome->purchaseId);

            if ($stored !== null && $stored->acknowledgedAt === null) {
                $this->purchases->update($stored->id, ['acknowledged_at' => now()->toDateTimeImmutable()]);
            }

            return $outcome;
        }

        $result = $this->gateway->acknowledge($kind, $record->productId, $token);

        if ($result->isAcknowledgedAtGoogle()) {
            $this->purchases->update($outcome->purchaseId, ['acknowledged_at' => now()->toDateTimeImmutable()]);

            return new SyncOutcome(SyncOutcome::SYNCED, $outcome->purchaseId, $outcome->userId, $outcome->newlyLinked, $record, true, $outcome->subscriptionId);
        }

        AcknowledgePlayPurchase::dispatch($outcome->purchaseId)->delay(now()->addMinute());

        return $outcome;
    }

    /**
     * Google could not be read for this purchase: remember since when, alert
     * after 24 hours, and lapse by the stored dates only after 72 hours of
     * silence past the period or grace end (FR-046, A-9).
     */
    private function recordUnreachable(StoredPlayPurchase $purchase, DateTimeImmutable $now): void
    {
        $since = $purchase->unreachableSince ?? $now;

        if ($purchase->unreachableSince === null) {
            $this->purchases->update($purchase->id, ['unreachable_since' => $now]);
        }

        $silentHours = ($now->getTimestamp() - $since->getTimestamp()) / 3600;

        if ($silentHours >= 24 && $silentHours < 25) {
            PlayLog::unreachable($purchase->id);
        }

        if ($purchase->subscriptionId === null || $purchase->userId === null) {
            return;
        }

        $subscription = $this->subscriptions->find($purchase->subscriptionId);

        if ($subscription === null || ! $subscription->status->grants() || $subscription->status === SubscriptionStatus::Revoked) {
            return;
        }

        $end = $subscription->graceUntil ?? $subscription->currentPeriodEnd;
        $limit = (int) config('billing.google_play.unreachable_lapse_hours') * 3600;

        if ($end === null || $end > $now) {
            return;
        }

        $silentSince = max($since->getTimestamp(), $end->getTimestamp());

        if ($now->getTimestamp() - $silentSince >= $limit) {
            DB::transaction(fn () => $this->apply->expire($purchase, $purchase->userId, $now));
        }
    }
}
