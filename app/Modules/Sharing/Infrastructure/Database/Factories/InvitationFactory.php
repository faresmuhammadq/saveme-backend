<?php

namespace App\Modules\Sharing\Infrastructure\Database\Factories;

use App\Modules\Identity\Application\Sharing\InvitationAddresses;
use App\Modules\Sharing\Domain\InvitationSecret;
use App\Modules\Sharing\Infrastructure\Models\Invitation;
use App\Modules\Wallets\Domain\Ports\ScopeSequence;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A pending editor invitation to a made-up address, expiring in 7 days. Give
 * `wallet_id` and `invited_by` explicitly. States: `link`, `expired`,
 * `closed(status)`, `withToken($token)`.
 *
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
{
    protected $model = Invitation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'wallet_id' => (string) Str::uuid7(),
            'invited_by' => (string) Str::uuid7(),
            'role' => 'editor',
            'channel' => 'email',
            'address_sealed' => fn (array $attributes): string => self::seal($attributes['invited_by'], 'invitee@example.test'),
            'address_lookup' => fn (): string => app(InvitationAddresses::class)->normaliseAndLookup('invitee@example.test')['lookup'],
            'token_hash' => fn (): string => InvitationSecret::generate()->hash(),
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
            'responder_user_id' => null,
            'version' => 1,
            'change_seq' => fn (array $attributes): int => self::stampFor($attributes['wallet_id']),
            'deleted_at' => null,
        ];
    }

    /**
     * Seals under the inviter's key when the inviter exists; an inviter with
     * no users row (many tests have none) gets an opaque stand-in.
     */
    private static function seal(string $inviterId, string $address): string
    {
        if (DB::table('users')->where('id', $inviterId)->exists()) {
            return app(InvitationAddresses::class)->seal($inviterId, $address);
        }

        return 'unsealed:'.$address;
    }

    /**
     * The wallet's next stamp; a wallet that does not exist takes a stamp
     * above anything a counter hands out.
     */
    private static function stampFor(string $walletId): int
    {
        static $orphan = 1_000_000_000;

        if (DB::table('wallets')->where('id', $walletId)->exists()) {
            return app(ScopeSequence::class)->nextFor($walletId);
        }

        return ++$orphan;
    }

    public function link(): static
    {
        return $this->state(fn (): array => ['channel' => 'link', 'address_sealed' => null, 'address_lookup' => null]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subHour()]);
    }

    public function closed(string $status = 'revoked'): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
            'deleted_at' => now(),
            'version' => 2,
            'address_sealed' => null,
            'address_lookup' => null,
        ]);
    }

    public function withToken(string $token): static
    {
        return $this->state(fn (): array => ['token_hash' => InvitationSecret::hashOf($token)]);
    }
}
