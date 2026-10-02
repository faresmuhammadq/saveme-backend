<?php

namespace App\Modules\Sync\Domain\Batch;

/**
 * The answer to one item: the status and body its single write produced.
 * Exactly one of `record` and `problem` is set.
 */
final readonly class BatchItemResult
{
    /**
     * @param  array<string, mixed>|null  $record
     * @param  array<string, mixed>|null  $problem
     */
    public function __construct(
        public ItemType $type,
        public string $id,
        public ItemOutcome $outcome,
        public int $status,
        public ?array $record,
        public ?array $problem,
    ) {}

    /**
     * @param  array<string, mixed>  $body  the single write's decoded response body
     */
    public static function fromResponse(BatchItem $item, int $status, array $body): self
    {
        $problem = $status >= 400 ? $body : null;
        $code = is_string($problem['code'] ?? null) ? $problem['code'] : null;
        $outcome = ItemOutcome::fromResponse($status, $code);

        $record = $outcome === ItemOutcome::Applied && is_array($body['data'] ?? null) ? $body['data'] : null;

        return new self($item->type, $item->id, $outcome, $status, $record, $outcome === ItemOutcome::Applied ? null : $problem);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'id' => $this->id,
            'outcome' => $this->outcome->value,
            'status' => $this->status,
            'record' => $this->record,
            'problem' => $this->problem,
        ];
    }
}
