<?php

namespace App\Modules\Identity\Infrastructure\Database\Factories;

use App\Modules\Identity\Infrastructure\Models\DataExport;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A queued account export for the given `user_id`.
 *
 * @extends Factory<DataExport>
 */
class DataExportFactory extends Factory
{
    protected $model = DataExport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'scope' => 'account',
            'wallet_id' => null,
            'status' => 'queued',
            'requested_at' => now(),
        ];
    }

    public function ready(): static
    {
        return $this->state(fn (): array => [
            'status' => 'ready',
            'size_bytes' => 1024,
            'started_at' => now(),
            'completed_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
    }

    public function failed(string $code = 'generation_failed'): static
    {
        return $this->state(fn (): array => ['status' => 'failed', 'failure_code' => $code, 'completed_at' => now()]);
    }
}
