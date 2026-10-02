<?php

namespace App\Modules\Wallets\Http;

use App\Modules\Wallets\Application\Categories\Dto\CategoryView;
use App\Modules\Wallets\Application\Dto\MemberView;
use App\Modules\Wallets\Application\Dto\WalletView;
use App\Modules\Wallets\Application\Recurring\Dto\OccurrenceView;
use App\Modules\Wallets\Application\Recurring\Dto\RuleView;
use App\Modules\Wallets\Application\Sync\FeedPresenter;
use App\Modules\Wallets\Application\Transactions\Dto\TransactionView;
use App\Modules\Wallets\Domain\Categories\CategoryReferenceRecord;
use App\Modules\Wallets\Http\Resources\CategoryReferenceResource;
use App\Modules\Wallets\Http\Resources\CategoryResource;
use App\Modules\Wallets\Http\Resources\OccurrenceResource;
use App\Modules\Wallets\Http\Resources\RecurringRuleResource;
use App\Modules\Wallets\Http\Resources\TransactionResource;
use App\Modules\Wallets\Http\Resources\WalletMemberResource;
use App\Modules\Wallets\Http\Resources\WalletResource;

/**
 * Feed rows rendered by the same resource classes as the read endpoints.
 */
final class ResourceFeedPresenter implements FeedPresenter
{
    public function wallet(WalletView $view): array
    {
        return WalletResource::toArray($view);
    }

    public function member(MemberView $view): array
    {
        return WalletMemberResource::toArray($view);
    }

    public function categoryReference(CategoryReferenceRecord $reference): array
    {
        return CategoryReferenceResource::toArray($reference);
    }

    public function transaction(TransactionView $view): array
    {
        return TransactionResource::toArray($view);
    }

    public function category(CategoryView $view): array
    {
        return CategoryResource::toArray($view);
    }

    public function recurringRule(RuleView $view): array
    {
        return RecurringRuleResource::toArray($view);
    }

    public function occurrence(OccurrenceView $view): array
    {
        return OccurrenceResource::toArray($view);
    }
}
