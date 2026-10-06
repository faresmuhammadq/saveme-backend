<?php

use App\Modules\Billing\Domain\Events\SubscriptionLapsed;
use App\Modules\Billing\Domain\Events\SubscriptionRenewalDue;
use App\Modules\Budgeting\Domain\Events\BudgetExceeded;
use App\Modules\Identity\Application\Sharing\InvitationAddresses;
use App\Modules\Identity\Domain\Events\DeletionReminderDue;
use App\Modules\Identity\Domain\Events\ExportReady;
use App\Modules\Identity\Infrastructure\Models\Profile;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Notifications\Domain\Messages\PushMessage;
use App\Modules\Notifications\Infrastructure\Models\PushNotification;
use App\Modules\Sharing\Domain\Events\InvitationSent;
use App\Modules\Sharing\Domain\Events\MemberRevoked;
use App\Modules\Sharing\Domain\Events\MemberRoleChanged;
use App\Modules\Sharing\Infrastructure\Models\Invitation;
use App\Modules\Wallets\Domain\Events\WalletOwnershipTransferred;

/*
 * Each source event becomes one notification of the right type, for the right
 * person, with only the identifiers of its type (spec 023, FR-024 to FR-027).
 */

function stamp(): array
{
    return ['eventId' => (string) Str::uuid7(), 'occurredAt' => now()->utc()->format('Y-m-d\TH:i:s.u\Z')];
}

function lastMessage(string $token): PushMessage
{
    $messages = fakePush()->messagesTo($token);

    return $messages[array_key_last($messages)];
}

describe('budgets', function () {
    test('BudgetExceeded notifies the owner once, carrying only the budget id', function () {
        $owner = person();
        registerPhone($owner, 'owner-phone');
        $budgetId = (string) Str::uuid7();
        $event = new BudgetExceeded(...stamp(), budgetId: $budgetId, userId: $owner->id, periodStart: '2026-10-01', threshold: 100);

        event($event);
        event($event);

        $row = PushNotification::query()->firstOrFail();
        expect(PushNotification::query()->count())->toBe(1)
            ->and($row->type)->toBe('budget_exceeded')
            ->and($row->category)->toBe('budget_exceeded')
            ->and($row->dedupe_key)->toBe("budget_exceeded:{$budgetId}:2026-10-01")
            ->and($row->data)->toBe(['budget_id' => $budgetId])
            ->and(fakePush()->count())->toBe(1);
    });

    test('a new period is a new notification', function () {
        $owner = person();
        $budgetId = (string) Str::uuid7();

        event(new BudgetExceeded(...stamp(), budgetId: $budgetId, userId: $owner->id, periodStart: '2026-10-01', threshold: 100));
        event(new BudgetExceeded(...stamp(), budgetId: $budgetId, userId: $owner->id, periodStart: '2026-11-01', threshold: 100));

        expect(PushNotification::query()->count())->toBe(2);
    });
});

