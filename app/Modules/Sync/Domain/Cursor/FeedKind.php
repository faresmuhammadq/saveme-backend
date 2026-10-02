<?php

namespace App\Modules\Sync\Domain\Cursor;

/**
 * Which feed a cursor belongs to: the user scope or one wallet's scope.
 */
enum FeedKind: string
{
    case User = 'u';
    case Wallet = 'w';
}
