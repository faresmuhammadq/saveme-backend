<?php

use App\Modules\Billing\Application\GooglePlay\ReconcilePlayPurchase;
use App\Modules\Billing\Domain\GooglePlay\PlayProductKind;
use App\Modules\Billing\Domain\Ports\PlayAccountReferences;
use App\Modules\Billing\Domain\Ports\PlayPurchaseRepository;
use App\Modules\Billing\Infrastructure\Models\PlayPurchase;
use App\Modules\Billing\Infrastructure\Models\Subscription;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\QueryException;

/*
 * US2: one purchase token, one account, forever (spec 024, FR-006 to FR-010,
 * FR-049). Enforced by a unique key and a conditional update, not only a check.
 */

beforeEach(function () {
    mapPlayProducts();
    [$this->ada, $this->adaCredential] = playBuyer('ada@example.test');
    [$this->bob, $this->bobCredential] = playBuyer('bob@example.test');
    $this->token = playToken('link');
});

function verifyAs(string $credential, string $token, string $product = 'plus_monthly')
{
    return playCall($credential, 'post', '/api/v1/billing/google/purchases', ['purchase_token' => $token, 'product_id' => $product]);
}

function referenceOf(User $user): string
{
    return app(PlayAccountReferences::class)->for($user->id);
}

test('another account presenting a linked token is 409 PURCHASE_ALREADY_LINKED and nothing is granted to it', function () {
    scriptPlay($this->token);
    verifyAs($this->adaCredential, $this->token)->assertOk();

    $response = verifyAs($this->bobCredential, $this->token)->assertStatus(409);

    expect($response->json('code'))->toBe('PURCHASE_ALREADY_LINKED')
        ->and($response->getContent())->not->toContain('ada')->not->toContain($this->ada->id)
        ->and(Subscription::query()->where('user_id', $this->bob->id)->count())->toBe(0)
        ->and(PlayPurchase::query()->sole()->user_id)->toBe($this->ada->id);
});

test('a purchase carrying a reference is linked only to the account it names', function () {
    scriptPlay($this->token, ['obfuscatedRef' => referenceOf($this->ada)]);

    verifyAs($this->bobCredential, $this->token)->assertStatus(409)->assertJsonPath('code', 'PURCHASE_ALREADY_LINKED');
    expect(PlayPurchase::query()->where('user_id', '!=', null)->count())->toBe(0)->and(Subscription::query()->count())->toBe(0)->and(playGateway()->acknowledged)->toBe([]);

    verifyAs($this->adaCredential, $this->token)->assertOk();
    expect(PlayPurchase::query()->sole()->user_id)->toBe($this->ada->id);
});

test('a reference that resolves to no account is never linked to anyone', function () {
    scriptPlay($this->token, ['obfuscatedRef' => str_repeat('0f', 32)]);

    verifyAs($this->adaCredential, $this->token)->assertStatus(409);

    expect(PlayPurchase::query()->where('user_id', '!=', null)->count())->toBe(0)->and(playGateway()->acknowledged)->toBe([]);
});

test('a reference naming an account that has been purged is never linked to anyone', function () {
    $reference = referenceOf($this->bob);
    User::query()->whereKey($this->bob->id)->update(['state' => 'purged', 'purged_at' => now()]);
    scriptPlay($this->token, ['obfuscatedRef' => $reference]);

    verifyAs($this->adaCredential, $this->token)->assertStatus(409);

    expect(PlayPurchase::query()->where('user_id', '!=', null)->count())->toBe(0);
});

test('an account that is only pending deletion can still hold the purchase it paid for', function () {
    User::query()->whereKey($this->ada->id)->update(['state' => 'pending_deletion', 'deletion_requested_at' => now(), 'deletion_scheduled_for' => now()->addDays(30)]);
    scriptPlay($this->token, ['obfuscatedRef' => referenceOf($this->ada)]);

    $outcome = app(ReconcilePlayPurchase::class)->reconcile($this->token, PlayProductKind::Subscription, null, null);

    expect($outcome->userId)->toBe($this->ada->id)->and(Subscription::query()->sole()->user_id)->toBe($this->ada->id);
});

