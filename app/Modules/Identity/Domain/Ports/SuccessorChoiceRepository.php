<?php

namespace App\Modules\Identity\Domain\Ports;

use DateTimeImmutable;

/**
 * The new owners a departing person chose while their deletion is pending.
 */
interface SuccessorChoiceRepository
{
    /**
     * Replaces every stored choice of the person.
     *
     * @param  list<array{wallet_id: string, member_user_id: string}>  $choices
     */
    public function replace(string $userId, array $choices, DateTimeImmutable $at): void;

    /**
     * @return array<string, string> wallet id => chosen member's user id
     */
    public function forUser(string $userId): array;

    public function deleteFor(string $userId): void;
}
