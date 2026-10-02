<?php

use App\Modules\Wallets\Application\Recurring\RecurringRuleService;

/*
 * Rules and occurrences on the wallet feed (spec 014, US5 scenario 2, FR-023):
 * commit order by the wallet counter, tombstones included, each row exactly
 * its read, server-made writes too, a transfer rule on its source's feed only.
 */

beforeEach(function () {
    freezeClock('2026-10-05 10:00:00');
    [$this->ada, $this->adaCredential] = walletCaller('ada@example.test');
    $this->wallet = ownedWallet($this->ada->id);
});

test('rule and occurrence rows are in change_seq order, tombstones included, and equal their reads', function () {
    $rule = createRecurringRule($this->ada->id, $this->wallet->id, recurringRuleBody(['frequency' => 'weekly', 'anchor_day' => null, 'start_date' => '2026-10-06']))->rule;
    $gone = createRecurringRule($this->ada->id, $this->wallet->id, recurringRuleBody(['description' => 'Gym']))->rule;
    app(RecurringRuleService::class)->delete($this->ada->id, $this->wallet->id, $gone->id, 1, presentRule());

    $page = readFeed($this->adaCredential, $this->wallet->id, null, 200)->assertValidRequest()->assertValidResponse(200);
    $rows = collect($page->json('data'));

    $ruleRows = $rows->where('type', 'recurring_rule');
    $occurrenceRows = $rows->where('type', 'occurrence');
    $positions = $rows->map(fn (array $row): int => $row['record']['change_seq'])->all();

    expect($ruleRows)->toHaveCount(2)
        ->and($occurrenceRows->count())->toBe(occurrencesOf($rule->id, true)->count() + occurrencesOf($gone->id, true)->count())
        ->and($ruleRows->firstWhere('record.id', $gone->id)['record']['deleted_at'])->not->toBeNull()
        ->and($occurrenceRows->every(fn (array $row): bool => $row['record']['recurring_rule_id'] === $rule->id || $row['record']['recurring_rule_id'] === $gone->id))->toBeTrue();

    foreach ($rows->whereIn('type', ['recurring_rule', 'occurrence']) as $row) {
        $path = $row['type'] === 'recurring_rule' ? 'recurring-rules' : 'occurrences';
        $read = walletCall('GET', $this->adaCredential, '/wallets/'.$this->wallet->id.'/'.$path.'/'.$row['record']['id'])->assertOk()->json('data');

        expect($row['record'])->toBe($read);
    }

    $sorted = $positions;
    sort($sorted);
    expect($positions)->toBe($sorted);
});

test('server-made writes appear: materialise, auto-confirm and propagation', function () {
    $rule = createRecurringRule($this->ada->id, $this->wallet->id, recurringRuleBody(['auto_confirm' => true, 'frequency' => 'weekly', 'anchor_day' => null, 'start_date' => '2026-10-06']))->rule;
    $cursor = readFeedFully($this->adaCredential, $this->wallet->id)['cursor'];

    freezeClock('2026-10-14 08:00:00');
    runRecurringJob();

    $rows = readFeedFully($this->adaCredential, $this->wallet->id, $cursor)['rows'];
    $types = collect($rows)->pluck('type')->unique()->sort()->values()->all();
    $confirmed = collect($rows)->where('type', 'occurrence')->filter(fn (array $row): bool => $row['record']['status'] === 'confirmed');

    expect($types)->toBe(['occurrence', 'transaction', 'wallet'])
        ->and($confirmed)->toHaveCount(2)
        ->and(collect($rows)->where('type', 'transaction'))->toHaveCount(2);

    $cursor = readFeedFully($this->adaCredential, $this->wallet->id)['cursor'];
    saveRecurringRule($this->ada->id, $this->wallet->id, $rule->id, [...recurringRuleBody(['auto_confirm' => true, 'frequency' => 'weekly', 'anchor_day' => null, 'start_date' => '2026-10-06', 'amount_minor' => 500000]), 'version' => 1]);

    $after = readFeedFully($this->adaCredential, $this->wallet->id, $cursor)['rows'];

    expect(collect($after)->where('type', 'recurring_rule'))->toHaveCount(1)
        ->and(collect($after)->where('type', 'occurrence')->count())->toBeGreaterThan(0);
});

test('a transfer rule rides its source wallet\'s feed only while its transactions ride both', function () {
    $destination = ownedWallet($this->ada->id);
    $rule = createRecurringRule($this->ada->id, $this->wallet->id, recurringRuleBody(['kind' => 'transfer', 'category_id' => null, 'counter_wallet_id' => $destination->id, 'auto_confirm' => true]))->rule;

    freezeClock('2026-11-01 08:00:00');
    runRecurringJob();

    $source = collect(readFeedFully($this->adaCredential, $this->wallet->id)['rows']);
    $target = collect(readFeedFully($this->adaCredential, $destination->id)['rows']);

    expect($source->where('type', 'recurring_rule')->pluck('record.id')->all())->toBe([$rule->id])
        ->and($source->where('type', 'occurrence'))->toHaveCount(2)
        ->and($target->where('type', 'recurring_rule'))->toHaveCount(0)
        ->and($target->where('type', 'occurrence'))->toHaveCount(0)
        ->and($source->where('type', 'transaction'))->toHaveCount(1)
        ->and($target->where('type', 'transaction'))->toHaveCount(1);
});
