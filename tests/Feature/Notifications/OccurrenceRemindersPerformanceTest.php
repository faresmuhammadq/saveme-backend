<?php

use App\Modules\Notifications\Application\Reminders\SendOccurrenceReminders;
use App\Modules\Wallets\Infrastructure\Models\Occurrence;
use App\Modules\Wallets\Infrastructure\Models\RecurringRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Opt-in (PUSH_PERF=1): 10,000 active rules with an occurrence due today,
 * spread over 100 people, must be reminded in under ten minutes (SC-008).
 */

test('a pass over 10,000 active rules finishes inside ten minutes', function () {
    $people = 100;
    $perPerson = 100;

    for ($i = 0; $i < $people; $i++) {
        $owner = person("perf{$i}@example.test");
        $wallet = ownedWallet($owner->id);
        registerPhone($owner, "perf-phone-{$i}");

        $seed = dueOccurrence($wallet, $owner->id);
        $rule = RecurringRule::query()->findOrFail($seed->recurring_rule_id)->getAttributes();
        $occurrence = $seed->getAttributes();

        $rules = [];
        $occurrences = [];
        for ($n = 1; $n < $perPerson; $n++) {
            $ruleId = (string) Str::uuid7();
            $rules[] = [...$rule, 'id' => $ruleId];
            $occurrences[] = [...$occurrence, 'id' => (string) Str::uuid7(), 'recurring_rule_id' => $ruleId];
        }
        DB::table((new RecurringRule)->getTable())->insert($rules);
        DB::table((new Occurrence)->getTable())->insert($occurrences);
    }

    $started = microtime(true);
    $counts = app(SendOccurrenceReminders::class)->run();
    $seconds = microtime(true) - $started;

    fwrite(STDERR, sprintf("\nreminder pass: %d rules, %.1f s, %s\n", $people * $perPerson, $seconds, json_encode($counts)));

    expect($seconds)->toBeLessThan(600)
        ->and($counts['summarised'])->toBe($people * $perPerson);
})->skip(fn (): bool => getenv('PUSH_PERF') !== '1', 'opt-in: set PUSH_PERF=1');
