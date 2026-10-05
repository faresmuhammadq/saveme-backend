<?php

/*
| Every state-changing back-office action of spec 021 sits behind a second-factor
| proof from the last 10 minutes (FR-006; spec 018 RequiresRecentFactor).
|
| Each stage of delivery adds its actions to `recentFactorGateScenario()` and
| its name to the dataset. A scenario starts the action on a fresh component
| and says what "nothing changed" means for it:
|   - start:      drives the component until the factor prompt would show
|   - untouched:  true while the stored data is exactly as arranged
|   - events:     the audit events the action itself would write
|   - class/method: the component and its protected apply method
*/

use App\Modules\Admin\Http\Auth\OperatorSession;
use App\Modules\Admin\Http\Livewire\AccountPage;
use App\Modules\Admin\Http\Livewire\ClaimReview;
use App\Modules\Admin\Http\Livewire\PlanEditor;
use App\Modules\Billing\Infrastructure\Models\PaymentClaim;
use App\Modules\Billing\Infrastructure\Models\Plan;
use App\Modules\Billing\Infrastructure\Models\RedeemCode;
use App\Modules\Billing\Infrastructure\Models\Subscription;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

dataset('state-changing back-office actions', [
    'approve a claim',
    'reject a claim',
    'revoke a redeem code',
    'grant access',
    'extend access',
    'revoke access',
    'save a plan',
    'retire a plan',
]);

/**
 * @return array{start: Closure(): Testable, untouched: Closure(): bool, events: list<string>, class: class-string, method: string}
 */
function recentFactorGateScenario(string $name): array
{
    return match ($name) {
        'approve a claim' => (function (): array {
            $claim = PaymentClaim::factory()->create();

            return [
                'start' => fn (): Testable => Livewire::test(ClaimReview::class, ['claim' => $claim->id])->call('confirmApprove'),
                'untouched' => fn (): bool => PaymentClaim::query()->find($claim->id)->status === 'submitted',
                'events' => ['claim_reviewed'],
                'class' => ClaimReview::class,
                'method' => 'applyApprove',
            ];
        })(),
        'reject a claim' => (function (): array {
            $claim = PaymentClaim::factory()->create();

            return [
                'start' => fn (): Testable => Livewire::test(ClaimReview::class, ['claim' => $claim->id])
                    ->set('rejectReason', 'other')->set('rejectNote', 'checked')->call('confirmReject'),
                'untouched' => fn (): bool => PaymentClaim::query()->find($claim->id)->status === 'submitted',
                'events' => ['claim_reviewed'],
                'class' => ClaimReview::class,
                'method' => 'applyReject',
            ];
        })(),
        'revoke a redeem code' => (function (): array {
            $claim = PaymentClaim::factory()->approved()->create();
            $code = RedeemCode::factory()->create(['claim_id' => $claim->id]);

            return [
                'start' => fn (): Testable => Livewire::test(ClaimReview::class, ['claim' => $claim->id])
                    ->call('revokeCode')->call('confirmReason', 'Paid twice'),
                'untouched' => fn (): bool => RedeemCode::query()->find($code->id)->status === 'issued',
                'events' => ['claim_reviewed'],
                'class' => ClaimReview::class,
                'method' => 'applyRevoke',
            ];
        })(),
        'grant access' => (function (): array {
            $user = User::factory()->create();

            return [
                'start' => fn (): Testable => Livewire::test(AccountPage::class, ['user' => $user->id])
                    ->set('grantPlan', (string) Plan::query()->where('code', 'plus_monthly')->value('id'))
                    ->set('grantDuration', '30')->set('grantReason', 'Goodwill')->call('confirmGrant'),
                'untouched' => fn (): bool => Subscription::query()->where('user_id', $user->id)->count() === 0,
                'events' => ['entitlement_changed'],
                'class' => AccountPage::class,
                'method' => 'applyGrant',
            ];
        })(),
        'extend access' => (function (): array {
            $user = User::factory()->create();
            $subscription = Subscription::factory()->create(['user_id' => $user->id]);
            $end = $subscription->current_period_end;

            return [
                'start' => fn (): Testable => Livewire::test(AccountPage::class, ['user' => $user->id])
                    ->call('startExtend', $subscription->id)->set('extendDays', '10')->set('extendReason', 'Support fix')->call('confirmExtend'),
                'untouched' => fn (): bool => Subscription::query()->find($subscription->id)->current_period_end->equalTo($end),
                'events' => ['entitlement_changed'],
                'class' => AccountPage::class,
                'method' => 'applyExtend',
            ];
        })(),
        'revoke access' => (function (): array {
            $user = User::factory()->create();
            $subscription = Subscription::factory()->create(['user_id' => $user->id]);

            return [
                'start' => fn (): Testable => Livewire::test(AccountPage::class, ['user' => $user->id])
                    ->call('startRevoke', $subscription->id)->set('revokeReason', 'Refunded by hand')->call('confirmRevoke'),
                'untouched' => fn (): bool => Subscription::query()->find($subscription->id)->status === 'active',
                'events' => ['entitlement_changed'],
                'class' => AccountPage::class,
                'method' => 'applyRevoke',
            ];
        })(),
        'save a plan' => (function (): array {
            $plan = Plan::query()->where('code', 'plus_monthly')->sole();

            return [
                'start' => fn (): Testable => Livewire::test(PlanEditor::class, ['plan' => $plan->id])
                    ->set('draft.sort_order', '7')->call('review')->set('reason', 'Tidy order')->call('confirmSave'),
                'untouched' => fn (): bool => Plan::query()->find($plan->id)->sort_order === 2,
                'events' => ['admin_action'],
                'class' => PlanEditor::class,
                'method' => 'applySave',
            ];
        })(),
        'retire a plan' => (function (): array {
            $plan = Plan::query()->where('code', 'plus_yearly')->sole();

            return [
                'start' => fn (): Testable => Livewire::test(PlanEditor::class, ['plan' => $plan->id])
                    ->call('startRetire')->set('retireReason', 'Replaced')->call('confirmRetire'),
                'untouched' => fn (): bool => Plan::query()->find($plan->id)->status === 'active',
                'events' => ['admin_action'],
                'class' => PlanEditor::class,
                'method' => 'applyRetire',
            ];
        })(),
    };
}

