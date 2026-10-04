<?php

/*
| How an operator session ends (spec 018, US6; FR-040 to FR-043, FR-083).
*/

use App\Modules\Admin\Application\Operators\DeactivateOperator;
use App\Modules\Admin\Application\Operators\ResetOperatorPassword;
use App\Modules\Admin\Application\Reenrolment\ApproveReenrolment;
use App\Modules\Admin\Domain\AuditReason;
use App\Modules\Admin\Domain\Operators\SignInStage;
use App\Modules\Admin\Http\Auth\OperatorSession;
use App\Modules\Admin\Http\Livewire\OperatorNotificationBell;
use App\Modules\Admin\Infrastructure\Models\OperatorReenrolmentRequest;
use Illuminate\Support\Facades\DB;

it('signs out at once, writes one entry and leaves a replayed session reaching nothing', function () {
    ['operator' => $operator] = makeOperator();
    signInOperator($operator);
    $this->get('/admin')->assertOk();

    $this->post(route('admin.sign-out'))->assertRedirect(route('admin.sign-in'));

    $entries = DB::table('audit_entries')->where('event', 'session_revoked')->get();
    expect($entries)->toHaveCount(1)
        ->and($entries[0]->actor_id)->toBe($operator->id)
        ->and(json_decode($entries[0]->detail, true))->toBe(['reason' => 'signed_out'])
        ->and(app(OperatorSession::class)->qualifies())->toBe('signed_out');

    $this->get(route('admin.sign-in'))->assertSee("You've been signed out.");
    $this->get('/admin')->assertRedirect(route('admin.sign-in'));
});

it('keeps the language choice across sign-out', function () {
    ['operator' => $operator] = makeOperator();
    signInOperator($operator);
    $this->withSession(['admin.locale' => 'ar']);

    $this->post(route('admin.sign-out'));

    expect(session('admin.locale'))->toBe('ar');
});

it('signs out after 31 minutes without a request, with the idle message and no entry', function () {
    ['operator' => $operator] = makeOperator();
    signInOperator($operator);
    $before = DB::table('audit_entries')->count();

    $this->travel(31)->minutes();

    $this->get('/admin')->assertRedirect(route('admin.sign-in'));
    $this->get(route('admin.sign-in'))->assertSee('You were signed out after 30 minutes without activity.');
    expect(DB::table('audit_entries')->count())->toBe($before);
});

it('keeps a session that is used within 30 minutes of each request', function () {
    ['operator' => $operator] = makeOperator();
    signInOperator($operator);

    foreach (range(1, 4) as $ignored) {
        $this->travel(25)->minutes();
        $this->get('/admin')->assertOk();
    }
});

it('ends a session at 12 hours however active it is, with the limit message and no entry', function () {
    ['operator' => $operator] = makeOperator();
    signInOperator($operator);
    $before = DB::table('audit_entries')->count();

    foreach (range(1, 30) as $ignored) {
        $this->travel(29)->minutes();
        $response = $this->get('/admin');

        if ($response->status() !== 200) {
            break;
        }
    }

    $response->assertRedirect(route('admin.sign-in'));
    $this->get(route('admin.sign-in'))->assertSee('Your session reached its 12-hour limit. Sign in again.');
    expect(DB::table('audit_entries')->count())->toBe($before);
});

it('signs an existing session out on its next request after deactivation', function () {
    ['operator' => $operator, 'email' => $email] = makeOperator();
    makeOperator();
    signInOperator($operator);
    $this->get('/admin')->assertOk();

    app(DeactivateOperator::class)->handle($email);

    $this->get('/admin')->assertRedirect(route('admin.sign-in'));
});

it('signs an existing session out on its next request after a password reset', function () {
    ['operator' => $operator, 'email' => $email] = makeOperator();
    signInOperator($operator);
    $this->get('/admin')->assertOk();

    app(ResetOperatorPassword::class)->handle($email);

    $this->get('/admin')->assertRedirect(route('admin.sign-in'));
});

it('signs the requester out after a completed re-enrolment', function () {
    ['operator' => $requester] = makeOperator();
    $first = makeOperator()['operator'];
    $second = makeOperator()['operator'];
    $request = OperatorReenrolmentRequest::query()->create(['operator_id' => $requester->id, 'state' => 'open', 'opened_at' => now(), 'expires_at' => now()->addHours(72)]);
    signInOperator($requester);
    $this->get('/admin')->assertOk();

    app(ApproveReenrolment::class)->handle($first->id, $request->id, new AuditReason('Checked.'));
    app(ApproveReenrolment::class)->handle($second->id, $request->id, new AuditReason('Checked.'));

    $this->get('/admin')->assertRedirect(route('admin.sign-in'));
});

it('refuses a component update from a session that the account has outgrown', function () {
    ['operator' => $operator, 'email' => $email] = makeOperator();
    makeOperator();
    signInOperator($operator);
    $snapshot = livewireSnapshot($this->get('/admin')->getContent(), OperatorNotificationBell::class);

    app(DeactivateOperator::class)->handle($email);

    livewireUpdate($snapshot)->assertStatus(401);
});

it('does not end a half-finished sign-in when a guarded page is requested', function () {
    ['operator' => $operator] = makeOperator();
    signInOperatorPending($operator, SignInStage::SecondFactor);

    $this->get('/admin')->assertRedirect(route('admin.sign-in.code'));
    $this->get(route('admin.sign-in.code'))->assertOk();
});

it('sends the no-store and anti-framing headers on every back-office response', function () {
    ['operator' => $operator] = makeOperator();
    signInOperator($operator);

    foreach (['/admin', '/admin/security', '/admin/reenrolment-requests', '/admin/sign-in'] as $path) {
        $response = $this->get($path);
        expect($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->headers->get('X-Frame-Options'))->toBe('DENY');
    }
});
