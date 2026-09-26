<?php

use App\Modules\Identity\Application\Audit\AuditLog;
use App\Modules\Identity\Application\Audit\AuditRecord;
use App\Modules\Identity\Application\Audit\Details\DeletionCancelledDetail;
use App\Modules\Identity\Application\Audit\Details\LockoutDetail;
use App\Modules\Identity\Application\Audit\Details\PasswordChangedDetail;
use App\Modules\Identity\Application\Audit\Details\SessionDetail;
use App\Modules\Identity\Application\Audit\Details\SessionRevokedDetail;
use App\Modules\Identity\Application\Audit\Details\SignInFailedDetail;
use App\Modules\Identity\Domain\Audit\AuditEvent;
use App\Modules\Identity\Domain\Audit\SubjectType;
use App\Modules\Identity\Domain\Sessions\EndReason;
use App\Modules\Identity\Infrastructure\Models\AuditEntry;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * The single audit log (security baseline, The audit entry; Operational rules).
 */

test('recording writes exactly one entry with actor, subject, request and a coarse origin', function () {
    $requestId = (string) Str::uuid7();
    Context::add('request_id', $requestId);
    app()->instance('request', Request::create('/', server: ['REMOTE_ADDR' => '203.0.113.77']));
    $userId = (string) Str::uuid7();

    app(AuditLog::class)->record(AuditRecord::byUser(AuditEvent::PasswordChanged, $userId, SubjectType::User, $userId, new PasswordChangedDetail('change')));

    $entry = AuditEntry::query()->sole();
    expect($entry->event)->toBe('password_changed')
        ->and($entry->actor_type)->toBe('user')
        ->and($entry->actor_id)->toBe($userId)
        ->and($entry->subject_type)->toBe('user')
        ->and($entry->subject_id)->toBe($userId)
        ->and($entry->request_id)->toBe($requestId)
        ->and($entry->origin)->toBe('203.0.113.0/24')
        ->and($entry->detail)->toBe(['via' => 'change']);
});

test('IPv6 origins are coarsened to a /48', function () {
    expect(AuditLog::coarsen('2001:db8:abcd:12::1'))->toBe('2001:db8:abcd::/48');
});

test('an entry can never be edited', function () {
    app(AuditLog::class)->record(AuditRecord::byUser(AuditEvent::AddressVerified, (string) Str::uuid7(), SubjectType::User, null));

    expect(fn () => DB::update("update audit_entries set event = 'audit_read'"))->toThrow(QueryException::class, 'append-only');
});

test('an entry is deleted only after its 400-day retention', function () {
    app(AuditLog::class)->record(AuditRecord::byUser(AuditEvent::AddressVerified, (string) Str::uuid7(), SubjectType::User, null));
    DB::table('audit_entries')->insert([
        'id' => (string) Str::uuid7(), 'occurred_at' => now()->subDays(401), 'event' => 'address_verified',
        'actor_type' => 'system', 'subject_type' => 'user', 'detail' => '{}',
    ]);

    expect(DB::delete("delete from audit_entries where occurred_at < now() - interval '400 days'"))->toBe(1)
        ->and(fn () => DB::delete('delete from audit_entries'))->toThrow(QueryException::class, 'append-only');
});

test('no detail class can carry a value from the exclusion list', function () {
    $details = [
        new SignInFailedDetail, SignInFailedDetail::forUnknownAddress(str_repeat('ab', 32)), new LockoutDetail(15, 'abababab'),
        new SessionDetail('android'), new SessionRevokedDetail(EndReason::Replaced),
        new PasswordChangedDetail('reset'), new DeletionCancelledDetail('sign_in'),
    ];

    foreach ($details as $detail) {
        $json = json_encode($detail->toArray());
        expect($json)->not->toContain('@')
            ->and(array_keys($detail->toArray()))->each->not->toMatch('/pass|token|secret|email|address|amount|note/');
    }

    expect(SignInFailedDetail::forUnknownAddress(str_repeat('ab', 32))->toArray())->toBe(['lookup_prefix' => 'abababab']);
});
