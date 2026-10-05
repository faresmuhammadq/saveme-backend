<?php

namespace App\Modules\Identity\Domain\Deletion;

/**
 * What a purge did, as integers only: the detail of `deletion_completed`
 * and of the progress row. Never a name, an amount or an address.
 */
final readonly class PurgeCounts
{
    public const KEYS = [
        'wallets_transferred',
        'wallets_deleted',
        'memberships_ended',
        'invitations_revoked',
        'budgets_deleted',
        'categories_deleted',
        'categories_kept',
        'subscriptions_ended',
    ];

    /**
     * @param  array<string, int>  $values
     */
    public function __construct(private array $values = []) {}

    /**
     * @param  array<string, mixed>  $stored
     */
    public static function fromStored(array $stored): self
    {
        $values = [];

        foreach (self::KEYS as $key) {
            if (isset($stored[$key])) {
                $values[$key] = (int) $stored[$key];
            }
        }

        return new self($values);
    }

    public static function of(string $key, int $count): self
    {
        return new self([$key => $count]);
    }

    public function get(string $key): int
    {
        return $this->values[$key] ?? 0;
    }

    /**
     * Adds another set's counts to this one's.
     */
    public function plus(self $other): self
    {
        $merged = $this->values;

        foreach ($other->values as $key => $count) {
            $merged[$key] = ($merged[$key] ?? 0) + $count;
        }

        return new self($merged);
    }

    /**
     * Every key, zero when unset.
     *
     * @return array<string, int>
     */
    public function toArray(): array
    {
        $all = [];

        foreach (self::KEYS as $key) {
            $all[$key] = $this->get($key);
        }

        return $all;
    }
}
