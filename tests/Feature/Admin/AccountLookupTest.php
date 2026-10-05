<?php

use App\Modules\Admin\Application\Accounts\AccountLookup;
use App\Modules\Admin\Domain\Exceptions\LookupBudgetSpent;
use App\Modules\Admin\Http\Livewire\AccountPage;
use App\Modules\Admin\Http\Livewire\FindAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * Finding an account: exact, normalised, budgeted, and a miss answers the
 * same way whatever was typed (spec 021, FR-020, FR-025, FR-061; R-9).
 */

beforeEach(function () {
    $this->freezeTime();
    ['operator' => $this->operator] = makeOperator();
    signInOperator($this->operator);
    $this->spent = fn (): int => RateLimiter::attempts('admin-lookup:'.$this->operator->id);
});

function lookupEntries(string $action): array
{
    return DB::table('audit_entries')->where('event', 'admin_action')->whereRaw("detail->>'action' = ?", [$action])->get()->all();
}

it('redirects to the page on a hit by address in any case, spending nothing', function () {
    $user = User::factory()->withEmail('Layla@Example.test')->create();

    Livewire::test(FindAccount::class)
        ->set('term', '  LAYLA@example.TEST ')
        ->call('find')
        ->assertRedirect(route('admin.accounts.show', ['user' => $user->id]));

    expect(($this->spent)())->toBe(0)
        ->and(DB::table('audit_entries')->where('event', 'admin_action')->count())->toBe(0);
});

it('redirects to the page on a hit by id, spending nothing', function () {
    $user = User::factory()->create();

    Livewire::test(FindAccount::class)
        ->set('term', strtoupper($user->id))
        ->call('find')
        ->assertRedirect(route('admin.accounts.show', ['user' => $user->id]));

    expect(($this->spent)())->toBe(0);
});

it('answers every miss the same, writes one account_lookup_missed without the term, and spends one unit', function (string $term, string $by) {
    User::factory()->withEmail('someone@example.test')->create();

    Livewire::test(FindAccount::class)
        ->set('term', $term)
        ->call('find')
        ->assertSee('No account matches.')
        ->assertSeeHtml('data-test="no-match"')
        ->assertNoRedirect();

    $entries = lookupEntries('account_lookup_missed');
    expect($entries)->toHaveCount(1)
        ->and($entries[0]->actor_id)->toBe($this->operator->id)
        ->and($entries[0]->subject_type)->toBe('operator')
        ->and($entries[0]->subject_id)->toBe($this->operator->id)
        ->and(json_decode($entries[0]->detail, true))->toEqual(['action' => 'account_lookup_missed', 'by' => $by])
        ->and(json_encode($entries[0]))->not->toContain($term)
        ->and(($this->spent)())->toBe(1);
})->with([
    'unknown address' => ['nobody@example.test', 'address'],
    'unknown id' => ['0198a2f0-0000-7000-8000-000000000000', 'id'],
    'malformed id' => ['12345', 'id'],
]);

it('treats the address of a purged account as a miss', function () {
    $user = User::factory()->withEmail('gone@example.test')->create();
    User::query()->whereKey($user->id)->update(['state' => 'purged', 'purged_at' => now()]);

    Livewire::test(FindAccount::class)->set('term', 'gone@example.test')->call('find')->assertSee('No account matches.');

    expect(lookupEntries('account_lookup_missed'))->toHaveCount(1);
});

it('spends one unit for each page opening and refuses the 31st with the minutes to wait, writing nothing', function () {
    $user = User::factory()->create();
    $lookup = app(AccountLookup::class);

    foreach (range(1, 30) as $_) {
        expect($lookup->view($this->operator->id, $user->id))->not->toBeNull();
    }

    expect(($this->spent)())->toBe(30)
        ->and(lookupEntries('account_viewed'))->toHaveCount(30);

    expect(fn () => $lookup->view($this->operator->id, $user->id))->toThrow(LookupBudgetSpent::class)
        ->and(lookupEntries('account_viewed'))->toHaveCount(30);

    Livewire::test(AccountPage::class, ['user' => $user->id])
        ->assertSeeHtml('data-test="lookup-budget"')
        ->assertDontSeeHtml('data-test="privacy-note"');

    Livewire::test(FindAccount::class)->set('term', 'nobody@example.test')->call('find')
        ->assertSeeHtml('data-test="lookup-budget"');

    expect(lookupEntries('account_lookup_missed'))->toHaveCount(0)
        ->and(lookupEntries('account_viewed'))->toHaveCount(30);
});

it('opens nothing and writes nothing for an unknown id', function () {
    expect(app(AccountLookup::class)->view($this->operator->id, (string) Str::uuid7()))->toBeNull()
        ->and(lookupEntries('account_viewed'))->toHaveCount(0);
});
