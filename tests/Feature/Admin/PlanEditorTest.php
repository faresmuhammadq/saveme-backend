<?php

use App\Modules\Admin\Http\Livewire\PlanEditor;
use App\Modules\Billing\Infrastructure\Models\PaymentClaim;
use App\Modules\Billing\Infrastructure\Models\Plan;
use App\Modules\Billing\Infrastructure\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * The plan editor (spec 021, US4; FR-032 to FR-038).
 */

beforeEach(function () {
    $this->freezeTime();
    ['operator' => $this->operator] = makeOperator();
    signInOperator($this->operator);
    $this->plan = Plan::query()->where('code', 'plus_monthly')->sole();
});

function planEntries(): array
{
    return DB::table('audit_entries')->where('event', 'admin_action')->where('subject_type', 'plan')->get()->all();
}

it('answers 404 for an unknown or malformed plan', function () {
    $this->get(route('admin.plans.edit', ['plan' => (string) Str::uuid7()]))->assertNotFound();
    $this->get('/admin/plans/not-a-uuid')->assertNotFound();
});

it('loads the stored values into the draft, without the plan code', function () {
    $component = Livewire::test(PlanEditor::class, ['plan' => $this->plan->id])
        ->assertSet('draft.name_en', 'Plus · Monthly')
        ->assertSet('draft.name_ar', 'بلس · شهري')
        ->assertSet('draft.sort_order', '2')
        ->assertSet('draft.price_on', true)
        ->assertSet('draft.price_amount', '14900')
        ->assertSet('draft.price_currency', 'EGP')
        ->assertSet('draft.flag.sharing_enabled', true)
        ->assertSet('draft.quota.sharing_max_members_per_wallet', '5')
        ->assertSet('draft.unlimited.wallets_max', true)
        ->assertDontSeeHtml('data-test="unsaved-bar"')
        ->assertSeeHtml('dir="rtl"');

    expect($component->html())->not->toContain('plus_monthly');
});

it('lists the unsaved changes in a bar and discards them', function () {
    Livewire::test(PlanEditor::class, ['plan' => $this->plan->id])
        ->set('draft.name_en', 'Plus Monthly')
        ->set('draft.price_amount', '19900')
        ->assertSeeHtml('data-test="unsaved-bar"')
        ->assertSee('2 unsaved changes')
        ->call('discard')
        ->assertSet('draft.name_en', 'Plus · Monthly')
        ->assertDontSeeHtml('data-test="unsaved-bar"');
});

it('says nothing to save for an untouched draft and opens no dialog', function () {
    Livewire::test(PlanEditor::class, ['plan' => $this->plan->id])
        ->call('review')
        ->assertDispatched('admin-toast', tone: 'info', message: 'Nothing to save.')
        ->assertNotDispatched('admin-dialog-open');
});

it('shows field errors and opens no dialog for an invalid draft', function () {
    Livewire::test(PlanEditor::class, ['plan' => $this->plan->id])
        ->set('draft.name_en', '   ')->set('draft.sort_order', '1000')->set('draft.price_amount', '50')
        ->set('draft.quota.wallets_max', '0')->set('draft.unlimited.wallets_max', false)
        ->call('review')
        ->assertHasErrors(['draft.name_en', 'draft.sort_order', 'draft.price_amount', 'draft.quota.wallets_max'])
        ->assertNotDispatched('admin-dialog-open');
});

it('reviews with before and after on screen, the submitted claims and the people who keep the plan', function () {
    PaymentClaim::factory()->count(2)->create(['plan_id' => $this->plan->id]);
    Subscription::factory()->forPlan('plus_monthly')->count(11)->create();

    Livewire::test(PlanEditor::class, ['plan' => $this->plan->id])
        ->set('draft.price_amount', '19900')
        ->set('draft.quota.sharing_max_members_per_wallet', '3')
        ->call('review')
        ->assertDispatched('admin-dialog-open', name: 'review-plan')
        ->assertSeeHtml('data-test="change-instapay"')
        ->assertSee('EGP 149')->assertSee('EGP 199')
        ->assertSee('2 submitted claims name this plan')
        ->assertSee('11 people are on this plan and keep it')
        ->assertSeeHtml('data-test="members-note"');
});

it('does not warn about members when the limit is not lowered', function () {
    Livewire::test(PlanEditor::class, ['plan' => $this->plan->id])
        ->set('draft.quota.sharing_max_members_per_wallet', '9')
        ->assertDontSeeHtml('data-test="members-note"');
});

