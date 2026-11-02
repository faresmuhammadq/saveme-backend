<?php

namespace App\Modules\Sharing\Http\Requests;

/**
 * The create body of a Shared Budget invitation: nothing but the optional
 * version (omitted or 0 means create). v1 invites by link, so there is no
 * address and no role to send.
 */
final class UpsertSharedBudgetInvitationRequest extends SharingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'version' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function budgetId(): string
    {
        return $this->walletId();
    }

    public function version(): int
    {
        return $this->integer('version');
    }

    public function callerId(): string
    {
        $user = $this->user() ?? throw new \RuntimeException('Route is missing auth:api.');

        return (string) $user->getAuthIdentifier();
    }
}
