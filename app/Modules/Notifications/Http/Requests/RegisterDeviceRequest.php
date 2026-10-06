<?php

namespace App\Modules\Notifications\Http\Requests;

use App\Http\Api\Requests\ApiRequest;
use App\Http\Api\Rules\NotBlank;

/**
 * Exactly `{ "push_token": string }`, 1 to 4096 printable ASCII characters
 * (spec 023, FR-002). Platform, app version and language are already on the
 * session and the profile, so any other member is UNKNOWN_FIELD. Absent is
 * REQUIRED; blank or too long is OUT_OF_RANGE; not a string is INVALID_TYPE.
 */
final class RegisterDeviceRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'push_token' => ['present', 'bail', new NotBlank, 'string', 'between:1,4096', 'regex:/^[\x21-\x7E]+$/'],
        ];
    }

    public function pushToken(): string
    {
        return $this->string('push_token')->toString();
    }
}
