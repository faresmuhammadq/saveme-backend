<?php

namespace App\Modules\Identity\Infrastructure\Repositories;

use App\Modules\Identity\Domain\Ports\SuccessorChoiceRepository;
use App\Modules\Identity\Infrastructure\Models\DeletionSuccessorChoice;
use DateTimeImmutable;

final class EloquentSuccessorChoiceRepository implements SuccessorChoiceRepository
{
    public function replace(string $userId, array $choices, DateTimeImmutable $at): void
    {
        DeletionSuccessorChoice::query()->where('user_id', $userId)->delete();

        foreach ($choices as $choice) {
            DeletionSuccessorChoice::query()->forceCreate([
                'user_id' => $userId,
                'wallet_id' => $choice['wallet_id'],
                'member_user_id' => $choice['member_user_id'],
                'chosen_at' => $at->format('Y-m-d H:i:s.uP'),
            ]);
        }
    }

    public function forUser(string $userId): array
    {
        return DeletionSuccessorChoice::query()->where('user_id', $userId)->pluck('member_user_id', 'wallet_id')->all();
    }

    public function deleteFor(string $userId): void
    {
        DeletionSuccessorChoice::query()->where('user_id', $userId)->delete();
    }
}
