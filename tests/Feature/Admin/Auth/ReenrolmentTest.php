<?php

/*
| Two other operators approve a re-enrolment (spec 018, US4; FR-030 to FR-034).
*/

use App\Modules\Admin\Application\Reenrolment\ApproveReenrolment;
use App\Modules\Admin\Domain\AuditReason;
use App\Modules\Admin\Domain\Operators\Refusals\ReenrolmentRefused;
use App\Modules\Admin\Domain\Operators\SignInStage;
use App\Modules\Admin\Domain\SecondFactor\Base32;
use App\Modules\Admin\Http\Auth\OperatorSession;
use App\Modules\Admin\Http\Auth\PendingSignIn;
use App\Modules\Admin\Http\Livewire\ReenrolmentRequests;
use App\Modules\Admin\Http\Navigation\NavigationRegistry;
use App\Modules\Admin\Infrastructure\Models\OperatorReenrolmentRequest;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/** @return array{requester: array<string, mixed>, first: array<string, mixed>, second: array<string, mixed>} */
function threeOperators(): array
{
    return ['requester' => makeOperator('enrolled', ['name' => 'Nour Hassan']), 'first' => makeOperator('enrolled', ['name' => 'Karim Adel']), 'second' => makeOperator('enrolled', ['name' => 'Mariam Said'])];
}

function openRequest(array $requester): OperatorReenrolmentRequest
{
    signInOperatorPending($requester['operator'], SignInStage::SecondFactor);
    test()->post(route('admin.sign-in.lost-device.submit'))->assertOk();

    return OperatorReenrolmentRequest::query()->where('operator_id', $requester['operator']->id)->latest('opened_at')->firstOrFail();
}

function approvalActions(): array
{
    return DB::table('audit_entries')->where('event', 'admin_action')->orderBy('occurred_at')->get()
        ->map(fn (stdClass $entry): string => json_decode($entry->detail, true)['action'])->all();
}

function approveAs(array $approver, OperatorReenrolmentRequest $request, string $reason = 'Verified by phone call.'): bool
{
    return app(ApproveReenrolment::class)->handle($approver['operator']->id, $request->id, new AuditReason($reason));
}

it('opens a request only from the second-factor step, and ends the half-finished sign-in', function () {
    ['requester' => $requester] = threeOperators();

    $this->post(route('admin.sign-in.lost-device.submit'))->assertRedirect(route('admin.sign-in'));

    signInOperatorPending($requester['operator'], SignInStage::PasswordChange);
    $this->post(route('admin.sign-in.lost-device.submit'))->assertRedirect(route('admin.password.new'));
    expect(OperatorReenrolmentRequest::query()->count())->toBe(0);

    signInOperatorPending($requester['operator'], SignInStage::SecondFactor);
    $this->get(route('admin.sign-in.lost-device'))->assertOk()->assertSee('Lost your device?')->assertSee('Ask for re-enrolment');
    $this->post(route('admin.sign-in.lost-device.submit'))
        ->assertOk()
        ->assertSee('Request sent. Two other operators must approve it before');

    $request = OperatorReenrolmentRequest::query()->sole();
    expect($request->state)->toBe('open')
        ->and($request->expires_at->equalTo($request->opened_at->addHours(72)))->toBeTrue()
        ->and(app(PendingSignIn::class)->exists())->toBeFalse()
        ->and(approvalActions())->toBe(['reenrolment_requested']);
});

it('does not open a second request while one is open, and says when it expires', function () {
    ['requester' => $requester] = threeOperators();
    openRequest($requester);

    signInOperatorPending($requester['operator'], SignInStage::SecondFactor);
    $this->post(route('admin.sign-in.lost-device.submit'))->assertOk()->assertSee('A request is already waiting. It expires');

    expect(OperatorReenrolmentRequest::query()->where('state', 'open')->count())->toBe(1)
        ->and(approvalActions())->toBe(['reenrolment_requested']);
});

it('warns when too few other operators could approve, and still opens the request', function () {
    ['requester' => $requester] = [...threeOperators()];
    // Only two enrolled operators besides the requester exist; remove one.
    OperatorReenrolmentRequest::query()->delete();
    $other = makeOperator('enrolled');
    DB::table('operator_accounts')->whereNotIn('id', [$requester['operator']->id, $other['operator']->id])->update(['state' => 'deactivated', 'secret_sealed' => null, 'enrolled_at' => null]);

    signInOperatorPending($requester['operator'], SignInStage::SecondFactor);
    $this->post(route('admin.sign-in.lost-device.submit'))->assertOk()->assertSee('Fewer than two other operators can approve right now; the request will wait.');

    expect(OperatorReenrolmentRequest::query()->where('state', 'open')->count())->toBe(1);
});

