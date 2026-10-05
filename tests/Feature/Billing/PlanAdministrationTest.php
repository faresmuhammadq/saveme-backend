<?php

use App\Modules\Admin\Application\Plans\PlanChanges;
use App\Modules\Billing\Application\Claims\ClaimService;
use App\Modules\Billing\Application\Claims\SubmitClaim;
use App\Modules\Billing\Application\Plans\PlanAdministration;
use App\Modules\Billing\Application\Plans\PlanAdminQuery;
use App\Modules\Billing\Application\Subscriptions\OperatorSubscriptions;
use App\Modules\Billing\Domain\Plans\PlanChangeSet;
use App\Modules\Billing\Domain\Refusals\ClaimInvalid;
use App\Modules\Billing\Domain\Refusals\InvalidReason;
use App\Modules\Billing\Domain\Refusals\NothingToSave;
use App\Modules\Billing\Domain\Refusals\PlanChangedElsewhere;
use App\Modules\Billing\Domain\Refusals\PlanEditInvalid;
use App\Modules\Billing\Domain\Refusals\PlanNotGrantable;
use App\Modules\Billing\Domain\Refusals\PlanNotRetirable;
use App\Modules\Billing\Infrastructure\Models\PaymentClaim;
use App\Modules\Billing\Infrastructure\Models\Plan;
use App\Modules\Billing\Infrastructure\Models\Subscription;
use App\Modules\Identity\Infrastructure\Models\AuditEntry;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 * Editing the catalogue (spec 021, US4; FR-031 to FR-038, R-5, R-6).
 */

