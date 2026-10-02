<?php

namespace App\Modules\Wallets\Http\Resources;

use App\Modules\Wallets\Application\Activity\ActivityView;

/**
 * An Activity Entry on the wire (ADR-0020), exactly as the `ActivityEntry`
 * schema: identifiers only, no amount, note, record name or address. Not a
 * syncable record.
 */
final class ActivityEntryResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(ActivityView $view): array
    {
        $entry = $view->entry;

        return [
            'id' => $entry->id,
            'wallet_id' => $entry->walletId,
            'occurred_at' => Instants::format($entry->occurredAt),
            'actor_user_id' => $entry->actorUserId,
            'actor_display_name' => $view->actorDisplayName,
            'action' => $entry->action->value,
            'subject_type' => $entry->subject->value,
            'subject_id' => $entry->subjectId,
            'role' => $entry->role?->value,
        ];
    }
}
