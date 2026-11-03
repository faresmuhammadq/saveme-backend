<?php

namespace App\Modules\Wallets\Http\Requests;

use App\Http\Api\Rules\CalendarDate;
use App\Http\Api\Rules\IsoCurrency;
use App\Http\Api\Rules\MinorUnits;

/**
 * The whole writable transaction plus the last-seen version (spec 012,
 * `TransactionWrite`). Every member is required; the nullable ones may be
 * null. `postings`, `wallet_id`, `created_by` and the rest are unknown.
 */
final class SaveTransactionRequest extends TransactionRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kind' => ['bail', 'required', 'string', 'in:income,expense,transfer,adjustment_in,adjustment_out'],
            'date' => ['bail', 'required', new CalendarDate, 'after_or_equal:1900-01-01', 'before_or_equal:2999-12-31'],
            'amount_minor' => ['bail', 'required', new MinorUnits, 'integer', 'min:1', 'max:9007199254740991'],
            'currency' => ['bail', 'required', 'string', new IsoCurrency],
            'category_id' => ['present', 'nullable', 'string', 'uuid'],
            'counter_wallet_id' => ['present', 'nullable', 'string', 'uuid'],
            'description' => ['present', 'nullable', 'string', 'max:100'],
            'memo' => ['present', 'nullable', 'string', 'max:1000'],
            'counterparty' => ['present', 'nullable', 'string', 'max:100'],
            'cleared' => ['bail', 'required', 'boolean:strict'],
            'shared_budget_id' => ['sometimes', 'nullable', 'string', 'uuid'],
            'version' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array{kind: string, date: string, amount_minor: int, currency: string, category_id: ?string, counter_wallet_id: ?string, description: ?string, memo: ?string, counterparty: ?string, cleared: bool}
     */
    public function values(): array
    {
        $values = [
            'kind' => $this->string('kind')->toString(),
            'date' => $this->string('date')->toString(),
            'amount_minor' => $this->integer('amount_minor'),
            'currency' => $this->string('currency')->toString(),
            'category_id' => $this->nullableString('category_id'),
            'counter_wallet_id' => $this->nullableString('counter_wallet_id'),
            'description' => $this->nullableString('description'),
            'memo' => $this->nullableString('memo'),
            'counterparty' => $this->nullableString('counterparty'),
            'cleared' => $this->boolean('cleared'),
        ];

        // Absent keeps the stored link, so an older client never unlinks a transaction (ADR-0027 rule 4).
        if ($this->exists('shared_budget_id')) {
            $values['shared_budget_id'] = $this->nullableString('shared_budget_id');
        }

        return $values;
    }

    /**
     * Omitted or 0 means create.
     */
    public function version(): int
    {
        return $this->integer('version');
    }

    private function nullableString(string $key): ?string
    {
        return $this->input($key) === null ? null : $this->string($key)->toString();
    }
}
