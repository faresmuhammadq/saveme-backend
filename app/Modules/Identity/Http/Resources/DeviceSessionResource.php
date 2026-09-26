<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Application\Dto\DeviceSessionView;

/**
 * A Device Session as its owner sees it: the syncable envelope plus the
 * device and the times, and nothing further — no device identifier, no
 * origin (security baseline, What a person sees and controls).
 */
final class DeviceSessionResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(DeviceSessionView $view): array
    {
        $session = $view->session;

        return [
            'id' => $session->id,
            'version' => $session->version,
            'change_seq' => $session->changeSeq,
            'created_at' => Instants::format($session->createdAt),
            'updated_at' => Instants::format($session->updatedAt),
            'deleted_at' => null,
            'user_id' => $session->userId,
            'device_name' => $session->deviceName,
            'platform' => $session->platform,
            'app_version' => $session->appVersion,
            'state' => $session->state->value,
            'end_reason' => $session->endReason?->value,
            'signed_in_at' => Instants::format($session->signedInAt),
            'last_used_at' => Instants::format($session->lastUsedAt),
            'ended_at' => Instants::format($session->endedAt),
            'current' => $view->current,
        ];
    }
}
