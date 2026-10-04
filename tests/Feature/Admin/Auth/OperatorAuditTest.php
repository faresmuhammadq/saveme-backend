<?php

/*
| Each act of operator sign-in writes exactly one audit entry, and no entry
| holds a secret (spec 018, FR-080 to FR-083; contracts/commands-and-audit.md).
*/

use App\Modules\Admin\Application\Reenrolment\ApproveReenrolment;
use App\Modules\Admin\Application\Reenrolment\DeclineReenrolment;
use App\Modules\Admin\Domain\AuditReason;
use App\Modules\Admin\Domain\Operators\SignInStage;
use App\Modules\Admin\Domain\Ports\SecretSealer;
use App\Modules\Admin\Domain\SecondFactor\Base32;
use App\Modules\Admin\Domain\SecondFactor\Totp;
use App\Modules\Admin\Http\Auth\OperatorSession;
use App\Modules\Admin\Http\Livewire\ReplaceAuthenticator;
use App\Modules\Admin\Http\Livewire\SecurityPage;
use App\Modules\Admin\Infrastructure\Models\OperatorAccount;
use App\Modules\Admin\Infrastructure\Models\OperatorReenrolmentRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * @return array<string, int> "event" or "event:action" => how many entries
 */
function auditTally(): array
{
    $tally = [];

    foreach (DB::table('audit_entries')->get() as $entry) {
        $detail = json_decode($entry->detail, true);
        $key = $entry->event === 'admin_action' ? 'admin_action:'.$detail['action'] : $entry->event;
        $tally[$key] = ($tally[$key] ?? 0) + 1;
    }

    ksort($tally);

    return $tally;
}

function lastEntry(string $event, ?string $action = null): stdClass
{
    return DB::table('audit_entries')->where('event', $event)
        ->when($action, fn ($query) => $query->where('detail', 'like', '%"'.$action.'"%'))
        ->orderByDesc('occurred_at')->first();
}

