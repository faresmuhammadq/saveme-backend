<?php

use App\Modules\Admin\Http\Livewire\Overview;
use App\Modules\Billing\Infrastructure\Models\PlayPurchase;
use App\Modules\Billing\Infrastructure\Models\Subscription;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * Operators see Google Play at a glance: counts for the last 24 hours, test
 * purchases apart from paid ones (spec 024, FR-053, P-18 rule 7).
 */

beforeEach(function () {
    $this->freezeTime();
    ['operator' => $this->operator] = makeOperator();
    signInOperator($this->operator);
});

function playInboxRow(string $state, ?string $reason = null, int $hoursAgo = 1): void
{
    DB::table('play_notifications')->insert([
        'id' => (string) Str::uuid7(), 'message_id' => 'm-'.Str::random(12), 'kind' => $state === 'ignored' ? 'test' : 'subscription',
        'received_at' => now()->subHours($hoursAgo), 'state' => $state, 'ignored_reason' => $reason, 'attempts' => 0, 'updated_at' => now(),
    ]);
}

it('counts Google Play notifications of the last 24 hours by state, and nothing older', function () {
    foreach ([['received'], ['processed'], ['processed'], ['ignored', 'test'], ['failed']] as $args) {
        playInboxRow(...$args);
    }
    playInboxRow('processed', hoursAgo: 25);

    $component = Livewire::test(Overview::class);

    expect($component->get('play'))->toMatchArray(['received' => 5, 'processed' => 2, 'ignored' => 1, 'failed' => 1]);
    $component->assertSeeHtml('data-test="play-figures"')->assertSeeHtml('data-test="play-processed"')->assertSee(__('admin::overview.play.title'));
});

it('counts purchases verified in the last 24 hours and those unacknowledged for more than a day', function () {
    PlayPurchase::factory()->linkedTo((string) Str::uuid7())->create(['play_state' => 'active', 'purchased_at' => now()->subHours(30)]);
    PlayPurchase::factory()->linkedTo((string) Str::uuid7())->create(['play_state' => 'active', 'purchased_at' => now()->subHours(2), 'acknowledged_at' => now()]);
    PlayPurchase::factory()->create(['play_state' => 'active', 'purchased_at' => now()->subHours(50)]); // unlinked: never counted

    $play = Livewire::test(Overview::class)->get('play');

    expect($play['verified'])->toBe(2)->and($play['unacknowledged'])->toBe(1);
});

it('sums the pushes refused in the last 24 hours by reason from the hourly counters', function () {
    Cache::put('billing:play:rejected:audience:'.now()->utc()->format('YmdH'), 3, now()->addHours(25));
    Cache::put('billing:play:rejected:audience:'.now()->subHours(5)->utc()->format('YmdH'), 2, now()->addHours(25));
    Cache::put('billing:play:rejected:signature:'.now()->subHours(23)->utc()->format('YmdH'), 4, now()->addHours(25));
    Cache::put('billing:play:rejected:time:'.now()->subHours(30)->utc()->format('YmdH'), 9, now()->addHours(25));

    $component = Livewire::test(Overview::class);

    expect($component->get('play.rejected'))->toBe(9)->and($component->get('playRejections'))->toBe(['signature' => 4, 'audience' => 5]);
    $component->assertSeeHtml('data-test="play-rejection-audience"');
});

it('shows Paid · Google Play without test purchases and a separate Test · Google Play count', function () {
    Subscription::factory()->provider('google_play')->count(11)->create(['source_meta' => ['test' => false]]);
    Subscription::factory()->provider('google_play')->count(12)->create(['source_meta' => ['test' => true]]);
    Subscription::factory()->provider('google_play')->grace()->count(2)->create(['source_meta' => ['test' => true]]);

    $component = Livewire::test(Overview::class);

    $component->assertSet('figures.paid_google_play', 11)->assertSet('figures.test_google_play', 14)
        ->assertSeeHtml('data-test="figure-test_google_play"')->assertSee(__('admin::overview.figures.test_google_play'));
});

it('shows a few test purchases as fewer than 10, like every population count', function () {
    Subscription::factory()->provider('google_play')->count(3)->create(['source_meta' => ['test' => true]]);

    Livewire::test(Overview::class)->assertSet('figures.test_google_play', 'fewer_than_10')->assertSet('figures.paid_google_play', 0);
});

it('is authorised on every request: a signed-out operator cannot read it', function () {
    $this->flushSession();

    $this->get(route('admin.overview'))->assertRedirect();
});

it('never shows a token, an order id, a reference or an amount', function () {
    PlayPurchase::factory()->linkedTo((string) Str::uuid7())->create(['token_sealed' => 'v1.b1.SEALED-TOKEN-MARKER', 'obfuscated_ref' => str_repeat('ab', 32)]);
    playInboxRow('processed');

    $html = Livewire::test(Overview::class)->html();

    expect($html)->not->toContain('SEALED-TOKEN-MARKER')->not->toContain(str_repeat('ab', 32))->not->toContain('GPA.')->not->toContain('14900');
});
