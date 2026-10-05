<?php

namespace App\Modules\Identity\Infrastructure\Repositories;

use App\Modules\Identity\Domain\Ports\AuditStore;
use App\Modules\Identity\Infrastructure\Models\AuditEntry;

final class EloquentAuditStore implements AuditStore
{
    public function append(array $entry): void
    {
        AuditEntry::query()->forceCreate($entry);
    }

    public function page(array $criteria, ?array $after, bool $ascending, int $limit): array
    {
        $query = AuditEntry::query()
            ->where('occurred_at', '>=', $criteria['from'])
            ->where('occurred_at', '<', $criteria['until']);

        foreach (['event', 'actor_type', 'actor_id', 'subject_type', 'subject_id'] as $column) {
            if (isset($criteria[$column])) {
                $query->where($column, $criteria[$column]);
            }
        }

        if ($after !== null) {
            $query->whereRaw('(occurred_at, id) '.($ascending ? '>' : '<').' (?::timestamptz, ?::uuid)', [$after['occurred_at'], $after['id']]);
        }

        $direction = $ascending ? 'asc' : 'desc';

        $entries = $query->orderBy('occurred_at', $direction)->orderBy('id', $direction)->limit($limit)->get();

        return array_values($entries
            ->map(static fn (AuditEntry $entry): array => [
                'id' => $entry->id,
                'occurred_at' => $entry->occurred_at->utc()->format('Y-m-d\TH:i:s.uP'),
                'event' => $entry->event,
                'actor_type' => $entry->actor_type,
                'actor_id' => $entry->actor_id,
                'subject_type' => $entry->subject_type,
                'subject_id' => $entry->subject_id,
                'request_id' => $entry->request_id,
                'origin' => $entry->origin,
                'detail' => $entry->detail,
            ])->all());
    }
}
