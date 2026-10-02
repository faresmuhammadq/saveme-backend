<?php

namespace App\Modules\Sync\Application;

use App\Modules\Sync\Domain\Batch\BatchItem;
use App\Modules\Sync\Domain\Batch\BatchItemResult;
use App\Modules\Sync\Domain\Ports\ItemDispatcher;
use Illuminate\Support\Facades\Log;

/**
 * Applies a validated batch (spec 013, R-3): one item at a time in array
 * order, each as the per-record write it names, each committing on its own.
 * There is no outer transaction, so one item's clash, refusal or failure
 * never undoes or blocks another, and a repeat of the batch is safe because
 * every item is idempotent by id and version. One summary line per batch;
 * it names no ids, bodies or content.
 */
final class UpsertService
{
    public function __construct(private readonly ItemDispatcher $dispatcher) {}

    /**
     * @param  list<BatchItem>  $items
     * @return list<BatchItemResult>
     */
    public function apply(array $items): array
    {
        $results = [];

        foreach ($items as $item) {
            $answer = $this->dispatcher->dispatch($item);
            $results[] = BatchItemResult::fromResponse($item, $answer['status'], $answer['body']);
        }

        $this->log($items, $results);

        return $results;
    }

    /**
     * @param  list<BatchItem>  $applied
     * @param  list<BatchItemResult>  $results
     */
    private function log(array $applied, array $results): void
    {
        $counts = ['applied' => 0, 'conflict' => 0, 'rejected' => 0];
        $items = [];

        foreach ($results as $index => $result) {
            $counts[$result->outcome->value]++;
            $code = $result->problem['code'] ?? null;
            $items[] = [
                'type' => $result->type->value,
                'op' => $applied[$index]->operation->value,
                'outcome' => $result->outcome->value,
                'status' => $result->status,
                'code' => is_string($code) ? $code : null,
            ];
        }

        Log::info('sync_batch', ['items' => count($results), ...$counts, 'results' => $items]);
    }
}
