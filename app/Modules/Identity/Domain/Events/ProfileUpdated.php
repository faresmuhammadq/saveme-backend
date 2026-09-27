<?php

namespace App\Modules\Identity\Domain\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A person saved changes to their profile. Carries the names of the members
 * that changed, never their values: a display name must not reach an event
 * payload or a log (spec 008, R-7; P-15 rule 4).
 */
final readonly class ProfileUpdated implements ShouldDispatchAfterCommit
{
    /**
     * @param  list<string>  $changedFields
     */
    public function __construct(
        public string $eventId,
        public string $occurredAt,
        public string $userId,
        public array $changedFields,
    ) {}
}
