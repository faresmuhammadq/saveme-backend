<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Api\Envelope;
use App\Modules\Identity\Application\Profiles\Profiles;
use App\Modules\Identity\Application\Profiles\ReadMe;
use App\Modules\Identity\Application\Profiles\SaveProfile;
use App\Modules\Identity\Http\Controllers\Concerns\ResolvesCaller;
use App\Modules\Identity\Http\Requests\SaveProfileRequest;
use App\Modules\Identity\Http\Resources\AccountResource;
use App\Modules\Identity\Http\Resources\ProfileResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProfileController
{
    use ResolvesCaller;

    public function update(SaveProfileRequest $request, SaveProfile $save, Profiles $profiles): JsonResponse
    {
        $userId = $this->callerId($request);
        $saved = $save->handle($userId, $request->values(), $request->integer('version'), ProfileResource::toArray(...));

        return Envelope::ok(ProfileResource::toArray($saved, $profiles->isCurrencyLocked($userId)));
    }

    public function show(Request $request, ReadMe $readMe): JsonResponse
    {
        $me = $readMe->handle($this->callerId($request));

        return Envelope::ok([
            'account' => AccountResource::toArray($me['account']),
            'profile' => ProfileResource::toArray($me['profile'], $me['currencyLocked']),
        ]);
    }
}
