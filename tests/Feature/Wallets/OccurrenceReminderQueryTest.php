<?php

use App\Modules\Wallets\Application\Recurring\OccurrenceReminderQuery;
use App\Modules\Wallets\Infrastructure\Models\Occurrence;
use App\Modules\Wallets\Infrastructure\Models\RecurringRule;
use Illuminate\Support\Carbon;

/*
 * What Notifications may ask Wallets: occurrences that need someone now, and
 * who may act on them (spec 023, FR-023, research R-12).
 */

beforeEach(function () {
    $this->owner = person();
    $this->wallet = ownedWallet($this->owner->id);
    $this->now = Carbon::parse('2026-10-06 10:00:00', 'UTC')->toDateTimeImmutable(); // 13:00 in Cairo, 19:00 in Tokyo
    $this->ordinal = 0;
});

function ruleIn(object $test, array $attributes = []): RecurringRule
{
    return RecurringRule::factory()->create(['wallet_id' => $test->wallet->id, 'created_by' => $test->owner->id, ...$attributes]);
}

function occurrenceOf(object $test, RecurringRule $rule, string $date, array $attributes = []): Occurrence
{
    return Occurrence::factory()->forRule($rule)->create([
        'ordinal' => ++$test->ordinal,
        'scheduled_date' => $date,
        'date' => $date,
        ...$attributes,
    ]);
}

function needing(object $test, ?string $after = null, int $limit = 100): array
{
    return app(OccurrenceReminderQuery::class)->needingAttention($test->now, $after, $limit);
}

test('a scheduled manual occurrence dated the rule today or yesterday is due, and nothing earlier or later', function () {
    $rule = ruleIn($this);
    $today = occurrenceOf($this, $rule, '2026-10-06');
    $yesterday = occurrenceOf($this, $rule, '2026-10-05');
    occurrenceOf($this, $rule, '2026-10-04');
    occurrenceOf($this, $rule, '2026-10-07');

    $found = collect(needing($this))->keyBy('occurrenceId');

    expect($found->keys()->sort()->values()->all())->toBe(collect([$today->id, $yesterday->id])->sort()->values()->all())
        ->and($found[$today->id]->kind)->toBe('due')
        ->and($found[$today->id]->walletId)->toBe($this->wallet->id);
});

test('the rule timezone decides what today is, not the server and not the recipient', function () {
    $this->now = Carbon::parse('2026-10-06 20:30:00', 'UTC')->toDateTimeImmutable(); // 23:30 on the 6th in Cairo, 05:30 on the 7th in Tokyo
    $cairo = ruleIn($this, ['timezone' => 'Africa/Cairo']);
    $tokyo = ruleIn($this, ['timezone' => 'Asia/Tokyo']);
    $cairoOnThe7th = occurrenceOf($this, $cairo, '2026-10-07');
    $tokyoOnThe7th = occurrenceOf($this, $tokyo, '2026-10-07');
    $tokyoOnThe8th = occurrenceOf($this, $tokyo, '2026-10-08');

    $ids = collect(needing($this))->pluck('occurrenceId')->all();

    expect($ids)->toContain($tokyoOnThe7th->id)->not->toContain($cairoOnThe7th->id)->not->toContain($tokyoOnThe8th->id);
});

test('a refused automatic occurrence is returned within two days, an unrefused one never', function () {
    $auto = ruleIn($this, ['auto_confirm' => true]);
    $refusedToday = occurrenceOf($this, $auto, '2026-10-06', ['auto_confirm_refusal' => 'not_permitted']);
    $refusedTwoDaysAgo = occurrenceOf($this, $auto, '2026-10-04', ['auto_confirm_refusal' => 'currency_mismatch']);
    occurrenceOf($this, $auto, '2026-10-03', ['auto_confirm_refusal' => 'currency_mismatch']);
    occurrenceOf($this, $auto, '2026-10-05');
    occurrenceOf($this, $auto, '2026-10-06', ['is_payout' => true]);

    $found = collect(needing($this))->keyBy('occurrenceId');

    expect($found->keys()->sort()->values()->all())->toBe(collect([$refusedToday->id, $refusedTwoDaysAgo->id])->sort()->values()->all())
        ->and($found[$refusedToday->id]->kind)->toBe('refused');
});

test('confirmed, skipped and deleted occurrences, deleted wallets, and rules that are not active are left out', function () {
    $rule = ruleIn($this);
    occurrenceOf($this, $rule, '2026-10-06', ['status' => 'skipped']);
    occurrenceOf($this, $rule, '2026-10-05', ['deleted_at' => now()]);
    $confirmed = occurrenceOf($this, $rule, '2026-10-04');
    $keep = occurrenceOf($this, $rule, '2026-10-06', ['is_payout' => true]);

    $ended = ruleIn($this, ['status' => 'ended']);
    occurrenceOf($this, $ended, '2026-10-06');
    $paused = ruleIn($this, ['status' => 'paused']);
    occurrenceOf($this, $paused, '2026-10-06');
    $deletedRule = ruleIn($this, ['deleted_at' => now()]);
    occurrenceOf($this, $deletedRule, '2026-10-06');

    $goneWallet = ownedWallet($this->owner->id, ['deleted_at' => now()]);
    $ruleInGoneWallet = RecurringRule::factory()->create(['wallet_id' => $goneWallet->id, 'created_by' => $this->owner->id]);
    Occurrence::factory()->forRule($ruleInGoneWallet)->create(['scheduled_date' => '2026-10-06', 'date' => '2026-10-06']);

    expect(collect(needing($this))->pluck('occurrenceId')->all())->toBe([$keep->id])
        ->and($confirmed->id)->not->toBeNull();
});

test('recipients are the active members the matrix lets confirm: owner and editor, not viewer, not an ended member', function () {
    $editor = person('editor@example.test');
    $viewer = person('viewer@example.test');
    $left = person('left@example.test');
    addMember($this->wallet->id, $editor->id, 'editor');
    addMember($this->wallet->id, $viewer->id, 'viewer');
    $ended = addMember($this->wallet->id, $left->id, 'editor');
    $ended->forceFill(['deleted_at' => now()])->save();
    occurrenceOf($this, ruleIn($this), '2026-10-06');

    $reminder = needing($this)[0];

    expect($reminder->recipientUserIds)->toHaveCount(2)
        ->and($reminder->recipientUserIds)->toContain($this->owner->id)->toContain($editor->id)
        ->not->toContain($viewer->id)->not->toContain($left->id);
});

test('paging by occurrence id returns each occurrence once', function () {
    $rule = ruleIn($this);
    $all = [];
    foreach ([6, 5] as $day) {
        $all[] = occurrenceOf($this, $rule, "2026-10-0{$day}")->id;
    }
    foreach (range(1, 3) as $n) {
        $other = ruleIn($this);
        $all[] = occurrenceOf($this, $other, '2026-10-06')->id;
    }

    $seen = [];
    $after = null;
    do {
        $page = needing($this, $after, 2);
        foreach ($page as $reminder) {
            $seen[] = $reminder->occurrenceId;
            $after = $reminder->occurrenceId;
        }
    } while (count($page) === 2);

    sort($all);
    expect($seen)->toBe($all);
});

test('nothing needs anyone when nothing is due', function () {
    expect(needing($this))->toBe([]);
});
