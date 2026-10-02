<?php

namespace App\Modules\Sync\Domain\Batch;

/**
 * How an item ended, decided from what its single write answered (spec 013,
 * data-model): 200 and 201 are applied; 409 VERSION_CONFLICT is a conflict
 * the client resolves; anything else is rejected.
 */
enum ItemOutcome: string
{
    case Applied = 'applied';
    case Conflict = 'conflict';
    case Rejected = 'rejected';

    public static function fromResponse(int $status, ?string $problemCode): self
    {
        return match (true) {
            $status === 200, $status === 201 => self::Applied,
            $status === 409 && $problemCode === 'VERSION_CONFLICT' => self::Conflict,
            default => self::Rejected,
        };
    }
}
