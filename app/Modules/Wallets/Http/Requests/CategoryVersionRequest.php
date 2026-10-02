<?php

namespace App\Modules\Wallets\Http\Requests;

/**
 * Archive and delete carry nothing but the last-seen version, in a JSON body
 * (spec 010, R-5). A missing body is REQUIRED at /version, not 415.
 */
final class CategoryVersionRequest extends CategoryRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
        ];
    }

    public function version(): int
    {
        return $this->integer('version');
    }
}
