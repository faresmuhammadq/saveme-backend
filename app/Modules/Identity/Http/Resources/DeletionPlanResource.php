<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Application\Deletion\DeletionPlan;
use App\Modules\Identity\Application\Deletion\DeletionPlanEntry;

/**
 * The `DeletionPlan` schema, and the `successors` list the deletion request
 * answers with.
 */
final class DeletionPlanResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(DeletionPlan $plan): array
    {
        return [
            'state' => $plan->state,
            'deletion_requested_at' => Instants::format($plan->requestedAt),
            'deletion_scheduled_for' => Instants::format($plan->scheduledFor),
            'successors' => self::successors($plan),
        ];
    }

    /**
     * @return list<array{wallet_id: string, successor_user_id: string, reason: string, choice_expected: bool}>
     */
    public static function successors(DeletionPlan $plan): array
    {
        return array_map(fn (DeletionPlanEntry $entry): array => [
            'wallet_id' => $entry->walletId,
            'successor_user_id' => $entry->successor->userId,
            'reason' => $entry->successor->reason->value,
            'choice_expected' => $entry->choiceExpected,
        ], $plan->successors);
    }
}
