<?php

namespace App\Modules\Budgeting\Http\SharedBudgets\Requests;

/**
 * The member to hand the budget to, plus the budget's last-seen version.
 */
final class TransferOwnershipRequest extends SharedBudgetRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['bail', 'required', 'string', 'uuid'],
            'version' => ['required', 'integer', 'min:1'],
        ];
    }

    public function newOwnerId(): string
    {
        return $this->string('user_id')->toString();
    }
}
