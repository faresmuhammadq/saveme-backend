<?php

namespace App\Modules\Billing\Infrastructure\Database\Factories;

use App\Modules\Billing\Infrastructure\Models\PlayPurchase;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * An unlinked, pending Play purchase for a random token lookup.
 *
 * @extends Factory<PlayPurchase>
 */
class PlayPurchaseFactory extends Factory
{
    protected $model = PlayPurchase::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'token_lookup' => hash('sha256', Str::random(32)),
            'token_sealed' => null,
            'kind' => 'subscription',
            'product_id' => 'plus_monthly',
            'user_id' => null,
            'linked_at' => null,
            'subscription_id' => null,
            'play_state' => 'pending',
            'is_test' => false,
            'purchased_at' => now()->subHour(),
        ];
    }

    public function linkedTo(string $userId): static
    {
        return $this->state(fn (): array => ['user_id' => $userId, 'linked_at' => now()->subMinutes(30)]);
    }
}
