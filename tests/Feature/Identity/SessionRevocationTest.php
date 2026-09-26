<?php

use App\Modules\Identity\Domain\Events\DeviceSessionEnded;
use App\Modules\Identity\Infrastructure\Models\AuditEntry;
use App\Modules\Identity\Infrastructure\Models\DeviceSession;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

/*
 * Ending a session works on the very next request and leaves every other
 * session working (security baseline A2, A3; spec 007, FR-024, FR-026).
 */

beforeEach(function () {
    person();
    $this->a = signInAs(device: deviceBody(['name' => 'A']))->json('data');
    $this->b = signInAs(device: deviceBody(['name' => 'B']))->json('data');
    $this->c = signInAs(device: deviceBody(['name' => 'C']))->json('data');
});

function sessionsCall(string $credential): TestResponse
{
    $response = test()->withHeaders(asCredential($credential))->get('/api/v1/auth/sessions');
    test()->flushHeaders();

    return $response;
}

test('an ended session fails on its very next request; the others keep working', function () {
    sessionsCall($this->b['credential'])->assertOk();

    $this->withHeaders(asCredential($this->a['credential']))->deleteJson('/api/v1/auth/sessions/'.$this->b['session']['id'])->assertNoContent();
    $this->flushHeaders();

    sessionsCall($this->b['credential'])->assertStatus(401);
    sessionsCall($this->a['credential'])->assertOk();
    sessionsCall($this->c['credential'])->assertOk();
});

test('ending all others leaves only the current session', function () {
    $this->withHeaders(asCredential($this->a['credential']))->deleteJson('/api/v1/auth/sessions')->assertNoContent();

    expect(DeviceSession::query()->where('state', 'active')->pluck('id')->all())->toBe([$this->a['session']['id']]);
});

test('signing out ends only the current session', function () {
    $this->withHeaders(asCredential($this->a['credential']))->postJson('/api/v1/auth/logout')->assertNoContent();
    $this->flushHeaders();

    sessionsCall($this->a['credential'])->assertStatus(401);
    sessionsCall($this->b['credential'])->assertOk();
    expect(DeviceSession::query()->find($this->a['session']['id'])->end_reason)->toBe('signed_out');
});

test('an ended row survives with its time and reason, its version bumped', function () {
    $before = DeviceSession::query()->find($this->b['session']['id']);

    $this->withHeaders(asCredential($this->a['credential']))->deleteJson('/api/v1/auth/sessions/'.$this->b['session']['id']);

    $after = DeviceSession::query()->find($this->b['session']['id']);
    expect($after->state)->toBe('revoked')
        ->and($after->end_reason)->toBe('ended_by_owner')
        ->and($after->ended_at)->not->toBeNull()
        ->and($after->version)->toBe($before->version + 1)
        ->and($after->change_seq)->toBeGreaterThan($before->change_seq);
});

test('each end is audited once and announced once, after commit', function () {
    Event::fake([DeviceSessionEnded::class]);

    $this->withHeaders(asCredential($this->a['credential']))->deleteJson('/api/v1/auth/sessions');

    Event::assertDispatchedTimes(DeviceSessionEnded::class, 2);
    expect(AuditEntry::query()->where('event', 'session_revoked')->whereIn('subject_id', [$this->b['session']['id'], $this->c['session']['id']])->count())->toBe(2);
});
