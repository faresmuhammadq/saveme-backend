<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Api\Envelope;
use App\Modules\Identity\Application\Verification\ResendVerification;
use App\Modules\Identity\Application\Verification\VerifyAddress;
use App\Modules\Identity\Http\Controllers\Concerns\ResolvesCaller;
use App\Modules\Identity\Http\Requests\TokenRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class VerificationController
{
    use ResolvesCaller;

    public function verify(TokenRequest $request, VerifyAddress $verify): JsonResponse
    {
        $verify->handle($request->string('token')->toString());

        return Envelope::ok(['email_verified' => true]);
    }

    public function resend(Request $request, ResendVerification $resend): JsonResponse
    {
        $resend->handle($this->callerId($request));

        return Envelope::accepted(['status' => 'accepted']);
    }
}
