<?php

namespace App\Modules\Sync\Domain\Batch;

/**
 * One validated envelope entry: the per-record write it names and its body.
 */
final readonly class BatchItem
{
    /**
     * @param  array<string, mixed>  $data  the single write's body, verbatim
     */
    public function __construct(
        public ItemType $type,
        public ItemOperation $operation,
        public string $id,
        public ?string $walletId,
        public array $data,
    ) {}
}
