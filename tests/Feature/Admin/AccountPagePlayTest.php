<?php

use App\Modules\Admin\Http\Livewire\AccountPage;
use App\Modules\Billing\Infrastructure\Models\PlayPurchase;
use App\Modules\Billing\Infrastructure\Models\Subscription;
use App\Modules\Identity\Infrastructure\Models\User;
use Livewire\Livewire;

/*
 * The account page shows a Google Play subscription's markers and offers no
 * way to change it (spec 024, FR-052; spec 021 FR-029).
 */

beforeEach(function () {
    $this->freezeTime();
    ['operator' => $this->operator] = makeOperator();
    signInOperator($this->operator);
    $this->user = User::factory()->create();
});

function playSubscription(User $user, bool $acknowledged, bool $test, bool $autoRenew = true): array
{
    $subscription = Subscription::factory()->provider('google_play')->create(['user_id' => $user->id, 'auto_renew' => $autoRenew, 'source_meta' => ['test' => $test, 'base_plan_id' => 'monthly']]);
    $purchase = PlayPurchase::factory()->linkedTo($user->id)->create([
        'subscription_id' => $subscription->id, 'is_test' => $test, 'acknowledged_at' => $acknowledged ? now() : null,
        'token_sealed' => 'v1.b1.SEALED-TOKEN-MARKER', 'obfuscated_ref' => str_repeat('cd', 32),
    ]);

    return [$subscription, $purchase];
}

it('marks auto-renew, acknowledged and test on a Google Play subscription', function () {
    [$subscription] = playSubscription($this->user, acknowledged: true, test: true);

    $component = Livewire::test(AccountPage::class, ['user' => $this->user->id]);

    expect($component->get('account.access.subscriptions.0'))->toMatchArray(['provider' => 'google_play', 'auto_renew' => true, 'play_acknowledged' => true, 'play_test' => true]);
    $component->assertSeeHtml('data-test="play-auto-renew"')->assertSeeHtml('data-test="play-acknowledged"')->assertSeeHtml('data-test="play-test"')
        ->assertSee(__('admin::accounts.subscriptions.markers.auto_renew_on'))->assertSee(__('admin::accounts.subscriptions.markers.acknowledged'))->assertSee(__('admin::accounts.subscriptions.markers.test'));
});

it('shows will-not-renew, not-yet-acknowledged, and no test marker for a plain canceled purchase', function () {
    playSubscription($this->user, acknowledged: false, test: false, autoRenew: false);

    $component = Livewire::test(AccountPage::class, ['user' => $this->user->id]);

    $component->assertSee(__('admin::accounts.subscriptions.markers.auto_renew_off'))->assertSee(__('admin::accounts.subscriptions.markers.not_acknowledged'))
        ->assertDontSeeHtml('data-test="play-test"');
});

it('offers no extend and no revoke for a Google Play subscription', function () {
    [$subscription] = playSubscription($this->user, true, false);

    Livewire::test(AccountPage::class, ['user' => $this->user->id])
        ->assertDontSeeHtml('data-test="extend-'.$subscription->id.'"')->assertDontSeeHtml('data-test="revoke-'.$subscription->id.'"');
});

it('shows no markers on another channel\'s subscription', function () {
    Subscription::factory()->provider('instapay')->create(['user_id' => $this->user->id]);

    Livewire::test(AccountPage::class, ['user' => $this->user->id])->assertDontSeeHtml('data-test="play-auto-renew"');
});

it('never puts a token, an order id, a reference or an amount in the page', function () {
    playSubscription($this->user, true, false);

    $html = Livewire::test(AccountPage::class, ['user' => $this->user->id])->html();

    expect($html)->not->toContain('SEALED-TOKEN-MARKER')->not->toContain(str_repeat('cd', 32))->not->toContain('GPA.')->not->toContain('14900')
        ->and(json_encode(Livewire::test(AccountPage::class, ['user' => $this->user->id])->get('account.access.subscriptions')))->not->toContain('token')->not->toContain('order');
});
