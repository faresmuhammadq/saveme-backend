<?php

use App\Modules\Admin\Http\Livewire\Overview;
use App\Modules\Admin\Http\Middleware\ResolveAdminLocale;
use App\Modules\Billing\Infrastructure\Models\PaymentClaim;
use App\Modules\Billing\Infrastructure\Models\Subscription;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use App\Modules\Wallets\Infrastructure\Models\WalletMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * The platform overview (spec 021, US5; FR-010 to FR-014).
 */

beforeEach(function () {
    $this->freezeTime();
    ['operator' => $this->operator] = makeOperator();
    signInOperator($this->operator);
});

function openReenrolment(string $state = 'open', int $hoursLeft = 24): void
{
    $operatorId = makeOperator()['operator']->id;

    DB::table('operator_reenrolment_requests')->insert([
        'id' => (string) Str::uuid7(),
        'operator_id' => $operatorId,
        'state' => $state,
        'opened_at' => now()->subHour(),
        'expires_at' => now()->addHours($hoursLeft),
        'closed_at' => $state === 'open' ? null : now(),
        'closed_by' => $state === 'open' ? null : $operatorId,
    ]);
}

it('shows exact counts at or above ten, zero exactly and 1 to 9 as fewer than 10', function () {
    User::factory()->count(12)->verified()->create();
    User::factory()->count(3)->pendingDeletion()->create();
    Subscription::factory()->provider('instapay')->count(4)->create();

    Livewire::test(Overview::class)
        ->assertSeeHtml('data-test="figure-accounts"')
        ->assertSet('figures.accounts', 15)
        ->assertSet('figures.verified', 12)
        ->assertSet('figures.pending_deletion', 'fewer_than_10')
        ->assertSet('figures.paid_instapay', 'fewer_than_10')
        ->assertSet('figures.paid_google_play', 0)
        ->assertSet('figures.granted_manual', 0)
        ->assertSee('fewer than 10');

    $this->get(route('admin.overview'))->assertOk()->assertSee('Platform overview')->assertSee('Counts only.');
});

it('labels a grant as granted and never as paid or revenue', function () {
    Subscription::factory()->provider('manual')->count(11)->create();

    Livewire::test(Overview::class)
        ->assertSet('figures.granted_manual', 11)
        ->assertSee('Granted · Manual')
        ->assertDontSee('revenue');
});

it('counts shared wallets with two or more live members', function () {
    foreach (range(1, 10) as $ignored) {
        $wallet = Wallet::factory()->create();
        WalletMember::factory()->owner()->create(['wallet_id' => $wallet->id, 'user_id' => User::factory()->create()->id]);
        WalletMember::factory()->editor()->create(['wallet_id' => $wallet->id, 'user_id' => User::factory()->create()->id]);
    }
    $solo = Wallet::factory()->create();
    WalletMember::factory()->owner()->create(['wallet_id' => $solo->id, 'user_id' => User::factory()->create()->id]);

    Livewire::test(Overview::class)->assertSet('figures.shared_wallets', 10);
});

it('shows what needs attention exactly with the oldest age and links', function () {
    PaymentClaim::factory()->count(5)->create(['created_at' => now()->subDays(1)]);
    PaymentClaim::factory()->create(['created_at' => now()->subDays(3)]);
    PaymentClaim::factory()->approved()->create();
    openReenrolment();
    openReenrolment(state: 'completed');
    openReenrolment(hoursLeft: -2);

    Livewire::test(Overview::class)
        ->assertSet('claimsAwaiting', 6)
        ->assertSet('reenrolmentsOpen', 1)
        ->assertSeeHtml('data-test="needs-attention"')
        ->assertSee('6 claims awaiting review')
        ->assertSee('3 days ago')
        ->assertSee('1 re-enrolment request open')
        ->assertSeeHtml(route('admin.claims'))
        ->assertSeeHtml(route('admin.reenrolment-requests'))
        ->assertDontSee('Nothing needs attention');
});

it('says nothing needs attention at zero and hides the rows', function () {
    Livewire::test(Overview::class)
        ->assertSee('Nothing needs attention')
        ->assertDontSeeHtml('data-test="attention-claims"')
        ->assertDontSeeHtml('data-test="attention-reenrolments"');
});

it('keeps the low recovery-codes alert above the figures', function () {
    ['operator' => $operator] = makeOperator();
    giveRecoveryCodes($operator, 1);
    signInOperator($operator);

    $html = $this->get(route('admin.overview'))->assertOk()->getContent();

    expect(strpos($html, 'data-test="low-codes"'))->toBeInt()
        ->and(strpos($html, 'data-test="low-codes"'))->toBeLessThan(strpos($html, 'data-test="needs-attention"'));
});

it('writes no audit entry and shows no address, identifier or amount', function () {
    $user = User::factory()->withEmail('private-person@example.test')->create();
    PaymentClaim::factory()->forAddress('payer-secret@example.test')->create();
    $before = DB::table('audit_entries')->count();

    $this->get(route('admin.overview'))->assertOk()
        ->assertDontSee('private-person')
        ->assertDontSee('payer-secret')
        ->assertDontSee($user->id)
        ->assertDontSee('EGP');

    expect(DB::table('audit_entries')->count())->toBe($before);
});

it('renders in Arabic with the floor marker in Arabic and Western digits', function () {
    User::factory()->count(12)->create();
    User::factory()->count(2)->pendingDeletion()->create();

    $this->withSession([ResolveAdminLocale::SESSION_KEY => 'ar'])->get(route('admin.overview'))->assertOk()
        ->assertSee('dir="rtl"', false)
        ->assertSee('أقل من 10')
        ->assertSee('نظرة عامة على المنصة')
        ->assertSee('14');
});
