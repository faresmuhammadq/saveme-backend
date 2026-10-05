<?php

use App\Modules\Admin\Http\Livewire\AccountPage;
use App\Modules\Admin\Http\Middleware\ResolveAdminLocale;
use App\Modules\Billing\Infrastructure\Models\Plan;
use App\Modules\Billing\Infrastructure\Models\Subscription;
use App\Modules\Budgeting\Infrastructure\Models\Budget;
use App\Modules\Identity\Domain\Ports\AttributeCipher;
use App\Modules\Identity\Infrastructure\Models\DeviceSession;
use App\Modules\Identity\Infrastructure\Models\Profile;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Wallets\Infrastructure\Models\Category;
use App\Modules\Wallets\Infrastructure\Models\Transaction;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use App\Modules\Wallets\Infrastructure\Models\WalletMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * The account page shows counts and facts, never what a person wrote or spent
 * (spec 021, FR-021 to FR-025; R-16).
 */

const ACCOUNT_MARKERS = ['ZQ-NAME-4410', 'ZQ-WALLET-7731', 'ZQ-WALLET-7732', 'ZQ-CAT-5520', 'ZQ-NOTE-3309', 'ZQ-DEVICE-1188', '987654'];

beforeEach(function () {
    $this->freezeTime();
    ['operator' => $this->operator] = makeOperator();
    signInOperator($this->operator);
});

function seedAccountWithMarkers(string $address = 'marker.person@example.test'): User
{
    $user = User::factory()->withEmail($address)->verified()->create();
    $profile = Profile::factory()->create(['user_id' => $user->id]);
    $profile->forceFill(['display_name_ciphertext' => app(AttributeCipher::class)->seal($user->id, 'ZQ-NAME-4410')])->save();

    $first = Wallet::factory()->create(['name' => 'ZQ-WALLET-7731']);
    $second = Wallet::factory()->create(['name' => 'ZQ-WALLET-7732']);
    $category = Category::factory()->create(['user_id' => $user->id, 'name' => 'ZQ-CAT-5520']);
    WalletMember::factory()->owner()->create(['wallet_id' => $first->id, 'user_id' => $user->id]);
    WalletMember::factory()->owner()->create(['wallet_id' => $second->id, 'user_id' => $user->id]);
    WalletMember::factory()->editor()->create(['wallet_id' => $first->id, 'user_id' => User::factory()->create()->id]);
    Transaction::factory()->create(['wallet_id' => $first->id, 'category_id' => $category->id, 'amount_minor' => 987654, 'memo' => 'ZQ-NOTE-3309', 'description' => 'ZQ-NOTE-3309']);
    Budget::factory()->create(['user_id' => $user->id, 'category_id' => $category->id]);
    DeviceSession::factory()->create(['user_id' => $user->id, 'device_name' => 'ZQ-DEVICE-1188']);
    DeviceSession::factory()->create(['user_id' => $user->id, 'device_name' => 'ZQ-DEVICE-1188']);

    return $user;
}

function viewedEntries(string $userId): int
{
    return DB::table('audit_entries')->where('event', 'admin_action')->where('subject_type', 'user')
        ->where('subject_id', $userId)->whereRaw("detail->>'action' = 'account_viewed'")->count();
}

it('shows none of the markers, in either language, in the page or the component state', function (string $locale) {
    $user = seedAccountWithMarkers();

    $html = $this->withSession([ResolveAdminLocale::SESSION_KEY => $locale])
        ->get(route('admin.accounts.show', ['user' => $user->id]))->assertOk()->getContent();

    foreach (ACCOUNT_MARKERS as $marker) {
        expect($html)->not->toContain($marker);
    }

    app()->setLocale($locale);
    $component = Livewire::test(AccountPage::class, ['user' => $user->id]);
    $state = json_encode($component->snapshot);

    foreach (ACCOUNT_MARKERS as $marker) {
        expect($component->html())->not->toContain($marker)
            ->and($state)->not->toContain($marker);
    }
})->with(['en', 'ar']);

