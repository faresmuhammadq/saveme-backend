<?php

use App\Modules\Notifications\Domain\Messages\MessageType;
use App\Modules\Notifications\Domain\Messages\Priority;
use App\Modules\Notifications\Domain\Preferences\Category;

$id = '0192f7a0-1c3d-7abc-9f01-2b3c4d5e6f70';

// One row per type of spec 023 section 5: category, identifiers, collapse key, priority.
dataset('message types', [
    'occurrence_due' => [MessageType::OccurrenceDue, Category::RecurringDue, ['wallet_id', 'occurrence_id'], 'occurrence:%s', Priority::High],
    'occurrence_auto_confirm_refused' => [MessageType::OccurrenceAutoConfirmRefused, Category::RecurringDue, ['wallet_id', 'occurrence_id'], 'occurrence:%s', Priority::High],
    'occurrences_due_summary' => [MessageType::OccurrencesDueSummary, Category::RecurringDue, [], 'occurrences_due', Priority::Normal],
    'budget_exceeded' => [MessageType::BudgetExceeded, Category::BudgetExceeded, ['budget_id'], 'budget:%s', Priority::Normal],
    'invitation_received' => [MessageType::InvitationReceived, Category::Sharing, ['invitation_id'], 'invitation:%s', Priority::Normal],
    'member_role_changed' => [MessageType::MemberRoleChanged, Category::Sharing, ['wallet_id', 'role'], 'wallet:%s', Priority::Normal],
    'member_removed' => [MessageType::MemberRemoved, Category::Sharing, ['wallet_id'], 'wallet:%s', Priority::Normal],
    'wallet_ownership_received' => [MessageType::WalletOwnershipReceived, Category::Sharing, ['wallet_id'], 'wallet:%s', Priority::Normal],
    'subscription_ending' => [MessageType::SubscriptionEnding, Category::Billing, [], 'subscription', Priority::Normal],
    'subscription_lapsed' => [MessageType::SubscriptionLapsed, Category::Billing, [], 'subscription', Priority::Normal],
    'export_ready' => [MessageType::ExportReady, Category::ExportReady, ['export_id'], 'export:%s', Priority::Normal],
    'deletion_reminder' => [MessageType::DeletionReminder, Category::DeletionReminder, [], 'deletion', Priority::High],
]);

test('each type has the category, identifiers, collapse key and priority of the spec', function (MessageType $type, Category $category, array $keys, string $collapse, Priority $priority) use ($id) {
    $data = array_fill_keys($keys, $id);
    $expectedCollapse = str_contains($collapse, '%s') ? sprintf($collapse, $id) : $collapse;

    expect($type->category())->toBe($category)
        ->and($type->allowedKeys())->toBe($keys)
        ->and($type->collapseKey($data))->toBe($expectedCollapse)
        ->and($type->priority())->toBe($priority);
})->with('message types');

test('exactly twelve types exist', function () {
    expect(MessageType::cases())->toHaveCount(12);
});

test('every type belongs to one of the six categories, each used', function () {
    $used = array_unique(array_map(fn (MessageType $type): string => $type->category()->value, MessageType::cases()));
    sort($used);
    $all = array_map(fn (Category $category): string => $category->value, Category::cases());
    sort($all);

    expect($used)->toBe($all);
});
