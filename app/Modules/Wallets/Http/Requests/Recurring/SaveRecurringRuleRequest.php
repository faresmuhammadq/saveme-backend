<?php

namespace App\Modules\Wallets\Http\Requests\Recurring;

use App\Http\Api\Rules\CalendarDate;
use App\Http\Api\Rules\IsoCurrency;
use App\Http\Api\Rules\MinorUnits;

/**
 * The whole writable recurring rule plus the last-seen version (spec 014,
 * `RecurringRuleWrite`). Every member is required; the nullable ones may be
 * null. `timezone`, `created_by`, `wallet_id` and the rest are unknown.
 *
 * @phpstan-import-type Values from \App\Modules\Wallets\Domain\Recurring\RecurringRuleRecord
 */
final class SaveRecurringRuleRequest extends RuleRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'preset' => ['bail', 'required', 'string', 'in:custom,gameya,installment'],
            'status' => ['bail', 'required', 'string', 'in:active,paused,ended'],
            'kind' => ['bail', 'required', 'string', 'in:income,expense,transfer'],
            'amount_minor' => ['bail', 'required', new MinorUnits, 'integer', 'min:1', 'max:9007199254740991'],
            'amount_basis' => ['bail', 'required', 'string', 'in:per_occurrence,total'],
            'currency' => ['bail', 'required', 'string', new IsoCurrency],
            'category_id' => ['present', 'nullable', 'string', 'uuid'],
            'counter_wallet_id' => ['present', 'nullable', 'string', 'uuid'],
            'description' => ['present', 'nullable', 'string', 'max:100'],
            'memo' => ['present', 'nullable', 'string', 'max:1000'],
            'counterparty' => ['present', 'nullable', 'string', 'max:100'],
            'auto_confirm' => ['bail', 'required', 'boolean:strict'],
            'frequency' => ['bail', 'required', 'string', 'in:daily,weekly,monthly,yearly'],
            'interval' => ['bail', 'required', 'integer', 'min:1', 'max:365'],
            'anchor_day' => ['present', 'nullable', 'integer', 'min:1', 'max:31'],
            'start_date' => ['bail', 'required', new CalendarDate, 'after_or_equal:1900-01-01', 'before_or_equal:2999-12-31'],
            'end_date' => ['present', 'nullable', new CalendarDate, 'after_or_equal:1900-01-01', 'before_or_equal:2999-12-31'],
            'occurrence_count' => ['present', 'nullable', 'integer', 'min:1', 'max:1000'],
            'payout_round' => ['present', 'nullable', 'integer', 'min:1'],
            'payout_category_id' => ['present', 'nullable', 'string', 'uuid'],
            'version' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * @return Values
     */
    public function values(): array
    {
        return [
            'preset' => $this->string('preset')->toString(),
            'status' => $this->string('status')->toString(),
            'kind' => $this->string('kind')->toString(),
            'amount_minor' => $this->integer('amount_minor'),
            'amount_basis' => $this->string('amount_basis')->toString(),
            'currency' => $this->string('currency')->toString(),
            'category_id' => $this->nullableString('category_id'),
            'counter_wallet_id' => $this->nullableString('counter_wallet_id'),
            'description' => $this->nullableString('description'),
            'memo' => $this->nullableString('memo'),
            'counterparty' => $this->nullableString('counterparty'),
            'auto_confirm' => $this->boolean('auto_confirm'),
            'frequency' => $this->string('frequency')->toString(),
            'interval' => $this->integer('interval'),
            'anchor_day' => $this->nullableInteger('anchor_day'),
            'start_date' => $this->string('start_date')->toString(),
            'end_date' => $this->nullableString('end_date'),
            'occurrence_count' => $this->nullableInteger('occurrence_count'),
            'payout_round' => $this->nullableInteger('payout_round'),
            'payout_category_id' => $this->nullableString('payout_category_id'),
        ];
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

    private function nullableInteger(string $key): ?int
    {
        return $this->input($key) === null ? null : $this->integer($key);
    }
}
