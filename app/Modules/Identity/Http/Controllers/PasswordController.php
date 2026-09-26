<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Api\Envelope;
use App\Modules\Identity\Application\Passwords\ChangePassword;
use App\Modules\Identity\Application\Passwords\RequestReset;
use App\Modules\Identity\Application\Passwords\ResetPassword;
use App\Modules\Identity\Http\Controllers\Concerns\ResolvesCaller;
use App\Modules\Identity\Http\Requests\ChangePasswordRequest;
use App\Modules\Identity\Http\Requests\EmailRequest;
use App\Modules\Identity\Http\Requests\ResetPasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

final class PasswordController
{
    use ResolvesCaller;

    /**
     * The same 202 whether or not the address is registered.
     */
    public function forgot(EmailRequest $request, RequestReset $reset): JsonResponse
    {
        $reset->handle($request->string('email')->toString());

        return Envelope::accepted(['status' => 'accepted']);
    }

    public function reset(ResetPasswordRequest $request, ResetPassword $reset): Response
    {
        $reset->handle($request->string('token')->toString(), $request->string('password')->toString());

        return Envelope::noContent();
    }

    public function change(ChangePasswordRequest $request, ChangePassword $change): Response
    {
        $change->handle(
            $this->callerId($request),
            $this->currentSessionId($request),
            $request->string('current_password')->toString(),
            $request->string('new_password')->toString(),
        );

        return Envelope::noContent();
    }
}
