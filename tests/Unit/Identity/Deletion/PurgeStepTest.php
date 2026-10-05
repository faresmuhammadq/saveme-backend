<?php

use App\Modules\Identity\Domain\Deletion\PurgeCounts;
use App\Modules\Identity\Domain\Deletion\PurgeStep;

test('the purge steps run in the order the constitution of the purge demands', function () {
    expect(array_map(fn (PurgeStep $step): string => $step->value, PurgeStep::cases()))->toBe([
        'begin', 'erase_name', 'revoke_invitations', 'resolve_owned_wallets', 'leave_wallets',
        'budgets', 'categories', 'subscriptions', 'exports', 'completion_mail', 'erase_identity',
    ]);
});

test('the name is erased before wallets are touched, and invitations before the identity goes', function () {
    expect(PurgeStep::EraseName->isBefore(PurgeStep::ResolveOwnedWallets))->toBeTrue()
        ->and(PurgeStep::EraseName->isBefore(PurgeStep::LeaveWallets))->toBeTrue()
        ->and(PurgeStep::RevokeInvitations->isBefore(PurgeStep::EraseIdentity))->toBeTrue()
        ->and(PurgeStep::CompletionMail->isBefore(PurgeStep::EraseIdentity))->toBeTrue()
        ->and(PurgeStep::LeaveWallets->isBefore(PurgeStep::ResolveOwnedWallets))->toBeFalse();
});

test('counts add up and always carry every key as an integer', function () {
    $counts = PurgeCounts::of('wallets_deleted', 2)->plus(PurgeCounts::of('wallets_deleted', 1))->plus(PurgeCounts::of('memberships_ended', 4));

    expect($counts->get('wallets_deleted'))->toBe(3)
        ->and($counts->toArray())->toHaveKeys(PurgeCounts::KEYS)
        ->and(array_filter($counts->toArray(), fn (mixed $value): bool => ! is_int($value)))->toBe([]);
});
