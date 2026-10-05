<?php

use App\Modules\Admin\Application\Lookup\LookupBudget;
use App\Modules\Admin\Http\Livewire\ClaimQueue;
use App\Modules\Admin\Http\Middleware\ResolveAdminLocale;
use App\Modules\Admin\Http\Navigation\NavigationRegistry;
use App\Modules\Billing\Infrastructure\Models\PaymentClaim;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * The InstaPay claim queue (spec 021, US1; FR-015, FR-061).
 */

beforeEach(function () {
    $this->freezeTime();
    ['operator' => $this->operator] = makeOperator();
    signInOperator($this->operator);
});

function queueIds(Testable $component): array
{
    return array_map(fn (array $row): string => $row['id'], $component->get('rows'));
}

it('lists awaiting claims oldest first with the tab count and no address', function () {
    $older = PaymentClaim::factory()->forAddress('seeded-payer@example.test')->create(['created_at' => now()->subHours(3)]);
    $newer = PaymentClaim::factory()->create(['created_at' => now()->subHour()]);
    PaymentClaim::factory()->approved()->create();

    $component = Livewire::test(ClaimQueue::class)
        ->assertSet('status', 'submitted')
        ->assertSet('awaiting', 2)
        ->assertSeeHtml('data-test="tab-submitted"')
        ->assertSeeHtml('data-test="tab-approved"')
        ->assertSeeHtml('data-test="tab-rejected"')
        ->assertSeeHtml('data-test="tab-expired"')
        ->assertSeeHtml('data-test="claim-row"')
        ->assertSeeHtml('data-test="claim-search"')
        ->assertDontSee('seeded-payer')
        ->assertDontSee('example.test');

    expect(queueIds($component))->toBe([$older->id, $newer->id])
        ->and(json_encode($component->get('rows')))->not->toContain('example.test');
});

it('shows reference, plan, amount, the shared-reference pill and the time left', function () {
    PaymentClaim::factory()->reference('SAMEREF00001')->create();
    PaymentClaim::factory()->reference('SAMEREF00001')->create();

    Livewire::test(ClaimQueue::class)
        ->assertSee('SAMEREF00001')
        ->assertSee('Plus · Yearly')
        ->assertSee('Shared reference')
        ->assertSee('Expires in')
        ->assertSeeHtml('data-test="shared-reference"');
});

it('switches tabs, newest first outside awaiting, and rejects an unknown status', function () {
    PaymentClaim::factory()->create();
    $oldApproved = PaymentClaim::factory()->approved()->create(['created_at' => now()->subDays(2)]);
    $newApproved = PaymentClaim::factory()->approved()->create(['created_at' => now()->subDay()]);

    $component = Livewire::test(ClaimQueue::class)->call('setStatus', 'approved');

    expect(queueIds($component))->toBe([$newApproved->id, $oldApproved->id]);

    $component->call('setStatus', 'bogus')->assertSet('status', 'submitted');
    Livewire::withQueryParams(['status' => 'nope'])->test(ClaimQueue::class)->assertSet('status', 'submitted');
});

it('reads the status and the cursor from the query string', function () {
    PaymentClaim::factory()->rejected()->create();

    $component = Livewire::withQueryParams(['status' => 'rejected'])->test(ClaimQueue::class);

    expect($component->get('rows'))->toHaveCount(1);
});

it('searches by reference in any spelling and spends no lookup unit', function () {
    $hit = PaymentClaim::factory()->reference('ABCD12345678')->create();
    PaymentClaim::factory()->reference('ZZZZ99999999')->create();

    $component = Livewire::test(ClaimQueue::class)->set('draft', 'abcd-1234 5678')->call('search');

    expect(queueIds($component))->toBe([$hit->id])
        ->and(RateLimiter::attempts('admin-lookup:'.$this->operator->id))->toBe(0);
});

it('searches by exact address, spends one unit per query, and never shows the address', function () {
    $hit = PaymentClaim::factory()->forAddress('payer@example.test')->create();
    PaymentClaim::factory()->forAddress('other@example.test')->create();

    $component = Livewire::test(ClaimQueue::class)->set('draft', 'Payer@Example.test')->call('search');

    expect(queueIds($component))->toBe([$hit->id])
        ->and(RateLimiter::attempts('admin-lookup:'.$this->operator->id))->toBe(1);

    $component->assertDontSee('payer@example.test')->call('setStatus', 'submitted');
    expect(RateLimiter::attempts('admin-lookup:'.$this->operator->id))->toBe(2);

    expect(DB::table('audit_entries')->where('event', 'admin_action')->count())->toBe(0);
});

it('refuses an address search when the lookup budget is spent', function () {
    PaymentClaim::factory()->forAddress('payer@example.test')->create();

    foreach (range(1, LookupBudget::ATTEMPTS) as $ignored) {
        app(LookupBudget::class)->spend($this->operator->id);
    }

    Livewire::test(ClaimQueue::class)
        ->set('draft', 'payer@example.test')
        ->call('search')
        ->assertSet('rows', [])
        ->assertSee('Too many lookups. Try again in 10 minutes.')
        ->assertSeeHtml('data-test="lookup-budget"');
});

it('treats a malformed address as no match without spending anything', function () {
    PaymentClaim::factory()->create();

    Livewire::test(ClaimQueue::class)->set('draft', 'not@')->call('search')->assertSet('rows', [])->assertSee('No claims here.');

    expect(RateLimiter::attempts('admin-lookup:'.$this->operator->id))->toBe(0);
});

it('shows the empty state', function () {
    Livewire::test(ClaimQueue::class)->assertSee('No claims here.');
});

it('pages 25 at a time with older and newer', function () {
    $claims = PaymentClaim::factory()->count(27)
        ->sequence(...array_map(fn (int $n) => ['created_at' => now()->subMinutes(100 - $n)], range(1, 27)))->create();

    $component = Livewire::test(ClaimQueue::class);
    expect($component->get('rows'))->toHaveCount(25);

    $component->assertSeeHtml('data-test="page-older"')->call('older');
    expect(queueIds($component))->toBe($claims->slice(25)->pluck('id')->values()->all());

    $component->call('newer');
    expect(queueIds($component))->toBe($claims->take(25)->pluck('id')->all());
});

it('renders in Arabic right to left with plan names in Arabic', function () {
    PaymentClaim::factory()->create();

    $response = $this->withSession([ResolveAdminLocale::SESSION_KEY => 'ar'])->get(route('admin.claims'))
        ->assertOk()
        ->assertSee('dir="rtl"', false)
        ->assertSee('مطالبات إنستا باي')
        ->assertSee('بلس · سنوي');

    expect($response->getContent())->toUseWesternDigitsOnly();
});

function claimsBadge(): ?int
{
    foreach (app(NavigationRegistry::class)->groups('admin.claims') as $group) {
        foreach ($group['entries'] as $item) {
            if ($item['entry']->key === 'claims') {
                return $item['badge'];
            }
        }
    }

    throw new RuntimeException('The claims entry is not registered.');
}

it('puts the four working screens in the sidebar, the claims badge counting awaiting claims and hidden at zero', function () {
    $html = $this->get(route('admin.claims'))->assertOk()->getContent();

    expect($html)->toContain(route('admin.accounts'))->toContain(route('admin.plans'))->toContain(route('admin.audit'))
        ->and(claimsBadge())->toBeNull();

    PaymentClaim::factory()->count(3)->create();
    PaymentClaim::factory()->create(['expires_at' => now()->subMinute()]);

    $this->get(route('admin.claims'));
    expect(claimsBadge())->toBe(3);
});
