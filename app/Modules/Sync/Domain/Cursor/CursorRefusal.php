<?php

namespace App\Modules\Sync\Domain\Cursor;

/**
 * Why a cursor was refused. Never sent to the client (the wire answer is
 * always INVALID_CURSOR); it exists so tests and logs can tell the reasons apart.
 */
enum CursorRefusal: string
{
    case Malformed = 'malformed';
    case BadSignature = 'bad_signature';
    case UnknownVersion = 'unknown_version';
    case WrongFeed = 'wrong_feed';
    case WrongScope = 'wrong_scope';
    case WrongReader = 'wrong_reader';
    case AheadOfScope = 'ahead_of_scope';
}
