<?php

namespace App\Modules\Wallets\Http\Requests;

use App\Http\Api\Rules\NotBlank;

/**
 * The whole writable category plus the last-seen version (spec 011, FR-001).
 * A name that is blank or longer than 50 is OUT_OF_RANGE; NotBlank runs
 * first because the pipeline has already turned a blank string into null.
 */
final class SaveCategoryRequest extends CategoryRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kind' => ['bail', 'required', 'string', 'in:income,expense'],
            'name' => ['bail', 'present', new NotBlank, 'string', 'between:1,50'],
            'icon' => ['present', 'nullable', 'string', 'regex:/^[a-z0-9_]{1,40}$/'],
            'color' => ['present', 'nullable', 'string', 'regex:/^#[0-9A-F]{6}$/'],
            'version' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array{kind: string, name: string, icon: ?string, color: ?string}
     */
    public function values(): array
    {
        return [
            'kind' => $this->string('kind')->toString(),
            'name' => $this->string('name')->toString(),
            'icon' => $this->input('icon') === null ? null : $this->string('icon')->toString(),
            'color' => $this->input('color') === null ? null : $this->string('color')->toString(),
        ];
    }

    /**
     * Omitted or 0 means create.
     */
    public function version(): int
    {
        return $this->integer('version');
    }
}
