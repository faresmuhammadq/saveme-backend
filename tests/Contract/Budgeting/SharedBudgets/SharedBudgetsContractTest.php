<?php

use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * The Shared Budget endpoints against docs/openapi/paths/shared-budgets.yaml
 * (ADR-0027): every documented status the flows reach.
 */

function sbCall(string $method, string $credential, string $uri, array $body = []): TestResponse
{
    return walletCall($method, $credential, $uri, $body);
}

function sbBody(array $changes = [], int $version = 0): array
{
    return ['name' => 'Home', 'currency' => 'EGP', 'start_day' => 1, 'limit_minor' => 2000000, 'version' => $version, ...$changes];
}

beforeEach(function () {
    [$this->owner, $this->ownerCredential] = walletCaller('owner@example.test');
    [$this->partner, $this->partnerCredential] = walletCaller('partner@example.test');
    grantSharing($this->owner->id);
    $this->id = (string) Str::uuid7();
});

function createdBudget(string $credential, string $id): TestResponse
{
    return sbCall('PUT', $credential, '/shared-budgets/'.$id, sbBody())->assertValidRequest()->assertValidResponse(201);
}

test('creating returns 201 with the caller as owner and replaying 200', function () {
    $response = createdBudget($this->ownerCredential, $this->id);

    expect($response->json('data'))->toMatchArray(['id' => $this->id, 'owner_id' => $this->owner->id, 'version' => 1, 'currency' => 'EGP'])
        ->and($response->json('data.limit_history'))->toHaveCount(1);

    sbCall('PUT', $this->ownerCredential, '/shared-budgets/'.$this->id, sbBody())->assertValidRequest()->assertValidResponse(200);
});

test('a stranger finds nothing, a member cannot change it, a stale version conflicts', function () {
    createdBudget($this->ownerCredential, $this->id);
    joinSharedBudget($this->id, $this->partner->id);
    [, $strangerCredential] = walletCaller('stranger@example.test');

    sbCall('GET', $strangerCredential, '/shared-budgets/'.$this->id)->assertValidRequest()->assertValidResponse(404);
    sbCall('GET', $this->partnerCredential, '/shared-budgets/'.$this->id)->assertValidRequest()->assertValidResponse(200);
    sbCall('PATCH', $this->partnerCredential, '/shared-budgets/'.$this->id, ['limit_minor' => 5, 'version' => 1])->assertValidRequest()->assertValidResponse(403);
    sbCall('PATCH', $this->ownerCredential, '/shared-budgets/'.$this->id, ['limit_minor' => 5, 'version' => 9])->assertValidRequest()->assertValidResponse(409);
    sbCall('PUT', $this->ownerCredential, '/shared-budgets/'.$this->id, sbBody(['currency' => 'USD'], 1))->assertValidRequest()->assertValidResponse(422);
    sbCall('GET', $this->ownerCredential, '/shared-budgets/not-an-id')->assertValidResponse(422);
});

test('the list, archive, transfer and delete answer as documented', function () {
    createdBudget($this->ownerCredential, $this->id);
    joinSharedBudget($this->id, $this->partner->id);

    sbCall('GET', $this->partnerCredential, '/shared-budgets')->assertValidRequest()->assertValidResponse(200);
    sbCall('POST', $this->ownerCredential, '/shared-budgets/'.$this->id.'/archive', ['version' => 1])->assertValidRequest()->assertValidResponse(200);
    sbCall('POST', $this->ownerCredential, '/shared-budgets/'.$this->id.'/transfer-ownership', ['user_id' => $this->partner->id, 'version' => 2])->assertValidRequest()->assertValidResponse(200);
    sbCall('DELETE', $this->partnerCredential, '/shared-budgets/'.$this->id, ['version' => 3])->assertValidRequest()->assertValidResponse(200);
});

test('lines and members', function () {
    createdBudget($this->ownerCredential, $this->id);
    joinSharedBudget($this->id, $this->partner->id);
    $line = (string) Str::uuid7();
    $body = ['category_id' => platformCategoryId(), 'limit_minor' => 5000, 'version' => 0];

    sbCall('PUT', $this->ownerCredential, '/shared-budgets/'.$this->id.'/lines/'.$line, $body)->assertValidRequest()->assertValidResponse(201);
    sbCall('PUT', $this->ownerCredential, '/shared-budgets/'.$this->id.'/lines/'.$line, $body)->assertValidRequest()->assertValidResponse(200);
    sbCall('PUT', $this->partnerCredential, '/shared-budgets/'.$this->id.'/lines/'.(string) Str::uuid7(), $body)->assertValidRequest()->assertValidResponse(403);
    sbCall('GET', $this->partnerCredential, '/shared-budgets/'.$this->id.'/lines')->assertValidRequest()->assertValidResponse(200);
    sbCall('GET', $this->partnerCredential, '/shared-budgets/'.$this->id.'/members')->assertValidRequest()->assertValidResponse(200);
    sbCall('DELETE', $this->ownerCredential, '/shared-budgets/'.$this->id.'/lines/'.$line, ['version' => 1])->assertValidRequest()->assertValidResponse(200);
    sbCall('DELETE', $this->partnerCredential, '/shared-budgets/'.$this->id.'/members/'.$this->owner->id)->assertValidRequest()->assertValidResponse(403);
    sbCall('DELETE', $this->ownerCredential, '/shared-budgets/'.$this->id.'/members/'.$this->partner->id)->assertValidRequest()->assertNoContent();
});