it('throttles opening requests to five in 15 minutes', function () {
    ['requester' => $requester] = threeOperators();

    foreach (range(1, 5) as $ignored) {
        signInOperatorPending($requester['operator'], SignInStage::SecondFactor);
        $this->post(route('admin.sign-in.lost-device.submit'))->assertOk();
    }

    signInOperatorPending($requester['operator'], SignInStage::SecondFactor);
    $this->post(route('admin.sign-in.lost-device.submit'))->assertRedirect(route('admin.sign-in.lost-device'));
    $this->get(route('admin.sign-in.lost-device'))->assertSee('Too many attempts. Try again in');
});

it('lists open requests with name, instants and approvals only', function () {
    ['requester' => $requester, 'first' => $first] = threeOperators();
    $request = openRequest($requester);
    approveAs($first, $request);
    signInOperator(makeOperator()['operator']);

    $this->get('/admin/reenrolment-requests')
        ->assertOk()
        ->assertSee('Nour Hassan')
        ->assertSee('1/2')
        ->assertSee('data-test="request-approve"', false)
        ->assertDontSee('secret');
});

it('asks for a fresh code, then a reason, before an approval is recorded', function () {
    ['requester' => $requester, 'first' => $first] = threeOperators();
    $request = openRequest($requester);
    signInOperator($first['operator']);
    session()->put(OperatorSession::FACTOR_CONFIRMED_AT, now()->subMinutes(11)->getTimestamp());

    $component = Livewire::test(ReenrolmentRequests::class)
        ->call('approve', $request->id)
        ->assertSet('factorOpen', true)
        ->assertSet('reasonOpen', false);

    $this->travel(30)->seconds();
    $component->call('confirmFactor', currentCode($first['secret'], 1))
        ->assertSet('factorOpen', false)
        ->assertSet('reasonOpen', true)
        ->call('confirmReason', '   ')
        ->assertHasErrors('reason')
        ->call('confirmReason', 'Called her on her office line.')
        ->assertDispatched('admin-toast', tone: 'success', message: 'Approval recorded. One more operator must approve.');

    $entry = DB::table('audit_entries')->where('event', 'admin_action')->orderByDesc('occurred_at')->first();
    expect(json_decode($entry->detail, true))->toEqual(['action' => 'reenrolment_approved', 'reenrolment_request_id' => $request->id, 'reason' => 'Called her on her office line.'])
        ->and($entry->actor_id)->toBe($first['operator']->id)
        ->and($entry->subject_id)->toBe($requester['operator']->id);
});

it('offers no approve action on the requester\'s own request or after approving, and refuses forged calls', function () {
    ['requester' => $requester, 'first' => $first] = threeOperators();
    $request = openRequest($requester);
    approveAs($first, $request);

    signInOperator($first['operator']);
    Livewire::test(ReenrolmentRequests::class)->assertDontSeeHtml('data-test="request-approve"');

    // The requester has another signed-in session.
    signInOperator($requester['operator']);
    Livewire::test(ReenrolmentRequests::class)
        ->assertDontSeeHtml('data-test="request-approve"')
        ->call('approve', $request->id)
        ->call('confirmReason', 'Forged.')
        ->assertDispatched('admin-toast', tone: 'error', message: "You can't approve your own request.");

    signInOperator($first['operator']);
    Livewire::test(ReenrolmentRequests::class)
        ->call('approve', $request->id)
        ->call('confirmReason', 'Forged twice.')
        ->assertDispatched('admin-toast', tone: 'error', message: "You've already approved this request.");

    expect(DB::table('operator_reenrolment_approvals')->count())->toBe(1);
});

