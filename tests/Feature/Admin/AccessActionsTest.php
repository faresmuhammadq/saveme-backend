<?php

use App\Modules\Admin\Http\Livewire\AccountPage;
use App\Modules\Billing\Infrastructure\Models\Plan;
use App\Modules\Billing\Infrastructure\Models\Subscription;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * Grant, extend and revoke from the account page (spec 021, FR-026 to FR-030a).
 */

beforeEach(function () {
    $this->freezeTime();
    ['operator' => $this->operator] = makeOperator();
    signInOperator($this->operator);
    $this->user = User::factory()->create();
    $this->plus = (string) Plan::query()->where('code', 'plus_monthly')->value('id');
});

function accessEntries(string $action): array
{
    return DB::table('audit_entries')->where('event', 'entitlement_changed')->whereRaw("detail->>'action' = ?", [$action])->get()->all();
}

function grantFrom(object $test, string $duration, string $days = '', string $reason = 'Goodwill'): Testable
{
    return Livewire::test(AccountPage::class, ['user' => $test->user->id])
        ->set('grantPlan', $test->plus)->set('grantDuration', $duration)->set('grantDays', $days)->set('grantReason', $reason)
        ->call('confirmGrant');
}

it('grants a manual subscription for 30, 90, 365 or custom days and for life, one entry each', function (string $duration, string $days, ?int $expectedDays) {
    grantFrom($this, $duration, $days)
        ->assertHasNoErrors()
        ->assertDispatched('admin-toast', tone: 'success', message: 'Access granted.');

    $row = Subscription::query()->where('user_id', $this->user->id)->sole();
    $entries = accessEntries('grant');

    expect($row->provider)->toBe('manual')
        ->and($expectedDays === null ? $row->current_period_end : $row->current_period_end->equalTo(now()->addDays($expectedDays)))->toBe($expectedDays === null ? null : true)
        ->and($entries)->toHaveCount(1)
        ->and($entries[0]->actor_type)->toBe('operator')
        ->and($entries[0]->actor_id)->toBe($this->operator->id)
        ->and($entries[0]->subject_id)->toBe($row->id)
        ->and(json_decode($entries[0]->detail, true)['days'] ?? null)->toBe($expectedDays);
})->with([
    '30 days' => ['30', '', 30],
    '90 days' => ['90', '', 90],
    '365 days' => ['365', '', 365],
    'custom' => ['custom', '45', 45],
    'lifetime' => ['life', '', null],
]);

it('refreshes the governing plan on the page after a grant', function () {
    $component = Livewire::test(AccountPage::class, ['user' => $this->user->id])->assertSee('Free');

    $component->set('grantPlan', $this->plus)->set('grantDuration', '30')->set('grantReason', 'Goodwill')->call('confirmGrant')
        ->assertSee('Plus · Monthly')
        ->assertSeeHtml('data-test="governing"');
});

it('offers only active non-default plans for a grant', function () {
    Plan::factory()->create(['status' => 'retired', 'name' => ['en' => 'Retired plan', 'ar' => 'x']]);

    Livewire::test(AccountPage::class, ['user' => $this->user->id])
        ->assertSee('Plus · Monthly')
        ->assertDontSee('Retired plan');
});

it('changes nothing on a field error in a grant', function (string $duration, string $days, string $reason, string $field) {
    grantFrom($this, $duration, $days, $reason)->assertHasErrors($field);

    expect(Subscription::query()->count())->toBe(0)
        ->and(accessEntries('grant'))->toBe([]);
})->with([
    'zero days' => ['custom', '0', 'x', 'grantDays'],
    'too many days' => ['custom', '3651', 'x', 'grantDays'],
    'not a number' => ['custom', 'abc', 'x', 'grantDays'],
    'blank reason' => ['30', '', '   ', 'grantReason'],
    'long reason' => ['30', '', str_repeat('a', 501), 'grantReason'],
]);

it('extends a manual subscription and writes one entry', function () {
    $sub = Subscription::factory()->create(['user_id' => $this->user->id]);
    $end = $sub->current_period_end;

    Livewire::test(AccountPage::class, ['user' => $this->user->id])
        ->call('startExtend', $sub->id)->set('extendDays', '15')->set('extendReason', 'Support fix')->call('confirmExtend')
        ->assertHasNoErrors()
        ->assertDispatched('admin-toast', tone: 'success', message: 'Subscription extended.');

    expect($sub->fresh()->current_period_end->equalTo($end->addDays(15)))->toBeTrue()
        ->and(accessEntries('extend'))->toHaveCount(1);
});

