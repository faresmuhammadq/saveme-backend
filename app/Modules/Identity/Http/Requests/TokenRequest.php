<?php

namespace App\Modules\Identity\Http\Requests;

final class TokenRequest extends IdentityRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['token' => ['required', 'string', 'max:128']];
    }
}
