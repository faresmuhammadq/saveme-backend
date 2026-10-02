<?php

namespace App\Modules\Sharing\Http\Controllers;

use App\Http\Api\Envelope;
use App\Modules\Sharing\Application\MemberService;
use App\Modules\Sharing\Http\Requests\VersionRequest;
use App\Modules\Sharing\Http\Resources\MemberResource;
use Illuminate\Http\JsonResponse;

/**
 * A member leaves (row S-05). Open to an unverified account.
 */
final class LeaveController
{
    public function __invoke(VersionRequest $request, MemberService $members): JsonResponse
    {
        $view = $members->leave($request->callerId(), $request->walletId(), $request->version(), MemberResource::toArray(...));

        return Envelope::ok(MemberResource::toArray($view));
    }
}
