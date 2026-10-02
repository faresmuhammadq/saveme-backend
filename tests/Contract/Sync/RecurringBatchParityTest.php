<?php

use App\Modules\Wallets\Application\Recurring\RecurringRuleService;
use App\Modules\Wallets\Infrastructure\Models\Occurrence;
use Illuminate\Support\Str;

/*
 * Each recurring batch item answers exactly what its single write answers
 * (spec 014, US5 scenario 3, FR-023; spec 013 FR-014, ADR-0016 rule 1). The
 * write is sent alone for one person and in a batch for another with an
 * identical fixture; status and record or problem must match. A rule may be
 * upserted and deleted, an occurrence only upserted.
 */

/**
 * Ids, instants and sequence numbers differ between two fixtures and are not the comparison.
 */
function recurringParityShape(mixed $value): string
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
function recurringSingleRequestFor(array $item): array
{
    $id = $item['id'];
    $wallet = $item['wallet_id'];

    return match ([$item['type'], $item['op']]) {
        ['recurring_rule', 'upsert'] => ['PUT', "/wallets/{$wallet}/recurring-rules/{$id}", $item['data']],
        ['recurring_rule', 'delete'] => ['DELETE', "/wallets/{$wallet}/recurring-rules/{$id}", $item['data']],
        ['occurrence', 'upsert'] => ['PUT', "/wallets/{$wallet}/occurrences/{$id}", $item['data']],
    };
}

/**
 * @return array<string, Closure(string): array<string, mixed>> fixture builder given the person's id
 */
function recurringParityScenarios(): array
{
    $world = function (string $u): array {
        freezeClock('2026-10-05 10:00:00');
        $wallet = ownedWallet($u, ['name' => 'Fixture']);
        $rule = createRecurringRule($u, $wallet->id, recurringRuleBody(['frequency' => 'weekly', 'anchor_day' => null, 'start_date' => '2026-10-06']))->rule;

        return [$wallet, $rule, occurrencesOf($rule->id)->first()];
    };

    return [
        'rule create' => fn (string $u) => syncItem('recurring_rule', 'upsert', (string) Str::uuid7(), recurringRuleBody(), ownedWallet($u)->id),
        'rule update' => function (string $u) use ($world) {
            [$wallet, $rule] = $world($u);

            return syncItem('recurring_rule', 'upsert', $rule->id, recurringRuleBody(['frequency' => 'weekly', 'anchor_day' => null, 'start_date' => '2026-10-06', 'amount_minor' => 1], 1), $wallet->id);
        },
        'rule replay' => function (string $u) use ($world) {
            [$wallet, $rule] = $world($u);

            return syncItem('recurring_rule', 'upsert', $rule->id, recurringRuleBody(['frequency' => 'weekly', 'anchor_day' => null, 'start_date' => '2026-10-06'], 1), $wallet->id);
        },
        'rule stale' => function (string $u) use ($world) {
            [$wallet, $rule] = $world($u);
            saveRecurringRule($u, $wallet->id, $rule->id, recurringRuleBody(['frequency' => 'weekly', 'anchor_day' => null, 'start_date' => '2026-10-06', 'amount_minor' => 7], 1));

            return syncItem('recurring_rule', 'upsert', $rule->id, recurringRuleBody(['frequency' => 'weekly', 'anchor_day' => null, 'start_date' => '2026-10-06', 'amount_minor' => 9], 1), $wallet->id);
        },
        'rule invalid' => fn (string $u) => syncItem('recurring_rule', 'upsert', (string) Str::uuid7(), recurringRuleBody(['currency' => 'USD']), ownedWallet($u)->id),
        'rule delete' => function (string $u) use ($world) {
            [$wallet, $rule] = $world($u);

            return syncItem('recurring_rule', 'delete', $rule->id, ['version' => 1], $wallet->id);
        },
        'rule delete of a tombstone' => function (string $u) use ($world) {
            [$wallet, $rule] = $world($u);
            app(RecurringRuleService::class)->delete($u, $wallet->id, $rule->id, 1, presentRule());

            return syncItem('recurring_rule', 'delete', $rule->id, ['version' => 1], $wallet->id);
        },
        'occurrence confirm' => function (string $u) use ($world) {
            [$wallet, , $occurrence] = $world($u);

            return syncItem('occurrence', 'upsert', $occurrence->id, [...confirmBody($occurrence, ['transaction_id' => (string) Str::uuid7()])], $wallet->id);
        },
        'occurrence skip' => function (string $u) use ($world) {
            [$wallet, , $occurrence] = $world($u);

            return syncItem('occurrence', 'upsert', $occurrence->id, occurrenceBody($occurrence, ['status' => 'skipped']), $wallet->id);
        },
        'occurrence stale' => function (string $u) use ($world) {
            [$wallet, , $occurrence] = $world($u);
            saveRecurringOccurrence($u, $wallet->id, $occurrence->id, occurrenceBody($occurrence, ['amount_minor' => 5]));

            return syncItem('occurrence', 'upsert', $occurrence->id, occurrenceBody($occurrence, ['amount_minor' => 6, 'version' => 1]), $wallet->id);
        },
        'occurrence unknown' => function (string $u) use ($world) {
            [$wallet, , $occurrence] = $world($u);

            return syncItem('occurrence', 'upsert', (string) Str::uuid7(), occurrenceBody($occurrence), $wallet->id);
        },
    ];
}