test('leaving is 204 for a member and 403 for the owner', function () {
    createdBudget($this->ownerCredential, $this->id);
    joinSharedBudget($this->id, $this->partner->id);

    sbCall('POST', $this->ownerCredential, '/shared-budgets/'.$this->id.'/leave')->assertValidRequest()->assertValidResponse(403);
    sbCall('POST', $this->partnerCredential, '/shared-budgets/'.$this->id.'/leave')->assertValidRequest()->assertNoContent();
});

test('progress, summary and trend', function () {
    createdBudget($this->ownerCredential, $this->id);
    joinSharedBudget($this->id, $this->partner->id);
    $wallet = ownedWallet($this->partner->id);
    recordTransaction($this->partner->id, $wallet->id, transactionBody(['shared_budget_id' => $this->id, 'amount_minor' => 900, 'date' => now()->format('Y-m-d')]));
    $today = now()->format('Y-m-d');

    $progress = sbCall('GET', $this->ownerCredential, '/shared-budgets/'.$this->id.'/progress')->assertValidRequest()->assertValidResponse(200);
    expect($progress->json('data.spent.amount_minor'))->toBe(900)->and($progress->json('data.members.0.user_id'))->toBe($this->partner->id);

    sbCall('GET', $this->ownerCredential, '/shared-budgets/'.$this->id.'/progress?date=nope')->assertValidResponse(400);
    sbCall('GET', $this->ownerCredential, '/shared-budgets/'.$this->id.'/reports/summary?from=2020-01-01&to='.$today)->assertValidRequest()->assertValidResponse(200);
    sbCall('GET', $this->ownerCredential, '/shared-budgets/'.$this->id.'/reports/summary?from='.$today)->assertValidResponse(400);
    sbCall('GET', $this->ownerCredential, '/shared-budgets/'.$this->id.'/reports/trend?from='.now()->subMonths(2)->format('Y-m-d').'&to='.$today)->assertValidRequest()->assertValidResponse(200);
    sbCall('GET', $this->ownerCredential, '/shared-budgets/'.$this->id.'/reports/trend?from=2000-01-01&to='.$today)->assertValidRequest()->assertValidResponse(400);
});

test('invitations: create with a link, preview, accept, decline', function () {
    createdBudget($this->ownerCredential, $this->id);
    $invitation = (string) Str::uuid7();
    $url = '/shared-budgets/'.$this->id.'/invitations/'.$invitation;

    $created = sbCall('PUT', $this->ownerCredential, $url, [])->assertValidRequest()->assertValidResponse(201);
    $token = Str::after($created->json('meta.share_url'), 'token=');
    expect($created->json('meta.share_url'))->toContain('/invite/shared-budget?token=');

    sbCall('PUT', $this->ownerCredential, $url, [])->assertValidRequest()->assertValidResponse(200);
    sbCall('GET', $this->ownerCredential, '/shared-budgets/'.$this->id.'/invitations')->assertValidRequest()->assertValidResponse(200);
    sbCall('GET', $this->ownerCredential, $url)->assertValidRequest()->assertValidResponse(200);
    sbCall('GET', $this->partnerCredential, $url)->assertValidRequest()->assertValidResponse(404);

    sbCall('POST', $this->partnerCredential, '/shared-budget-invitations/preview', ['token' => $token])->assertValidRequest()->assertValidResponse(200);
    sbCall('POST', $this->partnerCredential, '/shared-budget-invitations/preview', ['token' => Str::random(43)])->assertValidRequest()->assertValidResponse(404);
    sbCall('POST', $this->partnerCredential, '/shared-budget-invitations/accept', ['token' => $token])->assertValidRequest()->assertValidResponse(200);
    sbCall('POST', $this->partnerCredential, '/shared-budget-invitations/accept', ['token' => 'short'])->assertValidResponse(422);

    $second = (string) Str::uuid7();
    $again = sbCall('PUT', $this->ownerCredential, '/shared-budgets/'.$this->id.'/invitations/'.$second, [])->assertValidResponse(201);
    sbCall('POST', $this->partnerCredential, '/shared-budget-invitations/decline', ['token' => Str::after($again->json('meta.share_url'), 'token=')])->assertValidRequest()->assertNoContent();
    sbCall('DELETE', $this->ownerCredential, '/shared-budgets/'.$this->id.'/invitations/'.$second, ['version' => 2])->assertValidRequest()->assertValidResponse(200);
});

