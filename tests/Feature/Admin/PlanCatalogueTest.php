<?php

use App\Modules\Admin\Http\Livewire\PlanCatalogue;
use App\Modules\Admin\Http\Middleware\ResolveAdminLocale;
use App\Modules\Billing\Infrastructure\Models\Plan;
use App\Modules\Billing\Infrastructure\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * The plan catalogue (spec 021, US4; FR-031).
 */

beforeEach(function () {
    $this->freezeTime();
    ['operator' => $this->operator] = makeOperator();
    signInOperator($this->operator);
});

it('lists every plan, active and retired, in order, and never shows a plan code', function (string $locale) {
    Plan::query()->where('code', 'plus_yearly')->update(['status' => 'retired']);

    $html = $this->withSession([ResolveAdminLocale::SESSION_KEY => $locale])->get(route('admin.plans'))->assertOk()->getContent();

    foreach (Plan::query()->orderBy('sort_order')->get() as $plan) {
        expect($html)->toContain('data-test="plan-'.$plan->id.'"');
    }

    foreach (['plus_monthly', 'plus_yearly', '"free"'] as $code) {
        expect($html)->not->toContain($code);
    }
})->with(['en', 'ar']);

it('shows names, status, the default pill, order, prices and capability values', function () {
    Plan::query()->where('code', 'plus_yearly')->update(['status' => 'retired']);
    $monthly = Plan::query()->where('code', 'plus_monthly')->sole();
    $free = Plan::query()->where('code', 'free')->sole();

    Livewire::test(PlanCatalogue::class)
        ->assertSee('Plus · Monthly')->assertSee('بلس · شهري')
        ->assertSeeInOrder(['Free', 'Plus · Monthly', 'Plus · Yearly'])
        ->assertSeeHtml('data-test="default-pill"')
        ->assertSeeHtml('data-test="status-retired"')
        ->assertSeeHtml('data-test="instapay-'.$monthly->id.'"')
        ->assertSee('EGP 149')
        ->assertSee('Not sold by InstaPay')
        ->assertSee('Set up with Google Play billing')
        ->assertSeeHtml('data-test="edit-'.$free->id.'"')
        ->assertSeeHtml(route('admin.plans.edit', ['plan' => $monthly->id]));
});

it('applies the anonymity floor to people counts', function () {
    $monthly = Plan::query()->where('code', 'plus_monthly')->sole();
    $yearly = Plan::query()->where('code', 'plus_yearly')->sole();
    Subscription::factory()->forPlan('plus_monthly')->count(3)->create();
    Subscription::factory()->forPlan('plus_yearly')->count(12)->create();

    $component = Livewire::test(PlanCatalogue::class);

    expect($component->html())->toContain('data-test="governed-'.$monthly->id.'"')
        ->and(preg_match('~data-test="governed-'.$monthly->id.'"[^>]*>(?:\s|<!--.*?-->)*Fewer than 10~', $component->html()))->toBe(1)
        ->and(preg_match('~data-test="governed-'.$yearly->id.'"[^>]*>(?:\s|<!--.*?-->)*<bdi dir="ltr">12</bdi>~', $component->html()))->toBe(1);
});

it('shows zero people exactly', function () {
    $monthly = Plan::query()->where('code', 'plus_monthly')->sole();

    expect(preg_match('~data-test="governed-'.$monthly->id.'"[^>]*>(?:\s|<!--.*?-->)*<bdi dir="ltr">0</bdi>~', Livewire::test(PlanCatalogue::class)->html()))->toBe(1);
});

it('uses Western digits in Arabic', function () {
    $html = $this->withSession([ResolveAdminLocale::SESSION_KEY => 'ar'])->get(route('admin.plans'))->assertOk()->assertSee('dir="rtl"', false)->getContent();

    expect($html)->toUseWesternDigitsOnly();
});

it('writes nothing to the audit log', function () {
    $before = DB::table('audit_entries')->count();

    Livewire::test(PlanCatalogue::class);

    expect(DB::table('audit_entries')->count())->toBe($before);
});
