<?php

namespace App\Modules\Wallets\Infrastructure\Repositories;

use App\Modules\Wallets\Domain\Categories\CategoryKind;
use App\Modules\Wallets\Domain\Categories\CategoryRecord;
use App\Modules\Wallets\Domain\Categories\CategoryReferenceRecord;
use App\Modules\Wallets\Domain\Categories\Ports\CategoryReferenceRepository;
use App\Modules\Wallets\Infrastructure\Models\CategoryReference;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

final class EloquentCategoryReferenceRepository implements CategoryReferenceRepository
{
    public function liveFor(string $walletId, string $categoryId): ?CategoryReferenceRecord
    {
        $reference = CategoryReference::query()
            ->where('wallet_id', $walletId)
            ->where('category_id', $categoryId)
            ->whereNull('deleted_at')
            ->first();

        return $reference === null ? null : self::record($reference);
    }

    public function anyFor(string $walletId, string $categoryId): ?CategoryReferenceRecord
    {
        $reference = CategoryReference::query()
            ->where('wallet_id', $walletId)
            ->where('category_id', $categoryId)
            ->orderByRaw('deleted_at IS NULL DESC, deleted_at DESC')
            ->first();

        return $reference === null ? null : self::record($reference);
    }

    public function liveWalletIdsFor(string $categoryId): array
    {
        return array_values(CategoryReference::query()
            ->where('category_id', $categoryId)
            ->whereNull('deleted_at')
            ->orderBy('wallet_id')
            ->pluck('wallet_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all());
    }

    public function insert(string $walletId, CategoryRecord $category, int $changeSeq): CategoryReferenceRecord
    {
        $reference = CategoryReference::query()->create([
            'wallet_id' => $walletId,
            'category_id' => $category->id,
            'owner_user_id' => $category->ownerId,
            'kind' => $category->kind->value,
            'name' => $category->name,
            'icon' => $category->icon,
            'color' => $category->color,
            'version' => 1,
            'change_seq' => $changeSeq,
        ]);

        return self::record($reference->refresh());
    }

    public function refresh(string $referenceId, CategoryRecord $category, int $changeSeq): void
    {
        CategoryReference::query()->whereKey($referenceId)->toBase()->update([
            'kind' => $category->kind->value,
            'name' => $category->name,
            'icon' => $category->icon,
            'color' => $category->color,
            'deleted_at' => null,
            'version' => DB::raw('version + 1'),
            'change_seq' => $changeSeq,
            'updated_at' => now()->format('Y-m-d H:i:s.uP'),
        ]);
    }

    public function tombstone(string $referenceId, DateTimeImmutable $at, int $changeSeq): void
    {
        CategoryReference::query()->whereKey($referenceId)->toBase()->update([
            'deleted_at' => self::instant($at),
            'version' => DB::raw('version + 1'),
            'change_seq' => $changeSeq,
            'updated_at' => self::instant($at),
        ]);
    }

    public function tombstoneAllInWallet(string $walletId, DateTimeImmutable $at, Closure $nextChangeSeq): void
    {
        $ids = CategoryReference::query()->where('wallet_id', $walletId)->whereNull('deleted_at')
            ->orderBy('created_at')->orderBy('id')->pluck('id');

        foreach ($ids as $id) {
            $this->tombstone((string) $id, $at, $nextChangeSeq());
        }
    }

    public function listLive(string $walletId, ?array $after, int $limit): array
    {
        $query = CategoryReference::query()->where('wallet_id', $walletId)->whereNull('deleted_at');

        if ($after !== null) {
            $query->whereRaw('(created_at, id) > (?::timestamptz, ?::uuid)', [$after['value'], $after['id']]);
        }

        return array_values($query->orderBy('created_at')->orderBy('id')->limit($limit + 1)->get()
            ->map(fn (CategoryReference $reference): CategoryReferenceRecord => self::record($reference))
            ->all());
    }

    private static function instant(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }

    public static function record(CategoryReference $reference): CategoryReferenceRecord
    {
        return new CategoryReferenceRecord(
            id: $reference->id,
            walletId: $reference->wallet_id,
            categoryId: $reference->category_id,
            ownerUserId: $reference->owner_user_id,
            kind: CategoryKind::from($reference->kind),
            name: $reference->name,
            icon: $reference->icon,
            color: $reference->color,
            version: $reference->version,
            changeSeq: $reference->change_seq,
            createdAt: $reference->created_at->toDateTimeImmutable(),
            updatedAt: $reference->updated_at->toDateTimeImmutable(),
            deletedAt: $reference->deleted_at?->toDateTimeImmutable(),
        );
    }
}
