<?php

namespace App\Modules\Identity\Application\Deletion;

use DateTimeImmutable;

/**
 * The account's deletion state and, per shared wallet it owns, the member
 * who would inherit it (spec 022, FR-036).
 */
final readonly class DeletionPlan
{
    /**
     * @param  list<DeletionPlanEntry>  $successors  ascending by wallet id
     */
    public function __construct(
        public string $state,
        public ?DateTimeImmutable $requestedAt,
        public ?DateTimeImmutable $scheduledFor,
        public array $successors,
    ) {}
}
