<?php

namespace App\Modules\Wallets\Application\Sync;

use App\Modules\Wallets\Application\Categories\Dto\CategoryView;
use App\Modules\Wallets\Application\Dto\MemberView;
use App\Modules\Wallets\Application\Dto\WalletView;
use App\Modules\Wallets\Application\Recurring\Dto\OccurrenceView;
use App\Modules\Wallets\Application\Recurring\Dto\RuleView;
use App\Modules\Wallets\Application\Transactions\Dto\TransactionView;
use App\Modules\Wallets\Domain\Categories\CategoryReferenceRecord;

/**
 * How a feed row renders. Application cannot reach Http, so the Http layer
 * implements this with the resource classes the read endpoints use, which is
 * what makes a feed record identical to its read (spec 013, FR-005).
 */
interface FeedPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function wallet(WalletView $view): array;

    /**
     * @return array<string, mixed>
     */
    public function member(MemberView $view): array;

    /**
     * @return array<string, mixed>
     */
    public function categoryReference(CategoryReferenceRecord $reference): array;

    /**
     * @return array<string, mixed>
     */
    public function transaction(TransactionView $view): array;

    /**
     * @return array<string, mixed>
     */
    public function category(CategoryView $view): array;

    /**
     * @return array<string, mixed>
     */
    public function recurringRule(RuleView $view): array;

    /**
     * @return array<string, mixed>
     */
    public function occurrence(OccurrenceView $view): array;
}
