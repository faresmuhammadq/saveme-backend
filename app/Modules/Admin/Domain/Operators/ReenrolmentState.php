<?php

namespace App\Modules\Admin\Domain\Operators;

enum ReenrolmentState: string
{
    case Open = 'open';
    case Completed = 'completed';
    case Declined = 'declined';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
