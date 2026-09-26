<?php

namespace Tests\Contract\Api\Support;

use App\Http\Api\Requests\ApiRequest;

/**
 * A fixture body for the runtime tests: one required string and one optional
 * nested object, enough to exercise field codes, pointers and unknown members.
 */
final class ProbeRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:10'],
            'device' => ['sometimes', 'array'],
            'device.platform' => ['required_with:device', 'in:android'],
        ];
    }
}
