<?php

/*
| Provisioning from the command line (spec 018, FR-003 to FR-005, FR-063;
| contracts/commands-and-audit.md).
*/

use App\Modules\Admin\Domain\Operators\ReenrolmentState;
use App\Modules\Admin\Domain\Operators\SignInStage;
use App\Modules\Admin\Domain\Ports\EmailLookup;
use App\Modules\Admin\Domain\Ports\RecoveryCodeStore;
use App\Modules\Admin\Http\Auth\PendingSignIn;
use App\Modules\Admin\Infrastructure\Models\OperatorAccount;
use App\Modules\Admin\Infrastructure\Models\OperatorRecoveryCode;
use App\Modules\Admin\Infrastructure\Models\OperatorReenrolmentRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * @return array{code: int, output: string, password: string|null}
 */
function runOperatorCommand(string $command, array $arguments): array
{
    $code = Artisan::call($command, $arguments);
    $output = Artisan::output();
    preg_match('/Temporary password \(shown once\): (\S+)/', $output, $matches);

    return ['code' => $code, 'output' => $output, 'password' => $matches[1] ?? null];
}

function operatorByEmail(string $email): OperatorAccount
{
    return OperatorAccount::query()->where('email_lookup', app(EmailLookup::class)->lookup($email))->firstOrFail();
}

function adminActions(string $subjectId): array
{
    return DB::table('audit_entries')->where('event', 'admin_action')->where('subject_id', $subjectId)->orderBy('occurred_at')->get()
        ->map(fn (stdClass $entry): string => json_decode($entry->detail, true)['action'])->all();
}

it('creates an operator and prints the temporary password once', function () {
    $this->freezeTime();

    $result = runOperatorCommand('admin:operator:create', ['email' => 'Mariam@Example.test', '--name' => 'Mariam Khalil']);
    $operator = operatorByEmail('mariam@example.test');
    $entry = DB::table('audit_entries')->where('subject_id', $operator->id)->first();

    expect($result['code'])->toBe(0)
        ->and($result['output'])->toContain('Operator created: Mariam@Example.test')
        ->and($result['password'])->toHaveLength(20)
        ->and(substr_count($result['output'], $result['password']))->toBe(1)
        ->and($operator->state)->toBe('awaiting_password_change')
        ->and(Hash::check($result['password'], $operator->password_hash))->toBeTrue()
        ->and($operator->temporary_password_expires_at->equalTo(now()->addHours(72)))->toBeTrue()
        ->and($entry->event)->toBe('admin_action')
        ->and($entry->actor_type)->toBe('system')
        ->and($entry->actor_id)->toBeNull()
        ->and(json_decode($entry->detail, true))->toBe(['action' => 'operator_created'])
        ->and(json_encode(DB::table('audit_entries')->get()))->not->toContain($result['password']);
});

it('refuses a duplicate email, a missing name and an invalid email, changing nothing', function (array $arguments, string $message) {
    makeOperator('enrolled', ['email' => 'taken@example.test']);
    $before = OperatorAccount::query()->count();

    $result = runOperatorCommand('admin:operator:create', $arguments);

    expect($result['code'])->toBe(1)
        ->and($result['output'])->toContain($message)
        ->and($result['password'])->toBeNull()
        ->and(OperatorAccount::query()->count())->toBe($before);
})->with([
    'duplicate after normalising' => [['email' => ' Taken@Example.TEST ', '--name' => 'Someone'], 'An operator with this email already exists.'],
    'no name' => [['email' => 'new@example.test'], 'A display name is required.'],
    'blank name' => [['email' => 'new@example.test', '--name' => '   '], 'A display name is required.'],
    'name too long' => [['email' => 'new@example.test', '--name' => str_repeat('n', 81)], 'A display name is required.'],
    'not an email' => [['email' => 'nobody', '--name' => 'Someone'], 'The email is not valid.'],
]);

