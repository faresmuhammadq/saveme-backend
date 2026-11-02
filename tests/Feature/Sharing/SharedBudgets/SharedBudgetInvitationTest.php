<?php

use App\Modules\Billing\Domain\Refusals\FeatureNotEntitled;
use App\Modules\Budgeting\Domain\SharedBudgets\Ports\SharedBudgetRepository;
use App\Modules\Budgeting\Domain\SharedBudgets\Refusals\SharedBudgetActionForbidden;
use App\Modules\Budgeting\Domain\SharedBudgets\Refusals\SharedBudgetNotFound;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sharing\Application\EnforceMemberQuota;
use App\Modules\Sharing\Application\SharedBudgets\ExpireSharedBudgetInvitations;
use App\Modules\Sharing\Application\SharedBudgets\SharedBudgetInvitationResponses;
use App\Modules\Sharing\Application\SharedBudgets\SharedBudgetInvitationService;
use App\Modules\Sharing\Domain\InvitationStatus;
use App\Modules\Sharing\Domain\Refusals\InvitationVersionConflict;
use App\Modules\Sharing\Domain\Refusals\TokenNotLive;
use App\Modules\Sharing\Domain\SharedBudgets\SharedBudgetInvitationRepository;
use App\Modules\Sharing\Http\Resources\SharedBudgetInvitationResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Shared Budget invitations (ADR-0027 rules 3, 14, 15): link only, owner only,
 * seats from the owner's plan, one answer for a token that does not work.
 */

function sendBudgetInvitation(string $owner, string $budgetId, ?string $invitationId = null): array
{
    $invitationId ??= (string) Str::uuid7();
    $saved = app(SharedBudgetInvitationService::class)->send($owner, $budgetId, $invitationId, 0, SharedBudgetInvitationResource::toArray(...));

    return [$saved, $invitationId, substr((string) $saved->shareUrl, strpos((string) $saved->shareUrl, '=') + 1)];
}

beforeEach(function () {
    $this->owner = User::factory()->create()->id;
    $this->invitee = User::factory()->create()->id;
    $this->budget = createSharedBudget($this->owner);
    $this->service = app(SharedBudgetInvitationService::class);
    $this->responses = app(SharedBudgetInvitationResponses::class);
    $this->present = SharedBudgetInvitationResource::toArray(...);
    grantSharing($this->owner);
});

test('the owner gets a link, and the token is stored only as its hash', function () {
    [$saved, $id, $token] = sendBudgetInvitation($this->owner, $this->budget);

    expect($saved->created)->toBeTrue()
        ->and($saved->shareUrl)->toContain('/invite/shared-budget?token=')
        ->and(DB::table('shared_budget_invitations')->where('id', $id)->value('token_hash'))->toBe(hash('sha256', $token))
        ->and(json_encode(DB::table('shared_budget_invitations')->get()))->not->toContain($token);
});

test('a replay of the create returns the invitation and no second link', function () {
    [, $id] = sendBudgetInvitation($this->owner, $this->budget);

    $again = $this->service->send($this->owner, $this->budget, $id, 0, $this->present);

    expect($again->created)->toBeFalse()->and($again->shareUrl)->toBeNull();
});

test('only the owner invites; a member is forbidden and a stranger finds nothing', function () {
    joinSharedBudget($this->budget, $this->invitee);
    $stranger = User::factory()->create()->id;

    expect(fn () => $this->service->send($this->invitee, $this->budget, (string) Str::uuid7(), 0, $this->present))->toThrow(SharedBudgetActionForbidden::class)
        ->and(fn () => $this->service->send($stranger, $this->budget, (string) Str::uuid7(), 0, $this->present))->toThrow(SharedBudgetNotFound::class);
});

test('a plan without sharing, or without a seat, refuses a new invitation', function () {
    revokeSharing($this->owner);
    expect(fn () => sendBudgetInvitation($this->owner, $this->budget))->toThrow(FeatureNotEntitled::class);

    grantSharing($this->owner, seats: 1);
    expect(fn () => sendBudgetInvitation($this->owner, $this->budget))->toThrow(FeatureNotEntitled::class);
});

test('accepting joins as a member, closes the invitation and stamps both feeds', function () {
    [, $id, $token] = sendBudgetInvitation($this->owner, $this->budget);

    [$member] = $this->responses->accept($this->invitee, $token);

    $invitation = app(SharedBudgetInvitationRepository::class)->find($id);
    expect($member->userId)->toBe($this->invitee)
        ->and($member->isOwner())->toBeFalse()
        ->and($member->memberChangeSeq)->toBeGreaterThan(0)
        ->and($invitation->status)->toBe(InvitationStatus::Accepted)
        ->and($invitation->isTombstone())->toBeTrue();
});