it('writes exactly one entry per act, with the documented actor, subject and detail', function () {
    $secrets = [];

    // Command line: create.
    Artisan::call('admin:operator:create', ['email' => 'nour@example.test', '--name' => 'Nour Hassan']);
    preg_match('/\(shown once\): (\S+)/', Artisan::output(), $temporary);
    $secrets = [...$secrets, 'nour@example.test', $temporary[1]];
    $nour = DB::table('operator_accounts')->first();
    expect(lastEntry('admin_action', 'operator_created'))->toMatchObject(['actor_type' => 'system', 'actor_id' => null, 'subject_type' => 'operator', 'subject_id' => $nour->id]);

    // First sign-in: password change, enrolment, codes, sign-in.
    $this->post(route('admin.sign-in.submit'), ['email' => 'nour@example.test', 'password' => $temporary[1]]);
    $newPassword = 'a brand new passphrase';
    $secrets[] = $newPassword;
    $this->post(route('admin.password.new.submit'), ['password' => $newPassword, 'password_confirmation' => $newPassword]);
    $enrol = $this->get(route('admin.enrol'))->getContent();
    preg_match('/data-test="enrol-key"[^>]*>([^<]+)</', $enrol, $key);
    preg_match('/data-test="enrol-link"[^>]*>([^<]+)</', $enrol, $link);
    $secret = (string) Base32::decode(str_replace(' ', '', trim($key[1])));
    $secrets = [...$secrets, trim($key[1]), str_replace(' ', '', trim($key[1])), html_entity_decode(trim($link[1])), $secret];
    $codesPage = $this->post(route('admin.enrol.submit'), ['one_time_code' => currentCode($secret)])->getContent();
    preg_match('/data-test="recovery-code-list".*?<\/ol>/s', $codesPage, $list);
    preg_match_all('/\b[0-9A-Z]{5}-[0-9A-Z]{5}\b/', $list[0], $shown);
    $secrets = [...$secrets, ...$shown[0], ...array_map(fn (string $code): string => str_replace('-', '', $code), $shown[0])];
    $this->post(route('admin.enrol.done'), ['saved' => '1']);

    expect(lastEntry('password_changed'))->toMatchObject(['actor_type' => 'operator', 'actor_id' => $nour->id, 'subject_id' => $nour->id])
        ->and(lastEntry('admin_action', 'enrolment_completed'))->toMatchObject(['actor_type' => 'operator', 'actor_id' => $nour->id])
        ->and(json_decode(lastEntry('sign_in_succeeded')->detail, true))->toBe(['factor' => 'code', 'recovery_codes_left' => 10]);

    // Security page: regenerate, replace.
    $this->travel(11)->minutes();
    $this->post(route('admin.sign-out'));
    expect(json_decode(lastEntry('session_revoked')->detail, true))->toBe(['reason' => 'signed_out']);

    $nourModel = OperatorAccount::query()->find($nour->id);
    signInOperator($nourModel);
    Livewire::test(SecurityPage::class)->call('regenerate');
    $replace = Livewire::test(ReplaceAuthenticator::class);
    $pending = app(SecretSealer::class)->open($nourModel->refresh()->pending_secret_sealed, $nour->id, 'pending_secret');
    $secrets[] = $pending;
    $this->travel(30)->seconds();
    $replace->set('oneTimeCode', Totp::codeAt($pending, Totp::stepAt(now()->getTimestamp())))->call('confirm');

    // Failures and a lock.
    app(OperatorSession::class)->end();
    foreach (range(1, 5) as $ignored) {
        $this->post(route('admin.sign-in.submit'), ['email' => 'nour@example.test', 'password' => 'wrong wrong wrong']);
    }
    $this->post(route('admin.sign-in.submit'), ['email' => 'ghost@example.test', 'password' => 'wrong wrong wrong']);

    // Re-enrolment: requested by Karim, approved by Nour and Mariam, another declined, another expired, another cancelled.
    $karim = makeOperator('enrolled', ['name' => 'Karim Adel']);
    $mariam = makeOperator('enrolled', ['name' => 'Mariam Said']);
    $secrets = [...$secrets, $karim['email'], $mariam['email'], $karim['password']];
    signInOperatorPending($karim['operator'], SignInStage::SecondFactor);
    $this->post(route('admin.sign-in.lost-device.submit'));
    $request = OperatorReenrolmentRequest::query()->where('operator_id', $karim['operator']->id)->sole();
    app(ApproveReenrolment::class)->handle($nour->id, $request->id, new AuditReason('Phoned him.'));
    app(ApproveReenrolment::class)->handle($mariam['operator']->id, $request->id, new AuditReason('Saw him in person.'));

    $declined = makeOperator('enrolled');
    signInOperatorPending($declined['operator'], SignInStage::SecondFactor);
    $this->post(route('admin.sign-in.lost-device.submit'));
    $declinedRequest = OperatorReenrolmentRequest::query()->where('operator_id', $declined['operator']->id)->sole();
    app(DeclineReenrolment::class)->handle($mariam['operator']->id, $declinedRequest->id, new AuditReason('Not him.'));

    $expiring = makeOperator('enrolled');
    signInOperatorPending($expiring['operator'], SignInStage::SecondFactor);
    $this->post(route('admin.sign-in.lost-device.submit'));
    $this->travel(73)->hours();
    Artisan::call('admin:expire-reenrolment-requests');

    $cancelled = makeOperator('enrolled');
    signInOperatorPending($cancelled['operator'], SignInStage::SecondFactor);
    $this->post(route('admin.sign-in.lost-device.submit'));
    Artisan::call('admin:operator:deactivate', ['email' => $cancelled['email']]);

    Artisan::call('admin:operator:reset-password', ['email' => $mariam['email']]);
    preg_match('/\(shown once\): (\S+)/', Artisan::output(), $reset);
    $secrets[] = $reset[1];

    expect(auditTally())->toBe([
        'admin_action:authenticator_replaced' => 1,
        'admin_action:enrolment_completed' => 1,
        'admin_action:operator_created' => 1,
        'admin_action:operator_deactivated' => 1,
        'admin_action:recovery_codes_regenerated' => 1,
        'admin_action:reenrolment_approved' => 2,
        'admin_action:reenrolment_cancelled' => 1,
        'admin_action:reenrolment_completed' => 1,
        'admin_action:reenrolment_declined' => 1,
        'admin_action:reenrolment_expired' => 1,
        'admin_action:reenrolment_requested' => 4,
        'admin_action:temporary_password_issued' => 1,
        'lockout_applied' => 1,
        'password_changed' => 1,
        'session_revoked' => 1,
        'sign_in_failed' => 6,
        'sign_in_succeeded' => 1,
    ]);

    $approved = DB::table('audit_entries')->where('detail', 'like', '%reenrolment_approved%')->orderBy('occurred_at')->first();
    expect($approved)->toMatchObject(['actor_type' => 'operator', 'actor_id' => $nour->id, 'subject_id' => $karim['operator']->id])
        ->and(json_decode($approved->detail, true))->toEqual(['action' => 'reenrolment_approved', 'reenrolment_request_id' => $request->id, 'reason' => 'Phoned him.'])
        ->and(json_decode(lastEntry('admin_action', 'reenrolment_expired')->detail, true))->toHaveKey('reenrolment_request_id')
        ->and(DB::table('audit_entries')->where('event', 'sign_in_failed')->whereNull('subject_id')->count())->toBe(1);

    // No entry holds a password, secret, setup key or link, code, recovery code or readable email (FR-082).
    $everything = json_encode(DB::table('audit_entries')->get());
    foreach ($secrets as $secretValue) {
        expect($everything)->not->toContain($secretValue);
    }
    expect($everything)->not->toContain('@example.test');
});

it('writes nothing when a session ends by idle or absolute limit', function () {
    ['operator' => $operator] = makeOperator();
    signInOperator($operator);
    $before = DB::table('audit_entries')->count();

    $this->travel(31)->minutes();
    $this->get('/admin')->assertRedirect(route('admin.sign-in'));

    expect(DB::table('audit_entries')->count())->toBe($before);
});