test('a purchase with no reference is linked to the first account that verifies it', function () {
    scriptPlay($this->token);

    verifyAs($this->bobCredential, $this->token)->assertOk();
    verifyAs($this->adaCredential, $this->token)->assertStatus(409);

    expect(PlayPurchase::query()->sole()->user_id)->toBe($this->bob->id);
});

test('the link is a conditional update: a second link of the same purchase does nothing', function () {
    $purchase = PlayPurchase::factory()->create();
    $repository = app(PlayPurchaseRepository::class);

    $first = $repository->linkTo($purchase->id, $this->ada->id, now()->toDateTimeImmutable());
    $second = $repository->linkTo($purchase->id, $this->bob->id, now()->toDateTimeImmutable());

    expect($first)->toBeTrue()->and($second)->toBeFalse()->and($purchase->fresh()->user_id)->toBe($this->ada->id);
});

test('two first verifications of one token race: exactly one account wins, the other is 409', function () {
    scriptPlay($this->token);
    $repository = app(PlayPurchaseRepository::class);

    // Bob's request passed its check just as Ada's linked the row: his conditional update must lose.
    $stored = PlayPurchase::factory()->create(['token_lookup' => playLookup($this->token)]);
    $repository->linkTo($stored->id, $this->ada->id, now()->toDateTimeImmutable());

    $outcome = app(ReconcilePlayPurchase::class)->reconcile($this->token, PlayProductKind::Subscription, 'plus_monthly', $this->bob->id);

    expect($outcome->kind)->toBe('elsewhere')->and(PlayPurchase::query()->sole()->user_id)->toBe($this->ada->id);
});

test('the lookup value is unique: one row per token', function () {
    $lookup = playLookup($this->token);
    PlayPurchase::factory()->create(['token_lookup' => $lookup]);

    expect(fn () => PlayPurchase::factory()->create(['token_lookup' => $lookup]))->toThrow(QueryException::class);
});

test('a replacing purchase inherits the replaced token\'s account and ends the old subscription', function () {
    $old = playToken('old');
    scriptPlay($old, ['obfuscatedRef' => referenceOf($this->ada)]);
    verifyAs($this->adaCredential, $old)->assertOk();
    $oldSubscription = Subscription::query()->sole();

    scriptPlay($this->token, ['productId' => 'plus_yearly', 'linkedToken' => $old, 'expiresAt' => now()->addYear()->toDateTimeImmutable()]);
    verifyAs($this->adaCredential, $this->token, 'plus_yearly')->assertOk();

    expect(Subscription::query()->count())->toBe(2)
        ->and($oldSubscription->fresh()->status)->toBe('expired')
        ->and(Subscription::query()->where('id', '!=', $oldSubscription->id)->sole()->status)->toBe('active')
        ->and(PlayPurchase::query()->where('token_lookup', playLookup($this->token))->sole()->replaces_lookup)->toBe(playLookup($old));
});

test('a replacing purchase presented by another account than the replaced one\'s is 409', function () {
    $old = playToken('old');
    scriptPlay($old);
    verifyAs($this->adaCredential, $old)->assertOk();

    scriptPlay($this->token, ['productId' => 'plus_yearly', 'linkedToken' => $old]);
    verifyAs($this->bobCredential, $this->token, 'plus_yearly')->assertStatus(409)->assertJsonPath('code', 'PURCHASE_ALREADY_LINKED');

    expect(Subscription::query()->where('user_id', $this->bob->id)->count())->toBe(0);
});

test('the account reference is stable per account, distinct between accounts, 64 hex, and resolves back', function () {
    $ada = $this->withHeaders(asCredential($this->adaCredential))->getJson('/api/v1/billing/google/account-reference')->assertOk();
    $again = $this->withHeaders(asCredential($this->adaCredential))->getJson('/api/v1/billing/google/account-reference')->assertOk();
    $bob = $this->withHeaders(asCredential($this->bobCredential))->getJson('/api/v1/billing/google/account-reference')->assertOk();

    $reference = $ada->json('data.obfuscated_account_id');
    expect($reference)->toMatch('/^[0-9a-f]{64}$/')->and($again->json('data.obfuscated_account_id'))->toBe($reference)
        ->and($bob->json('data.obfuscated_account_id'))->not->toBe($reference)
        ->and(app(PlayAccountReferences::class)->userFor($reference))->toBe($this->ada->id)
        ->and($reference)->not->toContain($this->ada->id);
});