test('a batch item answers what the same single write answers', function (string $name) {
    $fixture = recurringParityScenarios()[$name];

    $slug = Str::slug($name);
    $single = person("single-{$slug}@example.test");
    $batched = person("batch-{$slug}@example.test");
    $singleCredential = signInAs("single-{$slug}@example.test")->json('data.credential');
    $batchedCredential = signInAs("batch-{$slug}@example.test")->json('data.credential');

    $singleItem = $fixture($single->id);
    $batchItem = $fixture($batched->id);

    [$method, $uri, $body] = recurringSingleRequestFor($singleItem);
    $alone = walletCall($method, $singleCredential, $uri, $body);

    $response = postBatch($batchedCredential, [$batchItem]);
    $name === 'rule invalid' ? $response->assertValidResponse(200) : $response->assertValidRequest()->assertValidResponse(200);
    $result = $response->json('data.0');

    $answer = $alone->status() >= 400 ? $alone->json() : $alone->json('data');
    $sent = $result['record'] ?? $result['problem'];

    expect($result['status'])->toBe($alone->status())
        ->and($result['id'])->toBe($batchItem['id'])
        ->and($result['type'])->toBe($batchItem['type'])
        ->and(recurringParityShape($sent))->toBe(recurringParityShape($answer))
        ->and($result['outcome'])->toBe(match (true) {
            $alone->status() === 200, $alone->status() === 201 => 'applied',
            $alone->status() === 409 && ($alone->json('code') === 'VERSION_CONFLICT') => 'conflict',
            default => 'rejected',
        });
})->with(array_keys(recurringParityScenarios()));

test('an occurrence delete and a recurring item without a wallet_id are refused as an unusable envelope', function () {
    [$user, $credential] = walletCaller();
    $wallet = ownedWallet($user->id);
    freezeClock('2026-10-05 10:00:00');
    $rule = createRecurringRule($user->id, $wallet->id)->rule;
    $occurrence = occurrencesOf($rule->id)->first();

    postBatch($credential, [syncItem('occurrence', 'delete', $occurrence->id, ['version' => 1], $wallet->id)])
        ->assertValidResponse(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED');

    foreach ([['recurring_rule', 'upsert', $rule->id, recurringRuleBody([], 1)], ['occurrence', 'upsert', $occurrence->id, occurrenceBody($occurrence)]] as [$type, $op, $id, $data]) {
        postBatch($credential, [syncItem($type, $op, $id, $data)])
            ->assertValidResponse(422)
            ->assertJsonPath('errors.0.pointer', '/items/0/wallet_id')
            ->assertJsonPath('errors.0.code', 'REQUIRED');
    }

    expect(Occurrence::query()->whereKey($occurrence->id)->value('status'))->toBe('scheduled');
});
