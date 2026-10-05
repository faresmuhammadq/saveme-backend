<?php

namespace App\Modules\Identity\Application\Operators;

use DateTimeImmutable;

/**
 * What an operator may know about a person's account: its state, whether the
 * address is verified, when it began, when deletion is due and how many
 * devices are signed in. No address, name or device detail (spec 021, R-10).
 * A purging or purged account carries its id and state only.
 */
final readonly class AccountFacts
{
    /**
     * @param  'active'|'pending_deletion'|'purging'|'purged'  $state
     */
    public function __construct(
        public string $id,
        public string $state,
        public bool $verified,
        public ?DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $deletionScheduledFor,
        public int $activeDevices,
    ) {}

    /**
     * @return array{id: string, state: string, verified: bool, created_at: ?string, deletion_scheduled_for: ?string, active_devices: int}
     */
    public function toArray(): array
    {
        $instant = 'Y-m-d\TH:i:s.uP';

        return [
            'id' => $this->id,
            'state' => $this->state,
            'verified' => $this->verified,
            'created_at' => $this->createdAt?->format($instant),
            'deletion_scheduled_for' => $this->deletionScheduledFor?->format($instant),
            'active_devices' => $this->activeDevices,
        ];
    }
}
