<?php

namespace App\Modules\Identity\Http\Requests;

/**
 * The deletion request: the password, and optionally the new owner chosen
 * for each shared wallet the person owns (spec 022, FR-035). Whether a
 * choice fits the wallet and the member is the planner's to judge.
 */
final class DeletionRequestRequest extends IdentityRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'max:128'],
            'successors' => ['sometimes', 'array', 'max:100'],
            'successors.*' => ['array'],
            'successors.*.wallet_id' => ['required', 'string', 'uuid'],
            'successors.*.member_user_id' => ['required', 'string', 'uuid'],
        ];
    }

    /**
     * @return list<array{wallet_id: string, member_user_id: string}>|null
     */
    public function successors(): ?array
    {
        if (! $this->has('successors')) {
            return null;
        }

        return array_values(array_map(
            fn (array $entry): array => ['wallet_id' => (string) $entry['wallet_id'], 'member_user_id' => (string) $entry['member_user_id']],
            (array) $this->input('successors'),
        ));
    }
}
