<?php

namespace App\Modules\Billing\Infrastructure\Database\Factories;

use App\Modules\Billing\Infrastructure\Models\Plan;
use App\Modules\Billing\Infrastructure\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * An active manual subscription to the seeded Plus monthly plan, 30 days left.
 *
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => (string) Str::uuid7(),
            'plan_id' => fn (): string => (string) Plan::query()->where('code', 'plus_monthly')->value('id'),
            'provider' => 'manual',
            'provider_ref' => fn (): string => 'test:'.Str::uuid7(),
            'status' => 'active',
            'current_period_start' => now()->subDay(),
            'current_period_end' => now()->addDays(30),
            'grace_until' => null,
            'auto_renew' => false,
            'source_meta' => [],
        ];
    }

    public function forPlan(string $code): static
    {
        return $this->state(fn (): array => ['plan_id' => (string) Plan::query()->where('code', $code)->value('id')]);
    }

    public function provider(string $provider): static
    {
        return $this->state(fn (): array => ['provider' => $provider]);
    }

    public function endingIn(int $days): static
    {
        return $this->state(fn (): array => ['current_period_end' => now()->addDays($days)]);
    }

    public function grace(int $daysLeft = 2): static
    {
        return $this->state(fn (): array => ['status' => 'grace', 'current_period_end' => now()->subDay(), 'grace_until' => now()->addDays($daysLeft)]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['status' => 'expired', 'current_period_end' => now()->subDays(10), 'grace_until' => now()->subDays(7)]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => ['status' => 'revoked']);
    }

    public function lifetime(): static
    {
        return $this->state(fn (): array => ['current_period_end' => null]);
    }
}
