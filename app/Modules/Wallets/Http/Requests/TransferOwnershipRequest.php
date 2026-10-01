<?php

namespace App\Modules\Wallets\Http\Requests;

/**
 * The member to hand ownership to, and the last-seen version.
 */
final class TransferOwnershipRequest extends WalletRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'string', 'uuid'],
            'version' => ['required', 'integer', 'min:1'],
        ];
    }

    public function targetUserId(): string
    {
        return $this->string('user_id')->toString();
    }

    public function version(): int
    {
        return $this->integer('version');
    }
}
