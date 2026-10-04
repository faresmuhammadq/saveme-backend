<?php

namespace App\Modules\Billing\Infrastructure\Database\Factories;

use App\Modules\Billing\Domain\RedeemCodes\RedeemCode as Code;
use App\Modules\Billing\Infrastructure\Models\PaymentClaim;
use App\Modules\Billing\Infrastructure\Models\RedeemCode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * An issued code, valid 30 days, on a freshly approved claim (spec 020).
 *
 * @extends Factory<RedeemCode>
 */
class RedeemCodeFactory extends Factory
{
    protected $model = RedeemCode::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'claim_id' => PaymentClaim::factory()->approved(),
            'code_hash' => Code::generate()->hash(),
            'status' => 'issued',
            'expires_at' => now()->addDays(30),
        ];
    }

    /**
     * The code a person would type, in any spelling.
     */
    public function forCode(string $typed): static
    {
        return $this->state(['code_hash' => (Code::tryParse($typed) ?? throw new \InvalidArgumentException('Not a redeem code.'))->hash()]);
    }

    public function redeemed(?string $userId = null, ?string $subscriptionId = null): static
    {
        return $this->state(fn (): array => [
            'status' => 'redeemed',
            'redeemed_by' => $userId ?? (string) Str::uuid7(),
            'redeemed_at' => now(),
            'subscription_id' => $subscriptionId ?? (string) Str::uuid7(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(['status' => 'revoked', 'revoked_at' => now()]);
    }

    public function expiredCode(): static
    {
        return $this->state(['status' => 'expired', 'expires_at' => now()->subDay()]);
    }
}
