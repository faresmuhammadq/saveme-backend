<?php

namespace App\Modules\Identity\Http\Requests;

final class RegisterRequest extends IdentityRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'min:8', 'max:128'],
            'display_name' => ['sometimes', 'string', 'min:1', 'max:100'],
            'timezone' => ['sometimes', 'string', 'timezone:all'],
        ];
    }
}
