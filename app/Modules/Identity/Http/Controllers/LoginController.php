<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Api\Envelope;
use App\Modules\Identity\Application\SignIn\SignIn;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Resources\AccountResource;
use App\Modules\Identity\Http\Resources\DeviceSessionResource;
use Illuminate\Http\JsonResponse;

final class LoginController
{
    public function __invoke(LoginRequest $request, SignIn $signIn): JsonResponse
    {
        $result = $signIn->handle(
            email: $request->string('email')->toString(),
            password: $request->string('password')->toString(),
            device: $request->device(),
            origin: (string) $request->ip(),
        );

        return Envelope::ok([
            'credential' => $result->credential,
            'session' => DeviceSessionResource::toArray($result->session),
            'account' => AccountResource::toArray($result->account),
            'entitlements' => [
                'plan' => $result->entitlements->planLabelKey,
                'features' => (object) $result->entitlements->features,
            ],
            'deletion_cancelled' => $result->deletionCancelled,
        ]);
    }
}
