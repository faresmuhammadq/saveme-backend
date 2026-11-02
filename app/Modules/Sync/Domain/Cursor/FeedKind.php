<?php

namespace App\Modules\Sync\Domain\Cursor;

/**
 * Which feed a cursor belongs to: the user scope, one wallet's scope or one Shared Budget's scope.
 */
enum FeedKind: string
{
    case User = 'u';
    case Wallet = 'w';
    case SharedBudget = 's';
}