function markFactorStale(): void
{
    session()->put(OperatorSession::FACTOR_CONFIRMED_AT, now()->subMinutes(11)->getTimestamp());
}

function auditCountOf(array $events): int
{
    return DB::table('audit_entries')->whereIn('event', $events)->count();
}

beforeEach(function () {
    $this->freezeTime();
    Queue::fake();
    ['operator' => $this->operator, 'secret' => $this->secret] = makeOperator();
    signInOperator($this->operator);
});

it('opens the factor prompt and does nothing else when the last proof is older than 10 minutes', function (string $name) {
    $scenario = recentFactorGateScenario($name);
    markFactorStale();

    $component = $scenario['start']();

    $component->assertSet('factorOpen', true);
    expect($scenario['untouched']())->toBeTrue()
        ->and(auditCountOf($scenario['events']))->toBe(0);
})->with('state-changing back-office actions');

it('changes nothing and writes no entry for the action on a wrong code', function (string $name) {
    $scenario = recentFactorGateScenario($name);
    markFactorStale();

    $scenario['start']()->call('confirmFactor', '000000')->assertHasErrors('one_time_code')->assertSet('factorOpen', true);

    expect($scenario['untouched']())->toBeTrue()
        ->and(auditCountOf($scenario['events']))->toBe(0);
})->with('state-changing back-office actions');

it('runs the action after a correct new code', function (string $name) {
    $scenario = recentFactorGateScenario($name);
    markFactorStale();

    $component = $scenario['start']();
    $this->travel(30)->seconds();
    $component->call('confirmFactor', currentCode($this->secret, 1))->assertSet('factorOpen', false);

    expect($scenario['untouched']())->toBeFalse()
        ->and(auditCountOf($scenario['events']))->toBe(1);
})->with('state-changing back-office actions');

it('keeps the apply method protected so the browser cannot call it', function (string $name) {
    $scenario = recentFactorGateScenario($name);

    expect((new ReflectionMethod($scenario['class'], $scenario['method']))->isProtected())->toBeTrue();
})->with('state-changing back-office actions');