test('accepting twice returns the same membership and a used token then fails for anyone else', function () {
    [, , $token] = sendBudgetInvitation($this->owner, $this->budget);
    $other = User::factory()->create()->id;

    [$first] = $this->responses->accept($this->invitee, $token);
    [$second] = $this->responses->accept($this->invitee, $token);

    expect($second->id)->toBe($first->id)
        ->and(fn () => $this->responses->accept($other, $token))->toThrow(TokenNotLive::class);
});

test('an unknown, revoked, declined or expired token is the same answer', function () {
    [, $revokedId, $revoked] = sendBudgetInvitation($this->owner, $this->budget);
    $this->service->revoke($this->owner, $this->budget, $revokedId, 1, $this->present);
    [, , $declined] = sendBudgetInvitation($this->owner, $this->budget);
    $this->responses->decline($this->invitee, $declined);
    [, $expiredId, $expired] = sendBudgetInvitation($this->owner, $this->budget);
    DB::table('shared_budget_invitations')->where('id', $expiredId)->update(['expires_at' => now()->subMinute()]);

    foreach ([Str::random(43), $revoked, $declined, $expired, 'short'] as $token) {
        expect(fn () => $this->responses->accept($this->invitee, $token))->toThrow(TokenNotLive::class);
    }
});

test('a seat is checked again when accepting', function () {
    [, , $token] = sendBudgetInvitation($this->owner, $this->budget);
    grantSharing($this->owner, seats: 1);

    expect(fn () => $this->responses->accept($this->invitee, $token))->toThrow(FeatureNotEntitled::class);
});

test('the preview shows the budget and its owner and spends nothing', function () {
    [, $id, $token] = sendBudgetInvitation($this->owner, $this->budget);

    $preview = $this->responses->preview($this->invitee, $token);

    expect($preview->name)->toBe('Home')
        ->and($preview->currency)->toBe('EGP')
        ->and($preview->memberCount)->toBe(1)
        ->and($preview->alreadyMember)->toBeFalse()
        ->and(app(SharedBudgetInvitationRepository::class)->find($id)->status)->toBe(InvitationStatus::Pending);
});

test('the sweep closes an expired invitation with its own stamp', function () {
    [, $id] = sendBudgetInvitation($this->owner, $this->budget);
    DB::table('shared_budget_invitations')->where('id', $id)->update(['expires_at' => now()->subHour()]);

    $closed = app(ExpireSharedBudgetInvitations::class)->run(now()->toDateTimeImmutable());

    $invitation = app(SharedBudgetInvitationRepository::class)->find($id);
    expect($closed)->toBe(1)->and($invitation->status)->toBe(InvitationStatus::Expired)->and($invitation->version)->toBe(2);
});

test('a stale revoke is a version conflict and revoking a closed invitation changes nothing', function () {
    [, $id] = sendBudgetInvitation($this->owner, $this->budget);

    expect(fn () => $this->service->revoke($this->owner, $this->budget, $id, 9, $this->present))->toThrow(InvitationVersionConflict::class);

    $first = $this->service->revoke($this->owner, $this->budget, $id, 1, $this->present);
    $second = $this->service->revoke($this->owner, $this->budget, $id, 1, $this->present);
    expect($second->version)->toBe($first->version);
});

test('the departing owner\'s pending invitations are withdrawn by the system', function () {
    [, $id] = sendBudgetInvitation($this->owner, $this->budget);

    expect($this->service->revokeForDepartingInviter($this->budget, $id))->toBeTrue()
        ->and($this->service->revokeForDepartingInviter($this->budget, $id))->toBeFalse()
        ->and(app(SharedBudgetRepository::class)->find($this->budget)->version)->toBeGreaterThanOrEqual(1);
});

test('a lower quota makes the members beyond it read-only, owner first, and nobody leaves', function () {
    joinSharedBudget($this->budget, $this->invitee);
    $third = User::factory()->create()->id;
    joinSharedBudget($this->budget, $third);
    grantSharing($this->owner, seats: 2);

    $summary = app(EnforceMemberQuota::class)->forOwner($this->owner);

    $repository = app(SharedBudgetRepository::class);
    expect($summary['demoted'])->toBe(1)
        ->and($repository->activeMember($this->budget, $this->owner)->readOnly)->toBeFalse()
        ->and($repository->activeMember($this->budget, $this->invitee)->readOnly)->toBeFalse()
        ->and($repository->activeMember($this->budget, $third)->readOnly)->toBeTrue()
        ->and(app(EnforceMemberQuota::class)->forOwner($this->owner)['demoted'])->toBe(0);
});
