<?php

/*
| Nothing about an operator is stored readable beyond what the baseline lists
| (spec 018, FR-026; security baseline, What is protected at rest).
*/

use App\Modules\Admin\Domain\Ports\EmailLookup;
use App\Modules\Admin\Domain\Ports\SecretSealer;
use App\Modules\Admin\Infrastructure\Models\OperatorAccount;
use Illuminate\Support\Facades\DB;

it('stores no readable email, display name, secret or recovery code', function () {
    $secret = random_bytes(20);
    $operator = OperatorAccount::factory()
        ->withEmail('mariam.operator@example.test')
        ->withDisplayName('Mariam Khalil')
        ->enrolled($secret)
        ->withRecoveryCodes(['ABCDE-FGHJK', '23456-789AB'])
        ->create();

    $row = json_encode(DB::table('operator_accounts')->where('id', $operator->id)->first());
    $codes = json_encode(DB::table('operator_recovery_codes')->where('operator_id', $operator->id)->get());

    expect($row)->not->toContain('mariam.operator')
        ->and($row)->not->toContain('Mariam')
        ->and($row)->not->toContain(base64_encode($secret))
        ->and($row)->not->toContain(bin2hex($secret))
        ->and($codes)->not->toContain('ABCDE')
        ->and($codes)->not->toContain('FGHJK')
        ->and($codes)->not->toContain('23456');
});

it('seals under the active key reference and binds each value to its operator and field', function () {
    $operator = OperatorAccount::factory()->withEmail('ada@example.test')->create();
    $sealer = app(SecretSealer::class);

    expect($operator->email_sealed)->toStartWith('v1.a1.')
        ->and($sealer->open($operator->email_sealed, $operator->id, 'email'))->toBe('ada@example.test');

    expect(fn () => $sealer->open($operator->email_sealed, $operator->id, 'display_name'))->toThrow(RuntimeException::class)
        ->and(fn () => $sealer->open($operator->email_sealed, '00000000-0000-7000-8000-000000000000', 'email'))->toThrow(RuntimeException::class);
});

it('finds an operator by the keyed lookup of the normalised email', function () {
    $operator = OperatorAccount::factory()->withEmail('Ada@Example.Test')->create();

    expect(OperatorAccount::query()->where('email_lookup', app(EmailLookup::class)->lookup(' ada@example.test '))->value('id'))->toBe($operator->id);
});
