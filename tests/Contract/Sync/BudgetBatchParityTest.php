<?php

use App\Modules\Budgeting\Application\BudgetService;
use App\Modules\Budgeting\Infrastructure\Models\Budget;
use Illuminate\Support\Str;

/*
 * Each budget batch item answers exactly what its single write answers (spec
 * 015, US6 S1-S2, FR-020; spec 013 FR-014, ADR-0016 rule 1). The write is sent
 * alone for one person and in a batch for another with an identical fixture;
 * status and record or problem must match. A budget may be upserted and
 * deleted, never restored, and has no wallet.
 */

/**
 * Ids, instants and sequence numbers differ between two fixtures and are not the comparison.
 */
function budgetParityShape(mixed $value): string
{
    $json = (string) json_encode($value);
    $json = (string) preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', 'ID', $json);
    $json = (string) preg_replace('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z/', 'T', $json);

    return (string) preg_replace('/"(change_seq|version)":\d+/', '"$1":0', $json);
}

/**
 * @param  array<string, mixed>  $item
 * @return array{string, string, array<string, mixed>}
 */
function budgetSingleRequestFor(array $item): array
{
    return match ($item['op']) {
        'upsert' => ['PUT', '/budgets/'.$item['id'], $item['data']],
        'delete' => ['DELETE', '/budgets/'.$item['id'], $item['data']],
    };
}

/**
 * @return array<string, Closure(string): array<string, mixed>> fixture builder given the person's id
 */
function budgetParityScenarios(): array
{
    return [
        'create' => fn (string $u) => syncItem('budget', 'upsert', (string) Str::uuid7(), budgetBody()),
        'create all expenses' => fn (string $u) => syncItem('budget', 'upsert', (string) Str::uuid7(), budgetBody(['category_id' => null])),
        'update' => function (string $u) {
            $budget = createBudget($u);

            return syncItem('budget', 'upsert', $budget->id, budgetBody(['amount_minor' => 1], 1));
        },
        'replay' => function (string $u) {
            $budget = createBudget($u);

            return syncItem('budget', 'upsert', $budget->id, budgetBody([], 1));
        },
        'stale' => function (string $u) {
            $budget = createBudget($u);
            saveBudget($u, $budget->id, budgetBody(['amount_minor' => 7], 1));

            return syncItem('budget', 'upsert', $budget->id, budgetBody(['amount_minor' => 9], 1));
        },
        'immutable currency' => function (string $u) {
            $budget = createBudget($u);

            return syncItem('budget', 'upsert', $budget->id, budgetBody(['currency' => 'USD'], 1));
        },
        'invalid category' => fn (string $u) => syncItem('budget', 'upsert', (string) Str::uuid7(), budgetBody(['category_id' => platformCategoryId('income')])),
        'invalid shape' => fn (string $u) => syncItem('budget', 'upsert', (string) Str::uuid7(), budgetBody(['amount_minor' => 0])),
        'unknown id with a version' => fn (string $u) => syncItem('budget', 'upsert', (string) Str::uuid7(), budgetBody([], 3)),
        'delete' => function (string $u) {
            $budget = createBudget($u);

            return syncItem('budget', 'delete', $budget->id, ['version' => 1]);
        },
        'delete of a tombstone' => function (string $u) {
            $budget = createBudget($u);
            app(BudgetService::class)->delete($u, $budget->id, 1, presentBudget());

            return syncItem('budget', 'delete', $budget->id, ['version' => 1]);
        },
        'delete stale' => function (string $u) {
            $budget = createBudget($u);
            saveBudget($u, $budget->id, budgetBody(['amount_minor' => 7], 1));

            return syncItem('budget', 'delete', $budget->id, ['version' => 1]);
        },
        'delete unknown' => fn (string $u) => syncItem('budget', 'delete', (string) Str::uuid7(), ['version' => 1]),
    ];
}

test('a batch item answers what the same single write answers', function (string $name) {
    $fixture = budgetParityScenarios()[$name];

    $slug = Str::slug($name);
    $single = person("single-{$slug}@example.test");
    $batched = person("batch-{$slug}@example.test");
    $singleCredential = signInAs("single-{$slug}@example.test")->json('data.credential');
    $batchedCredential = signInAs("batch-{$slug}@example.test")->json('data.credential');

    $singleItem = $fixture($single->id);
    $batchItem = $fixture($batched->id);

    [$method, $uri, $body] = budgetSingleRequestFor($singleItem);
    $alone = walletCall($method, $singleCredential, $uri, $body);

    $response = postBatch($batchedCredential, [$batchItem]);
    $name === 'invalid shape' ? $response->assertValidResponse(200) : $response->assertValidRequest()->assertValidResponse(200);
    $result = $response->json('data.0');

    $answer = $alone->status() >= 400 ? $alone->json() : $alone->json('data');
    $sent = $result['record'] ?? $result['problem'];

    expect($result['status'])->toBe($alone->status())
        ->and($result['id'])->toBe($batchItem['id'])
        ->and($result['type'])->toBe('budget')
        ->and(budgetParityShape($sent))->toBe(budgetParityShape($answer))
        ->and($result['outcome'])->toBe(match (true) {
            $alone->status() === 200, $alone->status() === 201 => 'applied',
            $alone->status() === 409 && ($alone->json('code') === 'VERSION_CONFLICT') => 'conflict',
            default => 'rejected',
        });
})->with(array_keys(budgetParityScenarios()));

test('a batch applies a budget upsert then a delete in order, and the writes are visible on the feed', function () {
    [$user, $credential] = walletCaller();
    $id = (string) Str::uuid7();

    $response = postBatch($credential, [
        syncItem('budget', 'upsert', $id, budgetBody()),
        syncItem('budget', 'delete', $id, ['version' => 1]),
    ])->assertValidRequest()->assertValidResponse(200);

    expect(collect($response->json('data'))->pluck('status')->all())->toBe([201, 200])
        ->and(Budget::query()->findOrFail($id)->deleted_at)->not->toBeNull()
        ->and(collect(readFeedFully($credential, 'user')['rows'])->where('type', 'budget')->first()['record']['version'])->toBe(2);
});

test('a budget item with a wallet_id, or with the restore operation, is an unusable envelope', function () {
    [$user, $credential] = walletCaller();
    $id = (string) Str::uuid7();
    $wallet = ownedWallet($user->id);

    postBatch($credential, [syncItem('budget', 'upsert', $id, budgetBody(), $wallet->id)])
        ->assertValidResponse(422)
        ->assertJsonPath('errors.0.pointer', '/items/0/wallet_id')
        ->assertJsonPath('errors.0.code', 'UNKNOWN_FIELD');

    postBatch($credential, [syncItem('budget', 'restore', $id, ['version' => 1])])
        ->assertValidResponse(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED');

    expect(Budget::query()->count())->toBe(0);
});
