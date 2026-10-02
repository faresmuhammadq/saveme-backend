<?php

namespace App\Modules\Sync\Http\Requests;

use App\Modules\Sync\Domain\Batch\BatchEnvelope;
use App\Modules\Sync\Domain\Batch\BatchItem;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The batch envelope: refused whole when unusable, before anything is
 * applied (spec 013, R-4). It never looks inside an item's `data`.
 */
final class SyncBatchRequest extends FormRequest
{
    /** @var list<BatchItem> */
    private array $items = [];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    protected function prepareForValidation(): void
    {
        $this->items = BatchEnvelope::parse($this->json()->all());
    }

    /**
     * @return list<BatchItem>
     */
    public function items(): array
    {
        return $this->items;
    }
}
