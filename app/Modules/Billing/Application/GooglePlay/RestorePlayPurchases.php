<?php

namespace App\Modules\Billing\Application\GooglePlay;

use App\Modules\Billing\Domain\GooglePlay\Refusals\PlayUnavailable;
use App\Modules\Billing\Domain\GooglePlay\Refusals\ProductNotMapped;
use App\Modules\Billing\Domain\GooglePlay\Refusals\PurchaseAlreadyLinked;
use App\Modules\Billing\Domain\GooglePlay\Refusals\PurchaseNotVerified;
use App\Modules\Billing\Domain\Ports\PlayPurchaseRepository;
use App\Modules\Billing\Domain\Ports\TokenLookup;

/**
 * `POST /billing/google/restore` (spec 024, US5, FR-017 to FR-019): every
 * purchase the phone reports as owned is verified as in `VerifyPlayPurchase`
 * (one result each, in order, never throwing per item), and the caller's
 * other linked purchases are re-read from Google. It answers `unavailable`
 * for the whole call only when every Google read failed.
 */
final class RestorePlayPurchases
{
    public function __construct(
        private readonly VerifyPlayPurchase $verify,
        private readonly ReconcilePlayPurchase $reconcile,
        private readonly PlayPurchaseRepository $purchases,
        private readonly TokenLookup $lookup,
        private readonly PlayAnswers $answers,
    ) {}

    /**
     * @param  list<array{purchase_token: string, product_id: string}>  $items
     * @return array{results: list<array{index: int, product_id: string, outcome: string}>, entitlements: array<string, mixed>, subscription: array<string, mixed>}
     *
     * @throws PlayUnavailable when nothing reached Google
     */
    public function restore(string $userId, array $items, string $locale): array
    {
        $results = [];
        $attempts = 0;
        $unavailable = 0;
        $covered = [];
        $retryAfter = 60;

        foreach ($items as $index => $item) {
            $attempts++;
            $covered[$this->lookup->for($item['purchase_token'])] = true;

            try {
                $verified = $this->verify->check($userId, $item['purchase_token'], $item['product_id']);
                $outcome = $verified->isPending() ? 'pending' : ($verified->newlyLinked ? 'linked' : 'already_yours');
            } catch (PurchaseAlreadyLinked) {
                $outcome = 'linked_elsewhere';
                $attempts--;
            } catch (PurchaseNotVerified|ProductNotMapped) {
                $outcome = 'not_verified';
            } catch (PlayUnavailable $e) {
                $outcome = 'unavailable';
                $unavailable++;
                $retryAfter = $e->retryAfterSeconds ?? $retryAfter;
            }

            $results[] = ['index' => $index, 'product_id' => $item['product_id'], 'outcome' => $outcome];
        }

        foreach ($this->purchases->forUser($userId) as $purchase) {
            if (isset($covered[$purchase->tokenLookup]) || $purchase->tokenSealed === null) {
                continue;
            }

            $attempts++;

            if ($this->reconcile->sync($purchase->id)->kind === SyncOutcome::UNAVAILABLE) {
                $unavailable++;
            }
        }

        if ($attempts > 0 && $unavailable === $attempts) {
            throw new PlayUnavailable($retryAfter);
        }

        return ['results' => $results, ...$this->answers->for($userId, $locale)];
    }
}
