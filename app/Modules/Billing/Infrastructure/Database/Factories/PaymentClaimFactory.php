<?php

namespace App\Modules\Billing\Infrastructure\Database\Factories;

use App\Modules\Billing\Domain\Ports\ClaimSealer;
use App\Modules\Billing\Infrastructure\Models\PaymentClaim;
use App\Modules\Billing\Infrastructure\Models\Plan;
use App\Modules\Identity\Application\Billing\ClaimAddresses;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A submitted claim for Plus yearly at its InstaPay price, from a made-up
 * address, sealed through the real sealer (spec 020).
 *
 * @extends Factory<PaymentClaim>
 */
class PaymentClaimFactory extends Factory
{
    /** A 1×1 PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAADElEQVQImWNgYGAAAAAEAAGjChXjAAAAAElFTkSuQmCC';

    protected $model = PaymentClaim::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $id = (string) Str::uuid7();
        $address = app(ClaimAddresses::class)->normaliseAndLookup('payer-'.Str::lower(Str::random(8)).'@example.test');

        return [
            'id' => $id,
            'plan_id' => fn (): string => (string) Plan::query()->where('code', 'plus_yearly')->value('id'),
            'amount_minor' => 129000,
            'currency' => 'EGP',
            'reference' => Str::upper(Str::random(12)),
            'address_sealed' => app(ClaimSealer::class)->seal($address['address'], $id, 'address'),
            'address_lookup' => $address['lookup'],
            'locale' => 'en',
            'status' => 'submitted',
            'expires_at' => now()->addDays(7),
        ];
    }

    public function forAddress(string $email): static
    {
        return $this->state(function (array $attributes) use ($email): array {
            $address = app(ClaimAddresses::class)->normaliseAndLookup($email);

            return [
                'address_sealed' => app(ClaimSealer::class)->seal($address['address'], $attributes['id'], 'address'),
                'address_lookup' => $address['lookup'],
            ];
        });
    }

    /**
     * Plus monthly or yearly, at the plan's InstaPay price.
     */
    public function forPlan(string $code): static
    {
        return $this->state(function () use ($code): array {
            $plan = Plan::query()->where('code', $code)->firstOrFail();
            $price = collect($plan->prices)->firstWhere('channel', 'instapay') ?? throw new \LogicException("Plan [{$code}] has no InstaPay price.");

            return ['plan_id' => $plan->id, 'amount_minor' => $price['amount_minor'], 'currency' => $price['currency']];
        });
    }

    public function inArabic(): static
    {
        return $this->state(['locale' => 'ar']);
    }

    public function reference(string $reference): static
    {
        return $this->state(['reference' => $reference]);
    }

    public function approved(?string $operatorId = null): static
    {
        return $this->state(fn (): array => [
            'status' => 'approved',
            'reviewed_by' => $operatorId ?? (string) Str::uuid7(),
            'reviewed_at' => now(),
        ]);
    }

    public function rejected(string $reason = 'other', ?string $note = null): static
    {
        return $this->state(fn (): array => [
            'status' => 'rejected',
            'reviewed_by' => (string) Str::uuid7(),
            'reviewed_at' => now(),
            'reject_reason' => $reason,
            'review_note' => $note,
            'closed_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['status' => 'expired', 'expires_at' => now()->subDay(), 'closed_at' => now()]);
    }

    /**
     * A claim that closed (expired) the given number of days ago.
     */
    public function closedDaysAgo(int $days): static
    {
        return $this->state(fn (): array => [
            'status' => 'expired',
            'expires_at' => now()->subDays($days),
            'closed_at' => now()->subDays($days),
            'created_at' => now()->subDays($days + 7),
        ]);
    }

    /**
     * A 1×1 PNG receipt, sealed on the private disk as a real submission stores it.
     */
    public function withReceipt(): static
    {
        return $this->state(fn (array $attributes): array => [
            'receipt_path' => "billing/claims/{$attributes['id']}/receipt",
            'receipt_media_type' => 'image/png',
            'receipt_bytes' => strlen((string) base64_decode(self::PNG)),
        ])->afterCreating(function (PaymentClaim $claim): void {
            Storage::disk('local')->put((string) $claim->receipt_path, app(ClaimSealer::class)->seal((string) base64_decode(self::PNG), $claim->id, 'receipt'));
        });
    }
}
