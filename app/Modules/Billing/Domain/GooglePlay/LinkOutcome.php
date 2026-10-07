<?php

namespace App\Modules\Billing\Domain\GooglePlay;

/**
 * What linking a purchase to an account comes to (spec 024, R-3).
 */
enum LinkOutcome: string
{
    /** The purchase already belongs to the caller. */
    case AlreadyYours = 'already_yours';

    /** It belongs to, or by its reference names, another account (or none that is active). */
    case LinkedElsewhere = 'linked_elsewhere';

    /** It is unlinked and may be linked to `LinkDecision::$userId` now. */
    case Link = 'link';

    /** It is unlinked and nobody can be named yet (a notification with no usable reference). */
    case Unlinked = 'unlinked';
}
