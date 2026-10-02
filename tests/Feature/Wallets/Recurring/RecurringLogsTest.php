<?php

use Illuminate\Support\Facades\Log;

/*
 * No amount, description, memo or counterparty in any log line (P-15, spec
 * 014, FR-030); the job writes one summary line with counts.
 */

beforeEach(function () {
    freezeClock('2026-10-05 10:00:00');
    $this->world = recurringWorld();
    $this->wallet = $this->world['wallet'];
});

it('logs no amount or text from a rule write, an occurrence write, a refusal or a job run', function () {
    Log::spy();
    $secrets = ['4242421', 'Secret rent text', 'Secret memo text', 'Secret landlord'];

    $rule = createRecurringRule($this->world['owner']->id, $this->wallet->id, recurringRuleBody(['amount_minor' => 4242421, 'description' => $secrets[1], 'memo' => $secrets[2], 'counterparty' => $secrets[3], 'auto_confirm' => true]))->rule;
    $occurrence = occurrencesOf($rule->id)->first();
    saveRecurringOccurrence($this->world['owner']->id, $this->wallet->id, $occurrence->id, occurrenceBody($occurrence, ['status' => 'skipped']));
    refusalOf(fn () => saveRecurringRule($this->world['owner']->id, $this->wallet->id, $rule->id, recurringRuleBody(['amount_minor' => 4242421, 'currency' => 'USD', 'description' => $secrets[1]], 1)));
    freezeClock('2026-11-02 08:00:00');
    runRecurringJob();

    Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context = []) use ($secrets): bool {
        $line = $message.json_encode($context);
        foreach ($secrets as $secret) {
            expect($line)->not->toContain($secret);
        }

        return true;
    });
    Log::shouldNotHaveReceived('error');
});

it('writes one summary line per job run with counts only', function () {
    createRecurringRule($this->world['owner']->id, $this->wallet->id, recurringRuleBody(['auto_confirm' => true]));
    Log::spy();

    runRecurringJob();

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context = []): bool => $message === 'Recurring rules materialised' && array_keys($context) === ['rules', 'materialised', 'confirmed', 'refused', 'failed'])->once();
});