describe('sharing', function () {
    beforeEach(function () {
        $this->inviter = person('inviter@example.test');
        $this->wallet = ownedWallet($this->inviter->id);
        $this->invitee = person('invitee@example.test');
        registerPhone($this->invitee, 'invitee-phone');
    });

    test('InvitationSent by email to a verified account sends invitation_received with only the invitation id', function () {
        $invitation = Invitation::factory()->create(['wallet_id' => $this->wallet->id, 'invited_by' => $this->inviter->id]);
        $event = new InvitationSent(...stamp(), invitationId: $invitation->id, walletId: $this->wallet->id, invitedBy: $this->inviter->id, channel: 'email', role: 'editor');

        event($event);
        event($event);

        $row = PushNotification::query()->firstOrFail();
        expect(PushNotification::query()->count())->toBe(1)
            ->and($row->user_id)->toBe($this->invitee->id)
            ->and($row->type)->toBe('invitation_received')
            ->and($row->data)->toBe(['invitation_id' => $invitation->id])
            ->and($row->dedupe_key)->toBe("invitation:{$invitation->id}")
            ->and(lastMessage('invitee-phone')->data)->toBe(['schema' => '1', 'type' => 'invitation_received', 'category' => 'sharing', 'notification_id' => $row->id, 'invitation_id' => $invitation->id]);
    });

    test('a link invitation, and an address nobody holds, send nothing', function () {
        $link = Invitation::factory()->link()->create(['wallet_id' => $this->wallet->id, 'invited_by' => $this->inviter->id]);
        $stranger = Invitation::factory()->create([
            'wallet_id' => $this->wallet->id,
            'invited_by' => $this->inviter->id,
            'address_lookup' => app(InvitationAddresses::class)->normaliseAndLookup('stranger@example.test')['lookup'],
        ]);

        event(new InvitationSent(...stamp(), invitationId: $link->id, walletId: $this->wallet->id, invitedBy: $this->inviter->id, channel: 'link', role: 'editor'));
        event(new InvitationSent(...stamp(), invitationId: $stranger->id, walletId: $this->wallet->id, invitedBy: $this->inviter->id, channel: 'email', role: 'editor'));

        expect(PushNotification::query()->count())->toBe(0);
    });

    test('an unverified addressee is not told by push', function () {
        $unverified = person('unverified@example.test', verified: false);
        registerPhone($unverified, 'unverified-phone');
        $invitation = Invitation::factory()->create([
            'wallet_id' => $this->wallet->id,
            'invited_by' => $this->inviter->id,
            'address_lookup' => app(InvitationAddresses::class)->normaliseAndLookup('unverified@example.test')['lookup'],
        ]);

        event(new InvitationSent(...stamp(), invitationId: $invitation->id, walletId: $this->wallet->id, invitedBy: $this->inviter->id, channel: 'email', role: 'editor'));

        expect(PushNotification::query()->count())->toBe(0);
    });

    test('MemberRoleChanged by the owner sends member_role_changed with the wallet and the new role', function () {
        $event = new MemberRoleChanged(...stamp(), walletId: $this->wallet->id, memberUserId: $this->invitee->id, previousRole: 'editor', role: 'viewer', reason: 'owner', actorUserId: $this->inviter->id);

        event($event);
        event($event);

        $row = PushNotification::query()->firstOrFail();
        expect(PushNotification::query()->count())->toBe(1)
            ->and($row->type)->toBe('member_role_changed')
            ->and($row->data)->toEqual(['wallet_id' => $this->wallet->id, 'role' => 'viewer'])
            ->and($row->dedupe_key)->toBe('event:'.$event->eventId)
            ->and(lastMessage('invitee-phone')->collapseKey)->toBe('wallet:'.$this->wallet->id);
    });

    test('a role change by a plan has no actor and is still sent', function () {
        event(new MemberRoleChanged(...stamp(), walletId: $this->wallet->id, memberUserId: $this->invitee->id, previousRole: 'editor', role: 'viewer', reason: 'plan'));

        expect(PushNotification::query()->count())->toBe(1);
    });

    test('a role change whose actor is the member sends nothing', function () {
        event(new MemberRoleChanged(...stamp(), walletId: $this->wallet->id, memberUserId: $this->invitee->id, previousRole: 'editor', role: 'viewer', reason: 'owner', actorUserId: $this->invitee->id));

        expect(PushNotification::query()->count())->toBe(0);
    });

    test('MemberRevoked removed sends member_removed, and left sends nothing', function () {
        event(new MemberRevoked(...stamp(), walletId: $this->wallet->id, memberUserId: $this->invitee->id, actorUserId: $this->inviter->id, reason: 'left'));
        expect(PushNotification::query()->count())->toBe(0);

        $removed = new MemberRevoked(...stamp(), walletId: $this->wallet->id, memberUserId: $this->invitee->id, actorUserId: $this->inviter->id, reason: 'removed');
        event($removed);
        event($removed);

        $row = PushNotification::query()->firstOrFail();
        expect(PushNotification::query()->count())->toBe(1)
            ->and($row->type)->toBe('member_removed')
            ->and($row->data)->toBe(['wallet_id' => $this->wallet->id]);
    });

    test('WalletOwnershipTransferred sends wallet_ownership_received to the new owner only', function () {
        registerPhone($this->inviter, 'previous-owner-phone');
        $event = new WalletOwnershipTransferred(...stamp(), walletId: $this->wallet->id, previousOwnerId: $this->inviter->id, newOwnerId: $this->invitee->id);

        event($event);
        event($event);

        $row = PushNotification::query()->firstOrFail();
        expect(PushNotification::query()->count())->toBe(1)
            ->and($row->user_id)->toBe($this->invitee->id)
            ->and($row->type)->toBe('wallet_ownership_received')
            ->and(fakePush()->messagesTo('previous-owner-phone'))->toBe([]);
    });
});

