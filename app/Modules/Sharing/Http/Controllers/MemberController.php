<?php

namespace App\Modules\Sharing\Http\Controllers;

use App\Http\Api\Envelope;
use App\Modules\Sharing\Application\MemberService;
use App\Modules\Sharing\Http\Requests\MemberRoleRequest;
use App\Modules\Sharing\Http\Requests\VersionRequest;
use App\Modules\Sharing\Http\Resources\MemberResource;
use Illuminate\Http\JsonResponse;

/**
 * The owner's control of the people in a wallet (rows S-03, S-04).
 */
final class MemberController
{
    public function changeRole(MemberRoleRequest $request, MemberService $members): JsonResponse
    {
        $view = $members->changeRole($request->callerId(), $request->walletId(), $request->memberUserId(), $request->role(), $request->version(), MemberResource::toArray(...));

        return Envelope::ok(MemberResource::toArray($view));
    }

    public function destroy(VersionRequest $request, MemberService $members): JsonResponse
    {
        $view = $members->remove($request->callerId(), $request->walletId(), $request->memberUserId(), $request->version(), MemberResource::toArray(...));

        return Envelope::ok(MemberResource::toArray($view));
    }
}