it('shows counts, state, plan and capabilities', function () {
    $user = seedAccountWithMarkers();
    Subscription::factory()->create(['user_id' => $user->id]);

    $component = Livewire::test(AccountPage::class, ['user' => $user->id])
        ->assertSeeHtml('data-test="privacy-note"')
        ->assertSee('Names, balances, amounts and notes are never shown here.')
        ->assertSee('Email verified')
        ->assertSee('Plus · Monthly')
        ->assertSee($user->id);

    expect($component->html())
        ->toMatch('/data-test="count-wallets-owned"[^>]*>\s*(<[^>]+>\s*)*2\b/')
        ->toMatch('/data-test="count-wallets-joined"[^>]*>\s*(<[^>]+>\s*)*0\b/')
        ->toMatch('/data-test="count-other-members"[^>]*>\s*(<[^>]+>\s*)*1\b/')
        ->toMatch('/data-test="count-devices"[^>]*>\s*(<[^>]+>\s*)*2\b/')
        ->toContain('data-test="governing"')
        ->toContain('Shared wallets')
        ->not->toMatch('/admin::[a-z]+\./')
        ->toContain('admin/audit?subject_type=user&amp;subject_id='.$user->id);
});

it('shows the free plan and no subscriptions for a person with none', function () {
    $user = User::factory()->create();

    Livewire::test(AccountPage::class, ['user' => $user->id])
        ->assertSee('Free')
        ->assertSeeHtml('data-test="no-subscriptions"')
        ->assertDontSeeHtml('data-test="governing"');
});

it('by id never shows the stored address; by address it repeats only the normalised address typed', function () {
    $user = seedAccountWithMarkers('Marker.Person@Example.test');

    Livewire::test(AccountPage::class, ['user' => $user->id])
        ->assertDontSee('marker.person@example.test')
        ->assertDontSee('Marker.Person@Example.test')
        ->assertDontSeeHtml('data-test="found-by"');

    session()->flash('account_found_by', ['user' => $user->id, 'address' => 'marker.person@example.test']);

    Livewire::test(AccountPage::class, ['user' => $user->id])
        ->assertSeeHtml('data-test="found-by"')
        ->assertSee('marker.person@example.test');
});

it('does not trust a flashed address meant for another account', function () {
    $user = User::factory()->create();
    session()->flash('account_found_by', ['user' => (string) Str::uuid7(), 'address' => 'other@example.test']);

    Livewire::test(AccountPage::class, ['user' => $user->id])->assertDontSee('other@example.test');
});

it('writes one account_viewed per opening, none on re-render or after an action', function () {
    $user = User::factory()->create();
    $sub = Subscription::factory()->create(['user_id' => $user->id]);

    $component = Livewire::test(AccountPage::class, ['user' => $user->id]);
    expect(viewedEntries($user->id))->toBe(1);

    $component->call('$refresh')
        ->call('startRevoke', $sub->id)->set('revokeReason', 'Refunded by hand')->call('confirmRevoke');

    expect(viewedEntries($user->id))->toBe(1);

    Livewire::test(AccountPage::class, ['user' => $user->id]);
    expect(viewedEntries($user->id))->toBe(2);
});

it('shows the deletion date for an account waiting to be deleted and offers no grant', function () {
    $user = User::factory()->pendingDeletion()->create();

    Livewire::test(AccountPage::class, ['user' => $user->id])
        ->assertSeeHtml('data-test="state-pending_deletion"')
        ->assertSee('Deletion pending')
        ->assertDontSeeHtml('data-test="grant"');
});

it('shows only the id and Deleted for a purged account, and still records the opening', function () {
    $user = seedAccountWithMarkers();
    User::query()->whereKey($user->id)->update(['state' => 'purged']);

    Livewire::test(AccountPage::class, ['user' => $user->id])
        ->assertSee($user->id)
        ->assertSeeHtml('data-test="state-purged"')
        ->assertSee('Deleted')
        ->assertDontSeeHtml('data-test="grant"')
        ->assertDontSeeHtml('data-test="count-wallets-owned"')
        ->assertDontSeeHtml('data-test="plan-card"')
        ->assertDontSeeHtml('data-test="subscriptions-card"')
        ->assertDontSeeHtml('data-test="counts-card"');

    expect(viewedEntries($user->id))->toBe(1);
});

it('answers 404 for an unknown id and writes nothing', function () {
    $this->get(route('admin.accounts.show', ['user' => (string) Str::uuid7()]))->assertNotFound();

    expect(DB::table('audit_entries')->where('event', 'admin_action')->count())->toBe(0);
});

it('renders in Arabic right to left with Western digits', function () {
    $user = seedAccountWithMarkers();
    Subscription::factory()->create(['user_id' => $user->id, 'plan_id' => (string) Plan::query()->where('code', 'plus_yearly')->value('id')]);

    $html = $this->withSession([ResolveAdminLocale::SESSION_KEY => 'ar'])
        ->get(route('admin.accounts.show', ['user' => $user->id]))
        ->assertOk()
        ->assertSee('dir="rtl"', false)
        ->getContent();

    expect($html)->toUseWesternDigitsOnly();
});
