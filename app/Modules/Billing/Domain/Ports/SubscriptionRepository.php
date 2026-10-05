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
     * The person's subscriptions, each locked until the transaction ends, in
     * ascending id order (spec 020, lock order).
     *
     * @return list<SubscriptionRecord>
     */
    public function lockForUser(string $userId): array;

    public function findByProviderRef(string $provider, string $providerRef): ?SubscriptionRecord;

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

    /**
     * Active `instapay` subscriptions whose billing period ends within the
     * next $days days and that have not been reminded (spec 020, FR-034).
     *
     * @return list<SubscriptionRecord>
     */
    public function dueForReminder(DateTimeImmutable $now, int $days): array;

    /**
     * Records that the renewal reminder was queued, only if it was not before.
     *
     * @return bool whether this call recorded it
     */
    public function markReminded(string $id, DateTimeImmutable $at): bool;

    /**
     * How many people each plan governs at $now (ADR-0013 rule 14): per
     * person, the granting subscription that ends latest (none beats any
     * end). People with none are governed by the default plan and are not
     * counted here.
     *
     * @return array<string, int> plan id to people
     */
    public function governedCountsByPlan(DateTimeImmutable $now): array;

    /**
     * People holding a granting subscription (active or in grace, not yet
     * ended) on a non-default plan, distinct per channel, and the
     * subscriptions currently in grace (spec 021, FR-010).
     *
     * @return array{google_play: int, instapay: int, manual: int, in_grace: int}
     */
    public function figures(DateTimeImmutable $now): array;
}