it('changes nothing on a field error in an extension', function (string $days, string $reason, string $field) {
    $sub = Subscription::factory()->create(['user_id' => $this->user->id]);
    $end = $sub->current_period_end;

    Livewire::test(AccountPage::class, ['user' => $this->user->id])
        ->call('startExtend', $sub->id)->set('extendDays', $days)->set('extendReason', $reason)->call('confirmExtend')
        ->assertHasErrors($field);

    expect($sub->fresh()->current_period_end->equalTo($end))->toBeTrue()
        ->and(accessEntries('extend'))->toBe([]);
})->with([
    'zero' => ['0', 'x', 'extendDays'],
    'too many' => ['3651', 'x', 'extendDays'],
    'blank reason' => ['5', '', 'extendReason'],
    'long reason' => ['5', str_repeat('a', 501), 'extendReason'],
]);

it('revokes a manual or instapay subscription and writes one entry', function (string $provider) {
    $sub = Subscription::factory()->provider($provider)->create(['user_id' => $this->user->id]);

    Livewire::test(AccountPage::class, ['user' => $this->user->id])
        ->call('startRevoke', $sub->id)->set('revokeReason', 'Refunded by hand')->call('confirmRevoke')
        ->assertDispatched('admin-toast', tone: 'success', message: 'Subscription revoked.');

    expect($sub->fresh()->status)->toBe('revoked')
        ->and(accessEntries('revoke'))->toHaveCount(1);
})->with(['manual', 'instapay']);

it('requires a reason to revoke', function () {
    $sub = Subscription::factory()->create(['user_id' => $this->user->id]);

    Livewire::test(AccountPage::class, ['user' => $this->user->id])
        ->call('startRevoke', $sub->id)->set('revokeReason', '  ')->call('confirmRevoke')
        ->assertHasErrors('revokeReason');

    expect($sub->fresh()->status)->toBe('active');
});

it('offers no action on a google play subscription, only the right ones on the others', function () {
    $google = Subscription::factory()->provider('google_play')->create(['user_id' => $this->user->id]);
    $instapay = Subscription::factory()->provider('instapay')->create(['user_id' => $this->user->id]);
    $manual = Subscription::factory()->create(['user_id' => $this->user->id]);

    Livewire::test(AccountPage::class, ['user' => $this->user->id])
        ->assertDontSeeHtml('data-test="extend-'.$google->id.'"')
        ->assertDontSeeHtml('data-test="revoke-'.$google->id.'"')
        ->assertDontSeeHtml('data-test="extend-'.$instapay->id.'"')
        ->assertSeeHtml('data-test="revoke-'.$instapay->id.'"')
        ->assertSeeHtml('data-test="extend-'.$manual->id.'"')
        ->assertSeeHtml('data-test="revoke-'.$manual->id.'"')
        ->assertSeeHtml('data-test="grant"');
});

it('shows the refusal message and changes nothing when the rule is broken by a forged call', function () {
    $google = Subscription::factory()->provider('google_play')->create(['user_id' => $this->user->id]);

    Livewire::test(AccountPage::class, ['user' => $this->user->id])
        ->call('startExtend', $google->id)->set('extendDays', '5')->set('extendReason', 'x')->call('confirmExtend')
        ->assertDispatched('admin-toast', tone: 'error', message: 'Only manual subscriptions with an end date can be extended.');

    Livewire::test(AccountPage::class, ['user' => $this->user->id])
        ->call('startRevoke', $google->id)->set('revokeReason', 'x')->call('confirmRevoke')
        ->assertDispatched('admin-toast', tone: 'error', message: 'Google Play subscriptions are managed by Google Play.');

    expect($google->fresh()->status)->toBe('active')
        ->and(DB::table('audit_entries')->where('event', 'entitlement_changed')->count())->toBe(0);
});

it('shows the refusal when the account is no longer active', function () {
    $component = Livewire::test(AccountPage::class, ['user' => $this->user->id])
        ->set('grantPlan', $this->plus)->set('grantDuration', '30')->set('grantReason', 'Goodwill');
    User::query()->whereKey($this->user->id)->update([
        'state' => 'pending_deletion', 'deletion_requested_at' => now(), 'deletion_scheduled_for' => now()->addDays(30),
    ]);

    $component->call('confirmGrant')->assertDispatched('admin-toast', tone: 'error', message: 'This account isn’t active.');

    expect(Subscription::query()->count())->toBe(0);
});

it('asks for a plan instead of failing when none was chosen', function () {
    Livewire::test(AccountPage::class, ['user' => $this->user->id])
        ->set('grantPlan', '')->set('grantDuration', '30')->set('grantReason', 'Goodwill')
        ->call('confirmGrant')
        ->assertHasErrors('grantPlan');

    expect(Subscription::query()->where('user_id', $this->user->id)->count())->toBe(0)
        ->and(accessEntries('grant'))->toBe([]);
});

it('shows the plan message when the chosen plan cannot be granted', function () {
    Livewire::test(AccountPage::class, ['user' => $this->user->id])
        ->set('grantPlan', (string) Plan::query()->where('code', 'free')->value('id'))->set('grantDuration', '30')->set('grantReason', 'x')
        ->call('confirmGrant')
        ->assertDispatched('admin-toast', tone: 'error', message: 'This plan can’t be granted.');
});
