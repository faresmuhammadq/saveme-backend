<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/*
 * Postings are never exposed (P-04 rule 6, spec 012 FR-025) and no amount or
 * text reaches a log (P-15 rule 4, FR-026).
 */

beforeEach(function () {
    [$this->user, $this->credential] = walletCaller();
    $this->wallet = ownedWallet($this->user->id);
    $this->other = ownedWallet($this->user->id);
    $this->tid = (string) Str::uuid7();
});

/**
 * @return list<string>
 */
function memberNames(mixed $value, string $prefix = ''): array
{
    $names = [];

    if (is_array($value)) {
        foreach ($value as $key => $child) {
            $names[] = is_string($key) ? $key : $prefix;
            $names = [...$names, ...memberNames($child)];
        }
    }

    return $names;
}

test('no response of any transaction or wallet operation contains postings or counter_change_seq', function () {
    $responses = [
        transactionCall('PUT', $this->credential, $this->wallet->id, $this->tid, transferBody($this->other->id)),
        transactionCall('GET', $this->credential, $this->wallet->id, $this->tid),
        transactionCall('GET', $this->credential, $this->other->id, $this->tid),
        walletCall('GET', $this->credential, '/wallets/'.$this->wallet->id.'/transactions'),
        walletCall('GET', $this->credential, '/wallets/'.$this->other->id.'/transactions'),
        transactionCall('PATCH', $this->credential, $this->wallet->id, $this->tid, ['amount_minor' => 99, 'version' => 1]),
        transactionCall('DELETE', $this->credential, $this->wallet->id, $this->tid, ['version' => 2]),
        transactionCall('PATCH', $this->credential, $this->wallet->id, $this->tid, ['deleted_at' => null, 'version' => 3]),
        walletCall('GET', $this->credential, '/wallets/'.$this->wallet->id),
        walletCall('GET', $this->credential, '/wallets'),
        transactionCall('PATCH', $this->credential, $this->wallet->id, $this->tid, ['amount_minor' => 1, 'version' => 1]),
    ];

    foreach ($responses as $response) {
        $names = memberNames($response->json());

        expect($names)->not->toContain('postings')->and($names)->not->toContain('counter_change_seq');
    }
});

test('no log context of a create, an edit or a refusal contains an amount, description, memo or counterparty', function () {
    Log::spy();

    transactionCall('PUT', $this->credential, $this->wallet->id, $this->tid, transactionBody(['amount_minor' => 7654321, 'description' => 'SECRETDESC', 'memo' => 'SECRETMEMO', 'counterparty' => 'SECRETPERSON']))->assertCreated();
    transactionCall('PATCH', $this->credential, $this->wallet->id, $this->tid, ['memo' => 'SECRETMEMO2', 'version' => 1])->assertOk();
    transactionCall('PUT', $this->credential, $this->wallet->id, (string) Str::uuid7(), transactionBody(['amount_minor' => 7654321, 'currency' => 'USD', 'description' => 'SECRETDESC']))->assertStatus(422);
    transactionCall('PATCH', $this->credential, $this->wallet->id, $this->tid, ['memo' => 'SECRETSTALE', 'version' => 1])->assertStatus(409);

    Log::shouldNotHaveReceived('info', fn (...$arguments): bool => str_contains(json_encode($arguments), 'SECRET') || str_contains(json_encode($arguments), '7654321'));
    foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical'] as $level) {
        Log::shouldNotHaveReceived($level, fn (...$arguments): bool => str_contains((string) json_encode($arguments), 'SECRET') || str_contains((string) json_encode($arguments), '7654321'));
    }
});