it('completes on the second distinct approval only, exactly once, and clears the factor at once', function () {
    ['requester' => $requester, 'first' => $first, 'second' => $second] = threeOperators();
    $operator = $requester['operator'];
    giveRecoveryCodes($operator);
    $operator->forceFill(['last_code_step' => 5])->save();
    $request = openRequest($requester);
    signInOperator($operator);
    $epoch = $operator->refresh()->credential_epoch;

    expect(approveAs($first, $request))->toBeFalse()
        ->and($operator->refresh()->state)->toBe('active');

    expect(approveAs($second, $request))->toBeTrue();
    $operator->refresh();

    expect($operator->state)->toBe('awaiting_enrolment')
        ->and($operator->secret_sealed)->toBeNull()
        ->and($operator->enrolled_at)->toBeNull()
        ->and($operator->last_code_step)->toBeNull()
        ->and($operator->credential_epoch)->toBe($epoch + 1)
        ->and(DB::table('operator_recovery_codes')->where('operator_id', $operator->id)->count())->toBe(0)
        ->and($request->refresh()->state)->toBe('completed')
        ->and($request->closed_by)->toBe($second['operator']->id)
        ->and(app(OperatorSession::class)->qualifies())->not->toBeNull();

    $third = makeOperator();
    expect(fn () => approveAs($third, $request))->toThrow(ReenrolmentRefused::class);

    expect(approvalActions())->toBe(['reenrolment_requested', 'reenrolment_approved', 'reenrolment_approved', 'reenrolment_completed']);
    $completed = DB::table('audit_entries')->where('detail', 'like', '%reenrolment_completed%')->sole();
    expect($completed->actor_type)->toBe('system')->and($completed->subject_id)->toBe($operator->id);
});

it('sends the requester to enrolment at the next password sign-in, with new recovery codes', function () {
    ['requester' => $requester, 'first' => $first, 'second' => $second] = threeOperators();
    $request = openRequest($requester);
    approveAs($first, $request);
    approveAs($second, $request);
    app(OperatorSession::class)->end();

    $this->post(route('admin.sign-in.submit'), ['email' => $requester['email'], 'password' => $requester['password']])->assertRedirect(route('admin.enrol'));
    preg_match('/data-test="enrol-key"[^>]*>([^<]+)</', $this->get(route('admin.enrol'))->getContent(), $key);
    $secret = Base32::decode(str_replace(' ', '', trim($key[1])));

    $response = $this->post(route('admin.enrol.submit'), ['one_time_code' => currentCode((string) $secret)])->assertOk();
    preg_match('/data-test="recovery-code-list".*?<\/ol>/s', $response->getContent(), $list);

    expect(preg_match_all('/\b[0-9A-Z]{5}-[0-9A-Z]{5}\b/', $list[0]))->toBe(10);
});

it('lets another operator decline with a reason, after which the requester may ask again', function () {
    ['requester' => $requester, 'first' => $first] = threeOperators();
    $request = openRequest($requester);
    signInOperator($first['operator']);

    Livewire::test(ReenrolmentRequests::class)
        ->call('decline', $request->id)
        ->call('confirmReason', 'Not verified yet.')
        ->assertDispatched('admin-toast', tone: 'success', message: 'Request declined.');

    expect($request->refresh()->state)->toBe('declined')
        ->and($request->closed_by)->toBe($first['operator']->id)
        ->and(approvalActions())->toBe(['reenrolment_requested', 'reenrolment_declined']);

    openRequest($requester);
    expect(OperatorReenrolmentRequest::query()->where('state', 'open')->count())->toBe(1);
});

it('expires requests past 72 hours by command, and refuses an approval either way', function () {
    ['requester' => $requester, 'first' => $first] = threeOperators();
    $request = openRequest($requester);
    $this->travel(73)->hours();

    expect(fn () => approveAs($first, $request))->toThrow(ReenrolmentRefused::class, 'expired');

    Artisan::call('admin:expire-reenrolment-requests');

    expect($request->refresh()->state)->toBe('expired')
        ->and($request->closed_at)->not->toBeNull()
        ->and(approvalActions())->toBe(['reenrolment_requested', 'reenrolment_expired'])
        ->and(fn () => approveAs($first, $request))->toThrow(ReenrolmentRefused::class);
});

it('is scheduled every five minutes', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command, 'admin:expire-reenrolment-requests'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('*/5 * * * *');
});

function reenrolmentNavigationBadge(): ?int
{
    $entries = collect(app(NavigationRegistry::class)->groups())->flatMap(fn (array $group): array => $group['entries']);

    return $entries->firstWhere(fn ($item) => $item['entry']->key === 'reenrolment-requests')['badge'];
}

it('counts waiting requests in the navigation badge, never the viewer\'s own', function () {
    ['requester' => $requester, 'first' => $first] = threeOperators();
    openRequest($requester);

    signInOperator($first['operator']);
    $this->get('/admin')->assertOk()->assertSee('Re-enrolment requests');
    expect(reenrolmentNavigationBadge())->toBe(1);

    signInOperator($requester['operator']);
    expect(reenrolmentNavigationBadge())->toBeNull();
});
