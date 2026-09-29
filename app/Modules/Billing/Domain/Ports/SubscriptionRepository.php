<?php

namespace App\Modules\Billing\Domain\Ports;

use App\Modules\Billing\Domain\Subscriptions\SubscriptionRecord;
use App\Modules\Billing\Domain\Subscriptions\SubscriptionStatus;
use DateTimeImmutable;

interface SubscriptionRepository
{
    /**
     * @return list<SubscriptionRecord>
     */
    public function forUser(string $userId): array;

    public function find(string $id): ?SubscriptionRecord;

    /**
     * @param  array{user_id: string, plan_id: string, provider: string, provider_ref: string, status: string, current_period_start: DateTimeImmutable, current_period_end: ?DateTimeImmutable, grace_until: ?DateTimeImmutable, auto_renew: bool}  $row
     */
    public function create(array $row): SubscriptionRecord;

    /**
     * Moves a subscription from one status to another, only if it is still in
     * $from, so a concurrent or repeated move changes nothing twice.
     *
     * @param  array<string, DateTimeImmutable|null>  $changes  instant columns to set with it
     * @return bool whether this call moved it
     */
    public function transition(string $id, SubscriptionStatus $from, SubscriptionStatus $to, array $changes = []): bool;

    /**
     * @param  array<string, DateTimeImmutable|null>  $changes
     */
    public function updateDates(string $id, array $changes): void;

    /**
     * Active subscriptions whose billing period has ended.
     *
     * @return list<SubscriptionRecord>
     */
    public function dueForGrace(DateTimeImmutable $now): array;

    /**
     * Grace subscriptions whose grace has ended.
     *
     * @return list<SubscriptionRecord>
     */
    public function dueForExpiry(DateTimeImmutable $now): array;
}
