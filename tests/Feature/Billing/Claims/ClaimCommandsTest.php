<?php

use App\Modules\Billing\Application\Jobs\SendClaimApprovedMail;
use App\Modules\Billing\Application\Jobs\SendClaimRejectedMail;
use App\Modules\Billing\Infrastructure\Models\PaymentClaim;
use App\Modules\Billing\Infrastructure\Models\RedeemCode;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 * The command line for the review commands (spec 020, FR-025, the C11
 * integration checkpoint).
 */

beforeEach(function () {
    $this->freezeTime();
    Queue::fake();
    $this->operator = makeOperator()['operator'];
});

/**
 * @param  array<string, mixed>  $arguments
 * @return array{code: int, output: string}
 */
function reviewCommand(string $command, array $arguments): array
{
    $code = Artisan::call($command, $arguments);

    return ['code' => $code, 'output' => Artisan::output()];
}

test('the list shows submitted claims with their reference and duplicates, and never an address', function () {
    $first = PaymentClaim::factory()->forAddress('mona@example.test')->reference('DUPLICATE001')->create();
    PaymentClaim::factory()->forAddress('karim@example.test')->reference('DUPLICATE001')->create();
    PaymentClaim::factory()->forAddress('closed@example.test')->rejected()->create();

    $result = reviewCommand('billing:claim:list', []);

    expect($result['code'])->toBe(0)
        ->and($result['output'])->toContain($first->id)->toContain('Plus · Yearly')->toContain('1290.00 EGP')->toContain('DUPLICATE001')
        ->and($result['output'])->not->toContain('example.test')->not->toContain('v1.b1.')->not->toContain('closed')
        ->and(substr_count($result['output'], 'DUPLICATE001'))->toBe(2);
});

test('an empty queue says so', function () {
    expect(reviewCommand('billing:claim:list', [])['output'])->toContain('No claims are waiting for review.');
});

test('approve works for an active operator and prints nothing secret', function () {
    $claim = PaymentClaim::factory()->create();

    $result = reviewCommand('billing:claim:approve', ['claim' => $claim->id, '--operator' => $this->operator->id]);

    $plain = null;
    Queue::assertPushed(SendClaimApprovedMail::class, function (SendClaimApprovedMail $job) use (&$plain): bool {
        $plain = $job->code;

        return true;
    });

    expect($result['code'])->toBe(0)
        ->and($claim->fresh()->status)->toBe('approved')
        ->and($claim->fresh()->reviewed_by)->toBe($this->operator->id)
        ->and($result['output'])->toContain('approved')->not->toContain($plain)->not->toContain(str_replace('-', '', $plain));
});

test('an unknown, deactivated or malformed operator changes nothing and exits 1', function (string $kind) {
    $claim = PaymentClaim::factory()->create();
    $operator = match ($kind) {
        'unknown' => (string) Str::uuid7(),
        'deactivated' => makeOperator('deactivated')['operator']->id,
        'not enrolled' => makeOperator('awaiting_enrolment')['operator']->id,
        'malformed' => 'me',
        'missing' => '',
    };

    foreach ([
        ['billing:claim:approve', []],
        ['billing:claim:reject', ['--reason' => 'other']],
        ['billing:claim:revoke-code', ['--reason' => 'mistake']],
    ] as [$command, $options]) {
        $result = reviewCommand($command, ['claim' => $claim->id, '--operator' => $operator, ...$options]);

        expect($result['code'])->toBe(1)->and($result['output'])->toContain('active operator');
    }

    expect($claim->fresh()->status)->toBe('submitted')
        ->and(DB::table('audit_entries')->where('event', 'claim_reviewed')->count())->toBe(0);
    Queue::assertNothingPushed();
})->with(['unknown', 'deactivated', 'not enrolled', 'malformed', 'missing']);

test('reject takes a reason and an optional note', function () {
    $claim = PaymentClaim::factory()->create();

    $result = reviewCommand('billing:claim:reject', ['claim' => $claim->id, '--operator' => $this->operator->id, '--reason' => 'amount_mismatch', '--note' => 'paid 100 less']);

    expect($result['code'])->toBe(0)
        ->and($claim->fresh()->status)->toBe('rejected')
        ->and($claim->fresh()->reject_reason)->toBe('amount_mismatch')
        ->and($claim->fresh()->review_note)->toBe('paid 100 less');
    Queue::assertPushed(SendClaimRejectedMail::class, 1);
});

test('revoke-code voids an issued code', function () {
    $claim = PaymentClaim::factory()->create();
    reviewCommand('billing:claim:approve', ['claim' => $claim->id, '--operator' => $this->operator->id]);

    $result = reviewCommand('billing:claim:revoke-code', ['claim' => $claim->id, '--operator' => $this->operator->id, '--reason' => 'wrong customer']);

    expect($result['code'])->toBe(0)->and(RedeemCode::query()->sole()->status)->toBe('revoked');
});

test('the commands refuse as the service does, with the translated message and exit 1', function () {
    $approved = PaymentClaim::factory()->approved()->create();
    $submitted = PaymentClaim::factory()->create();
    $operator = $this->operator->id;

    $again = reviewCommand('billing:claim:approve', ['claim' => $approved->id, '--operator' => $operator]);
    $badReason = reviewCommand('billing:claim:reject', ['claim' => $submitted->id, '--operator' => $operator, '--reason' => 'because']);
    $noCode = reviewCommand('billing:claim:revoke-code', ['claim' => $submitted->id, '--operator' => $operator, '--reason' => 'mistake']);
    $noReason = reviewCommand('billing:claim:revoke-code', ['claim' => $approved->id, '--operator' => $operator, '--reason' => '']);

    expect($again['code'])->toBe(1)->and($again['output'])->toContain('cannot be reviewed')
        ->and($badReason['code'])->toBe(1)->and($badReason['output'])->toContain('/reason')
        ->and($noCode['code'])->toBe(1)->and($noCode['output'])->toContain('Only a code that was issued')
        ->and($noReason['code'])->toBe(1)
        ->and($submitted->fresh()->status)->toBe('submitted');
});
