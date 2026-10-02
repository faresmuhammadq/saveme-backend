<?php

namespace App\Modules\Wallets\Http\Requests;

use App\Http\Api\Requests\ApiRequest;

/**
 * Base for the category write bodies. The path identifier is checked before
 * the body (INVALID_ID precedes VALIDATION_FAILED, spec 011 section 6).
 */
abstract class CategoryRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        CategoryId::assert($this->route('id'));
    }

    public function categoryId(): string
    {
        return CategoryId::assert($this->route('id'));
    }
}