it('saves with a reason after a fresh code, writing one entry with field names only', function () {
    $component = Livewire::test(PlanEditor::class, ['plan' => $this->plan->id])
        ->set('draft.name_en', 'Plus Monthly')->set('draft.price_amount', '19900')
        ->call('review')
        ->set('reason', 'Spring pricing')
        ->call('confirmSave')
        ->assertDispatched('admin-toast', tone: 'success', message: 'Plan saved.')
        ->assertSet('draft.name_en', 'Plus Monthly');

    $this->plan->refresh();
    $entries = planEntries();
    expect($this->plan->name['en'])->toBe('Plus Monthly')
        ->and($this->plan->prices[0]['amount_minor'])->toBe(19900)
        ->and($entries)->toHaveCount(1)
        ->and(json_decode($entries[0]->detail, true))->toBe(['action' => 'plan_updated', 'fields' => 'name.en,prices.instapay', 'reason' => 'Spring pricing'])
        ->and($component->html())->not->toContain('data-test="unsaved-bar"');
});

it('requires a reason of 1 to 500 characters before asking for the code', function () {
    Livewire::test(PlanEditor::class, ['plan' => $this->plan->id])
        ->set('draft.sort_order', '7')
        ->call('review')->set('reason', '   ')->call('confirmSave')
        ->assertHasErrors('reason')
        ->assertSet('factorOpen', false)
        ->set('reason', str_repeat('x', 501))->call('confirmSave')
        ->assertHasErrors('reason');

    expect(planEntries())->toBe([]);
});

it('refuses a stale save, shows the message with Reload, and changes nothing', function () {
    $component = Livewire::test(PlanEditor::class, ['plan' => $this->plan->id])->set('draft.sort_order', '7');
    Plan::query()->whereKey($this->plan->id)->update(['sort_order' => 5, 'updated_at' => now()->addSecond()]);

    $component->call('review')->set('reason', 'Mine')->call('confirmSave')
        ->assertSet('stale', true)
        ->assertSeeHtml('data-test="stale-alert"')
        ->assertSeeHtml('data-test="reload"')
        ->assertDispatched('admin-toast', tone: 'error', message: 'This plan was changed by someone else. Reload to see the current values.');

    expect($this->plan->refresh()->sort_order)->toBe(5)
        ->and(planEntries())->toBe([]);

    $component->call('reload')->assertSet('stale', false)->assertSet('draft.sort_order', '5');
});

it('locks the default plan rows and refuses a forged change to them', function () {
    $free = Plan::query()->where('code', 'free')->sole();

    $component = Livewire::test(PlanEditor::class, ['plan' => $free->id])
        ->assertSeeHtml('data-test="locked-export_enabled"')
        ->assertSeeHtml('data-test="locked-reports_advanced"')
        ->assertSeeHtml('data-test="locked-wallets_max"')
        ->assertDontSeeHtml('data-test="locked-sharing_enabled"')
        ->assertDontSeeHtml('data-test="retire"');

    $component->set('draft.flag.export_enabled', false)->call('review')->assertHasErrors('draft.flag.export_enabled');
    expect($free->refresh()->features['export.enabled'])->toBeTrue();
});

it('retires a plan with counts, a reason and a fresh code, writing one entry', function () {
    PaymentClaim::factory()->create(['plan_id' => $this->plan->id]);

    Livewire::test(PlanEditor::class, ['plan' => $this->plan->id])
        ->assertSeeHtml('data-test="retire"')
        ->call('startRetire')
        ->assertDispatched('admin-dialog-open', name: 'retire-plan')
        ->assertSee('1 submitted claim names this plan')
        ->set('retireReason', '   ')->call('confirmRetire')->assertHasErrors('retireReason')
        ->set('retireReason', 'Replaced')->call('confirmRetire')
        ->assertDispatched('admin-toast', tone: 'success', message: 'Plan retired.')
        ->assertDontSeeHtml('data-test="retire"')
        ->assertSeeHtml('data-test="retired-note"');

    $entries = planEntries();
    expect($this->plan->refresh()->status)->toBe('retired')
        ->and($entries)->toHaveCount(1)
        ->and(json_decode($entries[0]->detail, true))->toBe(['action' => 'plan_retired', 'reason' => 'Replaced']);
});

it('refuses to start retiring the default plan', function () {
    Livewire::test(PlanEditor::class, ['plan' => Plan::query()->where('code', 'free')->value('id')])
        ->call('startRetire')->assertNotFound();
});

it('shows no value of the draft in the audit entry even when the price and capabilities change', function () {
    Livewire::test(PlanEditor::class, ['plan' => $this->plan->id])
        ->set('draft.price_amount', '77777')->set('draft.quota.wallets_max', '4')->set('draft.unlimited.wallets_max', false)
        ->call('review')->set('reason', 'Tidy')->call('confirmSave');

    $raw = (string) planEntries()[0]->detail;
    expect($raw)->not->toContain('77777')->and($raw)->toContain('features.wallets.max');
});
