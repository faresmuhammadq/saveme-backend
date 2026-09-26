<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Api\Envelope;
use App\Modules\Identity\Application\Registration\RegisterAccount;
use App\Modules\Identity\Http\Requests\RegisterRequest;
use Illuminate\Http\JsonResponse;

final class RegisterController
{
    /**
     * The same 202 whatever happened behind it (security baseline, Uniform answers).
     */
    public function __invoke(RegisterRequest $request, RegisterAccount $register): JsonResponse
    {
        $register->handle(
            email: $request->string('email')->toString(),
            password: $request->string('password')->toString(),
            displayName: $request->has('display_name') ? $request->string('display_name')->toString() : null,
            timezone: $request->has('timezone') ? $request->string('timezone')->toString() : null,
            locale: app()->getLocale(),
        );

        return Envelope::accepted(['status' => 'accepted']);
    }
}
