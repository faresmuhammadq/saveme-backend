<?php

namespace App\Modules\Identity\Http\Requests;

final class ChangePasswordRequest extends IdentityRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:128'],
            'new_password' => ['required', 'string', 'min:8', 'max:128'],
        ];
    }
}
