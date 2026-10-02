<?php

namespace App\Modules\Identity\Infrastructure\Sessions;

use App\Modules\Identity\Domain\Ports\ChangeSequence;
use App\Modules\Identity\Domain\Ports\SessionIssuer;
use App\Modules\Identity\Domain\Sessions\DeviceDescription;
use App\Modules\Identity\Domain\Sessions\IssuedCredential;
use App\Modules\Identity\Domain\Sessions\SessionState;
use App\Modules\Identity\Infrastructure\Models\DeviceSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issues `{session_id}|{secret}` credentials in the format Sanctum's guard
 * reads (spec 007, R-4). The secret is 64 characters of [A-Za-z0-9], about
 * 381 bits — above the baseline's 256-bit floor, which Sanctum's own 40
 * characters (about 238 bits) are not. Only its SHA-256 is stored.
 */
final class SanctumSessionIssuer implements SessionIssuer
{
    public function __construct(private readonly ChangeSequence $sequence) {}

    public function issue(string $userId, DeviceDescription $device): IssuedCredential
    {
        $secret = Str::random(64);

        DB::transaction(fn () => DeviceSession::query()->forceCreate([
            'id' => $device->sessionId,
            'user_id' => $userId,
            'token' => hash('sha256', $secret),
            'abilities' => ['*'],
            'device_id' => $device->deviceId,
            'device_name' => $device->name,
            'platform' => $device->platform,
            'app_version' => $device->appVersion,
            'state' => SessionState::Active->value,
            'signed_in_at' => now()->format('Y-m-d H:i:s.uP'),
            'version' => 1,
            'change_seq' => $this->sequence->next($userId),
        ]));

        return new IssuedCredential($device->sessionId, "{$device->sessionId}|{$secret}");
    }

    public function rotate(string $sessionId): IssuedCredential
    {
        $secret = Str::random(64);

        DB::transaction(function () use ($sessionId, $secret): void {
            $userId = DeviceSession::query()->whereKey($sessionId)->where('state', SessionState::Active->value)->value('user_id');

            if ($userId === null) {
                return;
            }

            DeviceSession::query()->whereKey($sessionId)->where('state', SessionState::Active->value)->update([
                'token' => hash('sha256', $secret),
                'signed_in_at' => now()->format('Y-m-d H:i:s.uP'),
                'version' => DB::raw('version + 1'),
                'change_seq' => $this->sequence->next((string) $userId),
                'updated_at' => now()->format('Y-m-d H:i:s.uP'),
            ]);
        });

        return new IssuedCredential($sessionId, "{$sessionId}|{$secret}");
    }
}
