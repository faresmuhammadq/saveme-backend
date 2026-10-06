<?php

namespace App\Modules\Notifications\Domain\Messages;

/**
 * FCM's Android priority (spec 023, A-4): high only for a reminder that
 * should wake the phone.
 */
enum Priority: string
{
    case High = 'HIGH';
    case Normal = 'NORMAL';
}
