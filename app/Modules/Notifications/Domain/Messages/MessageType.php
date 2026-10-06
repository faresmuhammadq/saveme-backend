<?php

namespace App\Modules\Notifications\Domain\Messages;

use App\Modules\Notifications\Domain\Preferences\Category;

/**
 * The closed set of push messages (spec 023, FR-022). Each type declares its
 * category, the identifiers its data block may carry besides the four common
 * keys, its collapse key and its priority. Frozen for the phone in
 * docs/openapi/paths/notifications.yaml (PushPayload).
 */
enum MessageType: string
{
    case OccurrenceDue = 'occurrence_due';
    case OccurrenceAutoConfirmRefused = 'occurrence_auto_confirm_refused';
    case OccurrencesDueSummary = 'occurrences_due_summary';
    case BudgetExceeded = 'budget_exceeded';
    case InvitationReceived = 'invitation_received';
    case MemberRoleChanged = 'member_role_changed';
    case MemberRemoved = 'member_removed';
    case WalletOwnershipReceived = 'wallet_ownership_received';
    case SubscriptionEnding = 'subscription_ending';
    case SubscriptionLapsed = 'subscription_lapsed';
    case ExportReady = 'export_ready';
    case DeletionReminder = 'deletion_reminder';

    public function category(): Category
    {
        return match ($this) {
            self::OccurrenceDue, self::OccurrenceAutoConfirmRefused, self::OccurrencesDueSummary => Category::RecurringDue,
            self::BudgetExceeded => Category::BudgetExceeded,
            self::InvitationReceived, self::MemberRoleChanged, self::MemberRemoved, self::WalletOwnershipReceived => Category::Sharing,
            self::SubscriptionEnding, self::SubscriptionLapsed => Category::Billing,
            self::ExportReady => Category::ExportReady,
            self::DeletionReminder => Category::DeletionReminder,
        };
    }

    /**
     * The identifiers the data block may carry, besides `schema`, `type`,
     * `category` and `notification_id`.
     *
     * @return list<string>
     */
    public function allowedKeys(): array
    {
        return match ($this) {
            self::OccurrenceDue, self::OccurrenceAutoConfirmRefused => ['wallet_id', 'occurrence_id'],
            self::BudgetExceeded => ['budget_id'],
            self::InvitationReceived => ['invitation_id'],
            self::MemberRoleChanged => ['wallet_id', 'role'],
            self::MemberRemoved, self::WalletOwnershipReceived => ['wallet_id'],
            self::ExportReady => ['export_id'],
            self::OccurrencesDueSummary, self::SubscriptionEnding, self::SubscriptionLapsed, self::DeletionReminder => [],
        };
    }

    /**
     * The Android collapse key, naming the message's subject.
     *
     * @param  array<string, string>  $data
     */
    public function collapseKey(array $data): string
    {
        return match ($this) {
            self::OccurrenceDue, self::OccurrenceAutoConfirmRefused => 'occurrence:'.$data['occurrence_id'],
            self::OccurrencesDueSummary => 'occurrences_due',
            self::BudgetExceeded => 'budget:'.$data['budget_id'],
            self::InvitationReceived => 'invitation:'.$data['invitation_id'],
            self::MemberRoleChanged, self::MemberRemoved, self::WalletOwnershipReceived => 'wallet:'.$data['wallet_id'],
            self::SubscriptionEnding, self::SubscriptionLapsed => 'subscription',
            self::ExportReady => 'export:'.$data['export_id'],
            self::DeletionReminder => 'deletion',
        };
    }

    public function priority(): Priority
    {
        return match ($this) {
            self::OccurrenceDue, self::OccurrenceAutoConfirmRefused, self::DeletionReminder => Priority::High,
            default => Priority::Normal,
        };
    }
}