it('reissues a temporary password, keeping the secret and recovery codes', function () {
    ['operator' => $operator, 'email' => $email, 'secret' => $secret] = makeOperator();
    app(RecoveryCodeStore::class)->replaceSet($operator->id, [str_repeat('a', 64)]);
    $epoch = $operator->credential_epoch;

    $result = runOperatorCommand('admin:operator:reset-password', ['email' => $email]);
    $operator->refresh();

    expect($result['code'])->toBe(0)
        ->and($result['password'])->toHaveLength(20)
        ->and($operator->state)->toBe('awaiting_password_change')
        ->and($operator->credential_epoch)->toBe($epoch + 1)
        ->and($operator->secret_sealed)->not->toBeNull()
        ->and($operator->enrolled_at)->not->toBeNull()
        ->and(OperatorRecoveryCode::query()->where('operator_id', $operator->id)->count())->toBe(1)
        ->and(Hash::check($result['password'], $operator->password_hash))->toBeTrue()
        ->and(adminActions($operator->id))->toBe(['temporary_password_issued']);
});

it('refuses to reset an unknown or a deactivated operator', function () {
    ['email' => $deactivated] = makeOperator('deactivated');

    expect(runOperatorCommand('admin:operator:reset-password', ['email' => 'nobody@example.test']))
        ->toMatchArray(['code' => 1])
        ->and(runOperatorCommand('admin:operator:reset-password', ['email' => 'nobody@example.test'])['output'])->toContain('No operator with this email.')
        ->and(runOperatorCommand('admin:operator:reset-password', ['email' => $deactivated])['output'])->toContain('This operator is deactivated.');
});

it('deactivates an operator, ends their sessions and cancels an open re-enrolment request', function () {
    ['operator' => $operator, 'email' => $email] = makeOperator();
    makeOperator();
    $request = OperatorReenrolmentRequest::query()->create([
        'operator_id' => $operator->id, 'state' => 'open', 'opened_at' => now(), 'expires_at' => now()->addHours(72),
    ]);
    $epoch = $operator->credential_epoch;

    $result = runOperatorCommand('admin:operator:deactivate', ['email' => $email]);
    $operator->refresh();

    expect($result['code'])->toBe(0)
        ->and($result['output'])->toContain("Operator deactivated: {$email}")
        ->and($operator->state)->toBe('deactivated')
        ->and($operator->credential_epoch)->toBe($epoch + 1)
        ->and($request->refresh()->state)->toBe(ReenrolmentState::Cancelled->value)
        ->and(adminActions($operator->id))->toBe(['reenrolment_cancelled', 'operator_deactivated']);
});

it('refuses to deactivate the last active operator, an unknown one or one already deactivated', function () {
    ['email' => $only] = makeOperator();
    ['email' => $gone] = makeOperator('deactivated');

    expect(runOperatorCommand('admin:operator:deactivate', ['email' => $only]))
        ->toMatchArray(['code' => 1])
        ->and(runOperatorCommand('admin:operator:deactivate', ['email' => $only])['output'])->toContain('This is the last active operator; create another first.')
        ->and(runOperatorCommand('admin:operator:deactivate', ['email' => $gone])['output'])->toContain('Already deactivated.')
        ->and(runOperatorCommand('admin:operator:deactivate', ['email' => 'nobody@example.test'])['output'])->toContain('No operator with this email.');

    expect(operatorByEmail($only)->state)->toBe('active');
});

it('lets an operator who is not active yet be deactivated even when they are the only operator', function () {
    ['email' => $email] = makeOperator('awaiting_password_change');

    expect(runOperatorCommand('admin:operator:deactivate', ['email' => $email])['code'])->toBe(0);
});

it('runs without interaction and prints nothing but the temporary password and the confirmation', function () {
    $result = runOperatorCommand('admin:operator:create', ['email' => 'quiet@example.test', '--name' => 'Quiet One', '--no-interaction' => true]);

    expect(trim($result['output']))->toBe("Operator created: quiet@example.test\nTemporary password (shown once): {$result['password']}");
});

it('records the sign-in stage a created operator starts at', function () {
    $result = runOperatorCommand('admin:operator:create', ['email' => 'first@example.test', '--name' => 'First']);

    $this->post(route('admin.sign-in.submit'), ['email' => 'first@example.test', 'password' => $result['password']])
        ->assertRedirect(route(PendingSignIn::routeFor(SignInStage::PasswordChange)));
});
