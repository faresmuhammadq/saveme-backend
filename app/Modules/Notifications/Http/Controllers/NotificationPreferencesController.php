<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Api\Envelope;
use App\Modules\Notifications\Application\PreferencesService;
use App\Modules\Notifications\Http\Requests\SavePreferencesRequest;
use App\Modules\Notifications\Http\Resources\NotificationPreferencesResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET` and `PUT /me/notification-preferences` (spec 023, US2). A read never
 * writes; a save is the whole object with the last-seen version.
 */
final class NotificationPreferencesController
{
    public function show(Request $request, PreferencesService $preferences): JsonResponse
    {
        return Envelope::ok(NotificationPreferencesResource::toArray($preferences->read($this->callerId($request))));
    }

    public function update(SavePreferencesRequest $request, PreferencesService $preferences): JsonResponse
    {
        $saved = $preferences->save(
            $this->callerId($request),
            $request->choices(),
            $request->version(),
            NotificationPreferencesResource::toArray(...),
        );

        return Envelope::ok(NotificationPreferencesResource::toArray($saved));
    }

    private function callerId(Request $request): string
    {
        return (string) $request->user('api')?->getAuthIdentifier();
    }
}
