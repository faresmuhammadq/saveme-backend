<?php

namespace App\Modules\Wallets\Application\Activity;

use App\Modules\Wallets\Domain\Activity\ActivityRecord;

/**
 * An entry with the actor's display name, filled only while the actor is an
 * active member of the wallet (ADR-0020 rule 6).
 */
final readonly class ActivityView
{
    public function __construct(
        public ActivityRecord $entry,
        public ?string $actorDisplayName,
    ) {}
}
