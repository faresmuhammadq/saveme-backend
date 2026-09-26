<?php

namespace App\Modules\Identity\Domain\Audit;

/**
 * Who acted: the person, an operator on their behalf, or the system.
 */
enum ActorType: string
{
    case User = 'user';
    case Operator = 'operator';
    case System = 'system';
}
