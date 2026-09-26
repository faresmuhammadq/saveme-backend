<?php

namespace App\Modules\Identity\Http\Requests;

final class PasswordConfirmationRequest extends IdentityRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['password' => ['required', 'string', 'max:128']];
    }
}