beforeEach(function () {
    $this->freezeTime();
    Queue::fake();
    $this->operator = (string) Str::uuid7();
    $this->plan = Plan::query()->where('code', 'plus_monthly')->sole();
    $this->admin = fn (): PlanAdministration => app(PlanAdministration::class);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function draftOfPlan(Plan $plan, array $overrides = []): PlanChangeSet
{
    $plan->refresh();
    $price = collect($plan->prices)->firstWhere('channel', 'instapay');

    return PlanChangeSet::fromDraft([
        'name' => $plan->name,
        'sort_order' => $plan->sort_order,
        'instapay_price' => $price === null ? null : ['amount_minor' => $price['amount_minor'], 'currency' => $price['currency']],
        'features' => $plan->features,
        ...$overrides,
    ]);
}

function versionOfPlan(Plan $plan): string
{
    return app(PlanAdminQuery::class)->find($plan->id)->version;
}

function adminPlanEntries(): array
{
    return AuditEntry::query()->where('event', 'admin_action')->where('subject_type', 'plan')->get()->all();
}

test('an update applies every change together and moves the version on', function () {
    $before = versionOfPlan($this->plan);

    $fields = ($this->admin)()->update($this->plan->id, $before, draftOfPlan($this->plan, [
        'name' => ['en' => 'Plus Monthly', 'ar' => 'بلس الشهرية'],
        'sort_order' => 9,
        'instapay_price' => ['amount_minor' => 19900, 'currency' => 'EGP'],
        'features' => [...$this->plan->features, 'wallets.max' => 8],
    ]));

    $this->plan->refresh();
    expect($fields)->toBe(['features.wallets.max', 'name.ar', 'name.en', 'prices.instapay', 'sort_order'])
        ->and($this->plan->name)->toEqual(['en' => 'Plus Monthly', 'ar' => 'بلس الشهرية'])
        ->and($this->plan->sort_order)->toBe(9)
        ->and($this->plan->features['wallets.max'])->toBe(8)
        ->and($this->plan->prices[0]['amount_minor'])->toBe(19900)
        ->and(versionOfPlan($this->plan))->not->toBe($before);
});

test('an invalid edit changes nothing at all', function () {
    $before = Plan::query()->find($this->plan->id)->toArray();

    expect(fn () => ($this->admin)()->update($this->plan->id, versionOfPlan($this->plan), draftOfPlan($this->plan, [
        'name' => ['en' => 'Fine', 'ar' => ''],
        'sort_order' => 5,
    ])))->toThrow(PlanEditInvalid::class);

    expect(Plan::query()->find($this->plan->id)->toArray())->toBe($before);
});

test('an identical draft is nothing to save', function () {
    expect(fn () => ($this->admin)()->update($this->plan->id, versionOfPlan($this->plan), draftOfPlan($this->plan)))->toThrow(NothingToSave::class);
});

test('a stale version is refused and changes nothing; the next save needs the new version', function () {
    $stale = versionOfPlan($this->plan);
    ($this->admin)()->update($this->plan->id, $stale, draftOfPlan($this->plan, ['sort_order' => 5]));

    expect(fn () => ($this->admin)()->update($this->plan->id, $stale, draftOfPlan($this->plan, ['sort_order' => 6])))->toThrow(PlanChangedElsewhere::class)
        ->and($this->plan->refresh()->sort_order)->toBe(5);

    ($this->admin)()->update($this->plan->id, versionOfPlan($this->plan), draftOfPlan($this->plan, ['sort_order' => 6]));

    expect($this->plan->refresh()->sort_order)->toBe(6);
});

test('an unknown plan is a change elsewhere on save and not retirable on retire', function () {
    $id = (string) Str::uuid7();

    expect(fn () => ($this->admin)()->update($id, 'x', draftOfPlan($this->plan)))->toThrow(PlanChangedElsewhere::class)
        ->and(fn () => ($this->admin)()->retire($id, 'x'))->toThrow(PlanNotRetirable::class);
});

test('the Google Play entries are carried over untouched', function () {
    $googlePlay = ['channel' => 'google_play', 'product_id' => 'plus_monthly_gp', 'amount_minor' => 15000, 'currency' => 'EGP'];
    $this->plan->update(['prices' => [...$this->plan->prices, $googlePlay]]);

    ($this->admin)()->update($this->plan->id, versionOfPlan($this->plan), draftOfPlan($this->plan, ['instapay_price' => ['amount_minor' => 20000, 'currency' => 'EGP']]));

    expect($this->plan->refresh()->prices)->toEqual([['channel' => 'instapay', 'product_id' => null, 'amount_minor' => 20000, 'currency' => 'EGP'], $googlePlay]);
});

test('the public catalogue reflects the saved plan', function () {
    ($this->admin)()->update($this->plan->id, versionOfPlan($this->plan), draftOfPlan($this->plan, [
        'name' => ['en' => 'Plus Monthly', 'ar' => 'بلس الشهرية'],
        'instapay_price' => ['amount_minor' => 19900, 'currency' => 'EGP'],
    ]));

    $data = collect($this->withHeaders(jsonHeaders())->get('/api/v1/plans')->json('data'))->firstWhere('id', $this->plan->id);

    expect($data['name'])->toBe('Plus Monthly')
        ->and($data['prices'][0]['amount_minor'])->toBe(19900);
});

test('the default plan refuses a change to a fixed capability from any caller', function () {
    $free = Plan::query()->where('code', 'free')->sole();

    expect(fn () => ($this->admin)()->update($free->id, versionOfPlan($free), draftOfPlan($free, ['features' => [...$free->features, 'export.enabled' => false]])))
        ->toThrow(PlanEditInvalid::class)
        ->and($free->refresh()->features['export.enabled'])->toBeTrue();
});

test('a new claim at the old price is refused after a price change, and a submitted claim stays approvable', function () {
    $submitted = PaymentClaim::factory()->create(['plan_id' => planIdByCode('plus_yearly'), 'amount_minor' => 129000]);
    $yearly = Plan::query()->where('code', 'plus_yearly')->sole();

    ($this->admin)()->update($yearly->id, versionOfPlan($yearly), draftOfPlan($yearly, ['instapay_price' => ['amount_minor' => 150000, 'currency' => 'EGP']]));

    expect(fn () => app(ClaimService::class)->submit(claimCommandAt(129000)))->toThrow(ClaimInvalid::class);

    app(ClaimService::class)->submit(claimCommandAt(150000));
    app(ClaimService::class)->approve($this->operator, $submitted->id);

    expect($submitted->refresh()->status)->toBe('approved');
});

function claimCommandAt(int $amount): SubmitClaim
{
    return new SubmitClaim(
        address: 'mona@example.test',
        planId: planIdByCode('plus_yearly'),
        amountMinor: $amount,
        currency: 'EGP',
        reference: '4815 1623-4211',
        locale: 'en',
        receipt: null,
    );
}

test('retire refuses the default plan and an already retired one, and a stale version', function () {
    $free = Plan::query()->where('code', 'free')->sole();

    expect(fn () => ($this->admin)()->retire($free->id, versionOfPlan($free)))->toThrow(PlanNotRetirable::class)
        ->and($free->refresh()->status)->toBe('active');

    $stale = versionOfPlan($this->plan);
    ($this->admin)()->update($this->plan->id, $stale, draftOfPlan($this->plan, ['sort_order' => 5]));

    expect(fn () => ($this->admin)()->retire($this->plan->id, $stale))->toThrow(PlanChangedElsewhere::class)
        ->and($this->plan->refresh()->status)->toBe('active');

    ($this->admin)()->retire($this->plan->id, versionOfPlan($this->plan));

    expect($this->plan->refresh()->status)->toBe('retired')
        ->and(fn () => ($this->admin)()->retire($this->plan->id, versionOfPlan($this->plan)))->toThrow(PlanNotRetirable::class);
});

test('a retired plan keeps serving its subscriptions and cannot be granted', function () {
    $user = User::factory()->create();
    $subscription = Subscription::factory()->forPlan('plus_monthly')->create(['user_id' => $user->id]);

    ($this->admin)()->retire($this->plan->id, versionOfPlan($this->plan));

    expect($subscription->refresh()->status)->toBe('active')
        ->and(app(PlanAdminQuery::class)->find($this->plan->id)->governedPeople)->toBe(1)
        ->and(fn () => app(OperatorSubscriptions::class)->grant($this->operator, User::factory()->create()->id, $this->plan->id, 30, 'Goodwill'))->toThrow(PlanNotGrantable::class);
});

test('the admin query lists every plan in order without a code, and counts governed people and submitted claims', function () {
    $monthly = $this->plan;
    Subscription::factory()->forPlan('plus_monthly')->create(['user_id' => User::factory()->create()->id]);
    $person = User::factory()->create();
    Subscription::factory()->forPlan('plus_monthly')->endingIn(10)->create(['user_id' => $person->id]);
    Subscription::factory()->forPlan('plus_yearly')->endingIn(300)->create(['user_id' => $person->id]);
    Subscription::factory()->forPlan('plus_monthly')->expired()->create(['user_id' => User::factory()->create()->id]);
    PaymentClaim::factory()->count(2)->create(['plan_id' => $monthly->id]);
    PaymentClaim::factory()->rejected()->create(['plan_id' => $monthly->id]);

    $views = app(PlanAdminQuery::class)->all();
    $byId = collect($views)->keyBy('id');

    expect(array_map(fn ($view) => $view->name['en'], $views))->toBe(['Free', 'Plus · Monthly', 'Plus · Yearly'])
        ->and($byId[$monthly->id]->governedPeople)->toBe(1)
        ->and($byId[planIdByCode('plus_yearly')]->governedPeople)->toBe(1)
        ->and($byId[planIdByCode('free')]->governedPeople)->toBeNull()
        ->and($byId[$monthly->id]->submittedClaims)->toBe(2)
        ->and($byId[$monthly->id]->instapayPrice)->toBe(['amount_minor' => 14900, 'currency' => 'EGP'])
        ->and(json_encode(array_map(fn ($view) => $view->toArray(), $views)))->not->toContain('plus_monthly', '"code"');
});

test('through Admin a save writes exactly one entry with the field names and the reason, and no value', function () {
    app(PlanChanges::class)->update($this->operator, $this->plan->id, versionOfPlan($this->plan), draftOfPlan($this->plan, [
        'instapay_price' => ['amount_minor' => 19900, 'currency' => 'EGP'],
        'features' => [...$this->plan->features, 'wallets.max' => 8],
        'name' => ['en' => 'Plus Monthly', 'ar' => 'بلس'],
    ]), 'Spring pricing');

    $entries = adminPlanEntries();
    expect($entries)->toHaveCount(1)
        ->and($entries[0]->actor_type)->toBe('operator')
        ->and($entries[0]->actor_id)->toBe($this->operator)
        ->and($entries[0]->subject_id)->toBe($this->plan->id)
        ->and($entries[0]->detail)->toBe(['action' => 'plan_updated', 'fields' => 'features.wallets.max,name.ar,name.en,prices.instapay', 'reason' => 'Spring pricing']);

    $raw = json_encode($entries[0]->detail);
    expect($raw)->not->toContain('19900')->not->toContain('Plus Monthly');
});

test('through Admin a retire writes exactly one entry with the reason', function () {
    app(PlanChanges::class)->retire($this->operator, $this->plan->id, versionOfPlan($this->plan), 'Replaced by yearly');

    $entries = adminPlanEntries();
    expect($entries)->toHaveCount(1)
        ->and($entries[0]->detail)->toBe(['action' => 'plan_retired', 'reason' => 'Replaced by yearly']);
});

test('a refused change writes no entry, and a failed entry rolls the change back', function () {
    $version = versionOfPlan($this->plan);

    expect(fn () => app(PlanChanges::class)->update($this->operator, $this->plan->id, $version, draftOfPlan($this->plan), 'No change'))->toThrow(NothingToSave::class)
        ->and(fn () => app(PlanChanges::class)->update($this->operator, $this->plan->id, $version, draftOfPlan($this->plan, ['sort_order' => 5]), '   '))->toThrow(InvalidReason::class)
        ->and(adminPlanEntries())->toBe([])
        ->and($this->plan->refresh()->sort_order)->toBe(2);

    DB::unprepared('CREATE OR REPLACE FUNCTION fail_plan_audit() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION \'audit down\'; END; $$ LANGUAGE plpgsql');
    DB::unprepared("CREATE TRIGGER fail_plan_audit BEFORE INSERT ON audit_entries FOR EACH ROW WHEN (NEW.subject_type = 'plan') EXECUTE FUNCTION fail_plan_audit()");

    try {
        expect(fn () => app(PlanChanges::class)->update($this->operator, $this->plan->id, $version, draftOfPlan($this->plan, ['sort_order' => 5]), 'Will fail'))->toThrow(Exception::class)
            ->and($this->plan->refresh()->sort_order)->toBe(2)
            ->and(versionOfPlan($this->plan))->toBe($version);
    } finally {
        DB::unprepared('DROP TRIGGER fail_plan_audit ON audit_entries');
    }
});