test('a member cannot invite and a plan without sharing is refused', function () {
    createdBudget($this->ownerCredential, $this->id);
    joinSharedBudget($this->id, $this->partner->id);

    sbCall('PUT', $this->partnerCredential, '/shared-budgets/'.$this->id.'/invitations/'.(string) Str::uuid7(), [])->assertValidRequest()->assertValidResponse(403);

    revokeSharing($this->owner->id);
    sbCall('PUT', $this->ownerCredential, '/shared-budgets/'.$this->id.'/invitations/'.(string) Str::uuid7(), [])->assertValidRequest()->assertValidResponse(403);
});

test('the shared budget feed and heads', function () {
    createdBudget($this->ownerCredential, $this->id);
    joinSharedBudget($this->id, $this->partner->id);
    $wallet = ownedWallet($this->partner->id);
    recordTransaction($this->partner->id, $wallet->id, transactionBody(['shared_budget_id' => $this->id]));

    $feed = sbCall('GET', $this->partnerCredential, '/sync/shared-budgets/'.$this->id.'/changes')->assertValidRequest()->assertValidResponse(200);
    expect(array_column($feed->json('data'), 'type'))->toContain('shared_budget', 'shared_budget_member', 'shared_budget_entry')
        ->and(json_encode($feed->json('data')))->not->toContain($wallet->id);

    sbCall('GET', $this->partnerCredential, '/sync/shared-budgets/heads')->assertValidRequest()->assertValidResponse(200);
    [, $strangerCredential] = walletCaller('stranger@example.test');
    sbCall('GET', $strangerCredential, '/sync/shared-budgets/'.$this->id.'/changes')->assertValidRequest()->assertValidResponse(404);
});

test('the batch applies a shared budget upsert like the single write', function () {
    $response = sbCall('POST', $this->ownerCredential, '/sync/batch', ['items' => [['type' => 'shared_budget', 'op' => 'upsert', 'id' => $this->id, 'data' => sbBody()]]]);

    expect($response->json('data.results.0.outcome') ?? $response->json('data.0.outcome'))->toBe('applied');
});

test('a transaction names its budget over HTTP, and a foreign one is refused', function () {
    createdBudget($this->ownerCredential, $this->id);
    $wallet = ownedWallet($this->owner->id);
    $url = '/wallets/'.$wallet->id.'/transactions/'.(string) Str::uuid7();

    $created = sbCall('PUT', $this->ownerCredential, $url, transactionBody(['shared_budget_id' => $this->id, 'date' => now()->format('Y-m-d')]))->assertValidRequest()->assertValidResponse(201);
    expect($created->json('data.shared_budget_id'))->toBe($this->id);

    $foreign = (string) Str::uuid7();
    sbCall('PUT', $this->partnerCredential, '/shared-budgets/'.$foreign, sbBody())->assertValidResponse(201);
    $refused = sbCall('PUT', $this->ownerCredential, '/wallets/'.$wallet->id.'/transactions/'.(string) Str::uuid7(), transactionBody(['shared_budget_id' => $foreign]))->assertValidRequest()->assertValidResponse(422);
    expect($refused->json('errors.0.pointer') ?? $refused->json('errors.0.field') ?? json_encode($refused->json()))->toContain('shared_budget_id');
});

test('leaving a budget keeps a transaction\'s link but the entry stops following it', function () {
    createdBudget($this->ownerCredential, $this->id);
    joinSharedBudget($this->id, $this->partner->id);
    $wallet = ownedWallet($this->partner->id);
    $url = '/wallets/'.$wallet->id.'/transactions/'.(string) Str::uuid7();
    sbCall('PUT', $this->partnerCredential, $url, transactionBody(['shared_budget_id' => $this->id, 'description' => 'Gift']))->assertValidResponse(201);

    sbCall('POST', $this->partnerCredential, '/shared-budgets/'.$this->id.'/leave')->assertNoContent();

    $feed = sbCall('GET', $this->ownerCredential, '/sync/shared-budgets/'.$this->id.'/changes')->assertValidResponse(200);
    $entry = collect($feed->json('data'))->firstWhere('type', 'shared_budget_entry');
    expect($entry['record']['description'])->toBeNull()->and($entry['record']['detached_at'])->not->toBeNull();
});
