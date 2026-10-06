<?php

namespace App\Modules\Notifications\Domain\Preferences;

/**
 * The six notification categories a person can switch on and off (spec 023,
 * FR-015). The value is also the Android channel id.
 */
enum Category: string
{
    case RecurringDue = 'recurring_due';
    case BudgetExceeded = 'budget_exceeded';
    case Sharing = 'sharing';
    case Billing = 'billing';
    case ExportReady = 'export_ready';
    case DeletionReminder = 'deletion_reminder';
}
