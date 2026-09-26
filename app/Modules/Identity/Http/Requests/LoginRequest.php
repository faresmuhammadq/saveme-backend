<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Domain\Sessions\DeviceDescription;

final class LoginRequest extends IdentityRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:128'],
            'device' => ['required', 'array'],
            'device.session_id' => ['required', 'string', 'regex:/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/'],
            'device.device_id' => ['required', 'string', 'regex:/^[A-Za-z0-9._:-]{1,64}$/'],
            'device.name' => ['required', 'string', 'min:1', 'max:100'],
            'device.platform' => ['required', 'string', 'in:android'],
            'device.app_version' => ['required', 'string', 'regex:/^\d+\.\d+\.\d+$/'],
        ];
    }

    public function device(): DeviceDescription
    {
        return new DeviceDescription(
            sessionId: $this->string('device.session_id')->toString(),
            deviceId: $this->string('device.device_id')->toString(),
            name: $this->string('device.name')->toString(),
            platform: $this->string('device.platform')->toString(),
            appVersion: $this->string('device.app_version')->toString(),
        );
    }
}
