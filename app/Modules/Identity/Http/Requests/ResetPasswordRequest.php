<?php

namespace App\Modules\Identity\Http\Requests;

final class ResetPasswordRequest extends IdentityRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:128'],
            'password' => ['required', 'string', 'min:8', 'max:128'],
        ];
    }
}