describe('account', function () {
    beforeEach(function () {
        $this->user = person();
        registerPhone($this->user, 'phone');
    });

    test('SubscriptionRenewalDue sends subscription_ending once per subscription', function () {
        $subscriptionId = (string) Str::uuid7();
        $event = new SubscriptionRenewalDue(...stamp(), userId: $this->user->id, subscriptionId: $subscriptionId);

        event($event);
        event(new SubscriptionRenewalDue(...stamp(), userId: $this->user->id, subscriptionId: $subscriptionId));

        $row = PushNotification::query()->firstOrFail();
        expect(PushNotification::query()->count())->toBe(1)
            ->and($row->type)->toBe('subscription_ending')
            ->and($row->category)->toBe('billing')
            ->and($row->dedupe_key)->toBe("renewal:{$subscriptionId}")
            ->and($row->data)->toBe([])
            ->and(lastMessage('phone')->collapseKey)->toBe('subscription');
    });

    test('SubscriptionLapsed sends subscription_lapsed once per event', function () {
        $event = new SubscriptionLapsed(...stamp(), userId: $this->user->id, subscriptionId: (string) Str::uuid7(), status: 'expired');

        event($event);
        event($event);

        $row = PushNotification::query()->firstOrFail();
        expect(PushNotification::query()->count())->toBe(1)->and($row->type)->toBe('subscription_lapsed')->and($row->dedupe_key)->toBe('event:'.$event->eventId);
    });

    test('ExportReady sends export_ready with the export id and never a link', function () {
        $exportId = (string) Str::uuid7();
        $event = new ExportReady(...stamp(), userId: $this->user->id, exportId: $exportId);

        event($event);
        event($event);

        $row = PushNotification::query()->firstOrFail();
        expect(PushNotification::query()->count())->toBe(1)
            ->and($row->type)->toBe('export_ready')
            ->and($row->data)->toBe(['export_id' => $exportId])
            ->and($row->dedupe_key)->toBe("export:{$exportId}")
            ->and(json_encode(lastMessage('phone')))->not->toContain('http');
    });

    test('DeletionReminderDue sends deletion_reminder keyed by the scheduled instant', function () {
        $scheduled = '2026-10-09T10:00:00.000000Z';
        $event = new DeletionReminderDue(...stamp(), userId: $this->user->id, deletionScheduledFor: $scheduled);

        event($event);
        event($event);

        $row = PushNotification::query()->firstOrFail();
        expect(PushNotification::query()->count())->toBe(1)
            ->and($row->type)->toBe('deletion_reminder')
            ->and($row->dedupe_key)->toBe("deletion_reminder:{$scheduled}")
            ->and($row->data)->toBe([])
            ->and(lastMessage('phone')->priority->value)->toBe('HIGH')
            ->and(json_encode(lastMessage('phone')))->not->toContain('2026-10-09');
    });

    test('a person pending deletion still receives, a person being purged is skipped inactive_account', function () {
        $pending = User::factory()->pendingDeletion()->create();
        Profile::factory()->create(['user_id' => $pending->id]);
        registerPhone($pending, 'pending-phone');
        $purging = User::factory()->pendingDeletion()->create();
        Profile::factory()->create(['user_id' => $purging->id]);
        User::query()->whereKey($purging->id)->update(['state' => 'purging']);

        event(new DeletionReminderDue(...stamp(), userId: $pending->id, deletionScheduledFor: '2026-10-09T10:00:00.000000Z'));
        event(new DeletionReminderDue(...stamp(), userId: $purging->id, deletionScheduledFor: '2026-10-09T10:00:00.000000Z'));

        expect(fakePush()->messagesTo('pending-phone'))->toHaveCount(1)
            ->and(PushNotification::query()->where('user_id', $purging->id)->value('skip_reason'))->toBe('inactive_account');
    });

    test('a person who turned the category off hears nothing, and mail is unaffected', function () {
        savePrefs($this->user->id, ['billing' => false]);

        event(new SubscriptionLapsed(...stamp(), userId: $this->user->id, subscriptionId: (string) Str::uuid7(), status: 'expired'));

        expect(PushNotification::query()->firstOrFail()->skip_reason)->toBe('category_off')->and(fakePush()->count())->toBe(0);
    });
});
