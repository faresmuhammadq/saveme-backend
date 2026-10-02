<?php

namespace App\Modules\Wallets\Infrastructure\Repositories;

use App\Modules\Wallets\Domain\Transactions\Ports\PostingRepository;
use App\Modules\Wallets\Infrastructure\Models\Posting;
use Illuminate\Support\Str;

final class EloquentPostingRepository implements PostingRepository
{
    public function replaceFor(string $transactionId, array $lines): void
    {
        $this->removeFor($transactionId);

        foreach ($lines as $line) {
            Posting::query()->forceCreate([
                'id' => (string) Str::uuid7(),
                'transaction_id' => $transactionId,
                'wallet_id' => $line->walletId,
                'category_id' => $line->categoryId,
                'amount_minor' => $line->money->amountMinor,
                'currency' => $line->money->currency->code,
            ]);
        }
    }

    public function removeFor(string $transactionId): void
    {
        Posting::query()->where('transaction_id', $transactionId)->delete();
    }
}
