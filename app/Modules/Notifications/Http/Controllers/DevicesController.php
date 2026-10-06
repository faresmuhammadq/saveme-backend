<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Api\Envelope;
use App\Http\Api\Problems\ApiRefusal;
use App\Modules\Notifications\Application\DeviceService;
use App\Modules\Notifications\Http\Requests\RegisterDeviceRequest;
use App\Modules\Notifications\Http\Resources\PushRegistrationResource;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `PUT` and `DELETE /me/devices/{id}`: the push registration of the caller's
 * own current device session (spec 023, US1). Any other id is a 404.
 */
final class DevicesController
{
    private const UUID_V7 = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function update(RegisterDeviceRequest $request, string $id, DeviceService $devices): JsonResponse
    {
        $this->requireId($id);

        $registration = $devices->register(
            $this->callerId($request),
            $this->currentSessionId($request),
            $id,
            $request->pushToken(),
        );

        return Envelope::ok(PushRegistrationResource::toArray($registration));
    }

    public function destroy(Request $request, string $id, DeviceService $devices): Response
    {
        $this->requireId($id);

        $devices->unregister($this->callerId($request), $this->currentSessionId($request), $id);

        return Envelope::noContent();
    }

    private function requireId(string $id): void
    {
        if (preg_match(self::UUID_V7, $id) !== 1) {
            throw new ApiRefusal(ErrorCode::InvalidId);
        }
    }

    private function callerId(Request $request): string
    {
        return (string) $request->user('api')?->getAuthIdentifier();
    }

    private function currentSessionId(Request $request): string
    {
        return (string) $request->user('api')?->currentAccessToken()?->getKey();
    }
}
