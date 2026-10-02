<?php

namespace App\Modules\Wallets\Http\Requests\Recurring;

use App\Http\Api\Rules\CalendarDate;
use App\Http\Api\Rules\MinorUnits;
use App\Http\Api\Rules\UuidV7;

/**
 * The whole writable occurrence plus the last-seen version (spec 014,
 * `OccurrenceWrite`). Every member is required; the nullable ones may be
 * null. `kind`, `scheduled_date`, `edited` and the rest are unknown.
 *
 * @phpstan-import-type Values from \App\Modules\Wallets\Domain\Recurring\OccurrenceRecord
 */
final class SaveOccurrenceRequest extends OccurrenceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['bail', 'required', 'string', 'in:scheduled,confirmed,skipped'],
            'date' => ['bail', 'required', new CalendarDate, 'after_or_equal:1900-01-01', 'before_or_equal:2999-12-31'],
            'amount_minor' => ['bail', 'required', new MinorUnits, 'integer', 'min:1', 'max:9007199254740991'],
            'category_id' => ['present', 'nullable', 'string', 'uuid'],
            'description' => ['present', 'nullable', 'string', 'max:100'],
            'memo' => ['present', 'nullable', 'string', 'max:1000'],
            'counterparty' => ['present', 'nullable', 'string', 'max:100'],
            'transaction_id' => ['present', 'nullable', 'string', new UuidV7],
            'version' => ['bail', 'required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return Values
     */
    public function values(): array
    {
        return [
            'status' => $this->string('status')->toString(),
            'date' => $this->string('date')->toString(),
            'amount_minor' => $this->integer('amount_minor'),
            'category_id' => $this->nullableString('category_id'),
            'description' => $this->nullableString('description'),
            'memo' => $this->nullableString('memo'),
            'counterparty' => $this->nullableString('counterparty'),
            'transaction_id' => $this->nullableString('transaction_id'),
        ];
    }

    public function version(): int
    {
        return $this->integer('version');
    }

    private function nullableString(string $key): ?string
    {
        return $this->input($key) === null ? null : $this->string($key)->toString();
    }
}
