<?php

namespace App\Modules\Identity\Infrastructure\Database\Factories;

use App\Modules\Identity\Infrastructure\Models\DeviceSession;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A device session whose secret is `secret-{id}`, so a test can build its
 * credential with `DeviceSessionFactory::credentialFor($session)`.
 *
 * @extends Factory<DeviceSession>
 */
class DeviceSessionFactory extends Factory
{
    protected $model = DeviceSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => fn (): string => (string) Str::uuid7(),
            'user_id' => User::factory(),
            'token' => fn (array $attributes): string => hash('sha256', 'secret-'.$attributes['id']),
            'abilities' => ['*'],
            'device_id' => fn (): string => 'device-'.Str::random(12),
            'device_name' => 'Pixel 8',
            'platform' => 'android',
            'app_version' => '1.0.0',
            'state' => 'active',
            'signed_in_at' => now(),
            'version' => 1,
            'change_seq' => fn (): int => (int) DB::scalar("select nextval('sync_change_seq')"),
        ];
    }

    public function revoked(string $reason = 'ended_by_owner'): static
    {
        return $this->state(fn (): array => ['state' => 'revoked', 'end_reason' => $reason, 'ended_at' => now()]);
    }

    public function idleFor(int $days): static
    {
        return $this->state(fn (): array => ['signed_in_at' => now()->subDays($days), 'last_used_at' => now()->subDays($days)]);
    }

    public static function credentialFor(DeviceSession $session): string
    {
        return $session->id.'|secret-'.$session->id;
    }
}
