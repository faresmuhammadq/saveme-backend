<?php

use App\Modules\Admin\Http\Livewire\AuditLog;
use App\Modules\Admin\Http\Middleware\ResolveAdminLocale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * The audit log screen: every query is one recorded read, expanding is not (spec 021, US3; FR-041 to FR-046).
 */

beforeEach(function () {
    $this->freezeTime();
    ['operator' => $this->operator] = makeOperator('enrolled', ['name' => 'Nadia Operator']);
    signInOperator($this->operator);
});

function auditReads(): array
{
    return DB::table('audit_entries')->where('event', 'audit_read')->orderBy('occurred_at')->orderBy('id')->get()
        ->map(fn (object $row): array => ['actor' => $row->actor_id, 'subject' => $row->subject_id, 'subject_type' => $row->subject_type, 'detail' => json_decode($row->detail, true)])
        ->all();
}

function logEntry(array $attributes = []): string
{
    $id = (string) Str::uuid7();
    DB::table('audit_entries')->insert(array_merge([
        'occurred_at' => now()->subHour(),
        'event' => 'session_issued',
        'actor_type' => 'user',
        'actor_id' => (string) Str::uuid7(),
        'subject_type' => 'session',
        'subject_id' => (string) Str::uuid7(),
        'request_id' => null,
        'origin' => '203.0.113.0/24',
        'detail' => '{}',
    ], $attributes, ['id' => $id]));

    return $id;
}

it('records one audit_read with the default filters on first load and lists newest first', function () {
    $older = logEntry(['occurred_at' => now()->subDays(2)]);
    $newer = logEntry(['occurred_at' => now()->subHours(2)]);
    logEntry(['occurred_at' => now()->subDays(20)]);

    $component = Livewire::test(AuditLog::class)
        ->assertSeeHtml('data-test="audit-read-note"')
        ->assertSee('recorded as audit_read');

    $rows = $component->get('rows');

    // The read of this very load is recorded first, so it heads the list.
    expect(array_column($rows, 'event'))->toBe(['audit_read', 'session_issued', 'session_issued'])
        ->and(array_slice(array_column($rows, 'id'), 1))->toBe([$newer, $older])
        ->and(auditReads())->toHaveCount(1)
        ->and(auditReads()[0]['actor'])->toBe($this->operator->id)
        ->and(auditReads()[0]['subject'])->toBe($this->operator->id)
        ->and(auditReads()[0]['detail'])->toEqual(['page' => 'first', 'from' => '2026-09-29', 'to' => '2026-10-05']);
});

it('records one audit_read per apply with the filters', function () {
    $subject = (string) Str::uuid7();
    $match = logEntry(['subject_type' => 'user', 'subject_id' => $subject]);
    logEntry();

    $component = Livewire::test(AuditLog::class)
        ->set('subjectType', 'user')
        ->set('subjectId', $subject)
        ->call('apply');

    expect(array_column($component->get('rows'), 'id'))->toBe([$match])
        ->and(auditReads())->toHaveCount(2)
        ->and(auditReads()[1]['detail'])->toMatchArray(['page' => 'first', 'subject_type' => 'user', 'subject_id' => $subject]);
});

it('records one audit_read for each older and newer and walks without repeats', function () {
    for ($i = 0; $i < 60; $i++) {
        logEntry(['occurred_at' => now()->subMinutes($i + 1)]);
    }

    $component = Livewire::test(AuditLog::class);
    $page1 = array_column($component->get('rows'), 'id');
    $component->assertSeeHtml('data-test="page-older"');

    $component->call('older');
    $page2 = array_column($component->get('rows'), 'id');

    $component->call('newer');
    $back = array_column($component->get('rows'), 'id');

    $reads = array_column(auditReads(), 'detail');

    expect($page1)->toHaveCount(50)
        ->and($page2)->toHaveCount(11)
        ->and(array_intersect($page1, $page2))->toBe([])
        ->and($back)->toHaveCount(50)
        ->and(array_column($reads, 'page'))->toBe(['first', 'older', 'newer']);
});

it('shows field errors, runs no query and writes nothing for invalid filters', function () {
    $component = Livewire::test(AuditLog::class)->set('from', '2024-01-01')->set('to', '2026-10-05');
    $before = count(auditReads());

    $component->call('apply')->assertHasErrors(['from'])->assertSee('Choose a range of at most 400 days.');
    $component->set('from', '2026-10-05')->set('to', '2026-10-01')->call('apply')->assertHasErrors(['to'])->assertSee('The end date must not be before the start.');
    $component->set('from', '2026-10-01')->set('to', '2026-10-05')->set('subjectId', 'abc')->call('apply')->assertHasErrors(['subjectId'])->assertSee('Enter a full ID.');

    expect(auditReads())->toHaveCount($before)
        ->and($component->get('rows'))->toBe([]);
});

it('writes nothing when a row is expanded or the component re-renders', function () {
    logEntry(['detail' => json_encode(['via' => 'password']), 'request_id' => (string) Str::uuid7()]);
    $component = Livewire::test(AuditLog::class);
    $before = count(auditReads());

    $component->assertSeeHtml('data-test="audit-toggle"')->assertSeeHtml('data-test="audit-detail"')->assertSee('password')->call('$refresh');

    expect(auditReads())->toHaveCount($before);
});

it('names operators, shows user ids and System', function () {
    $userId = (string) Str::uuid7();
    logEntry(['event' => 'admin_action', 'actor_type' => 'operator', 'actor_id' => $this->operator->id, 'subject_type' => 'operator', 'subject_id' => $this->operator->id]);
    logEntry(['actor_type' => 'user', 'actor_id' => $userId]);
    logEntry(['event' => 'deletion_completed', 'actor_type' => 'system', 'actor_id' => null]);

    $rows = Livewire::test(AuditLog::class)->assertSee('Nadia Operator')->assertSee($userId)->assertSee('System')->get('rows');

    expect(collect($rows)->firstWhere('actor_type', 'operator')['actor_name'])->toBe('Nadia Operator');
});

it('links user, claim and plan subjects and nothing else', function () {
    $user = (string) Str::uuid7();
    $claim = (string) Str::uuid7();
    $plan = (string) Str::uuid7();
    logEntry(['subject_type' => 'user', 'subject_id' => $user]);
    logEntry(['subject_type' => 'payment_claim', 'subject_id' => $claim]);
    logEntry(['subject_type' => 'plan', 'subject_id' => $plan]);
    logEntry(['subject_type' => 'wallet', 'subject_id' => (string) Str::uuid7()]);

    $component = Livewire::test(AuditLog::class)
        ->assertSeeHtml(route('admin.accounts.show', ['user' => $user]))
        ->assertSeeHtml(route('admin.claims.show', ['claim' => $claim]))
        ->assertSeeHtml(route('admin.plans.edit', ['plan' => $plan]));

    expect(collect($component->get('rows'))->where('subject_url', '')->count())->toBe(1 + 1);
});

it('prefills filters from the query string and records them', function () {
    $subject = (string) Str::uuid7();
    $match = logEntry(['subject_type' => 'user', 'subject_id' => $subject, 'event' => 'admin_action']);
    logEntry();

    $component = Livewire::withQueryParams(['subject_type' => 'user', 'subject_id' => $subject])->test(AuditLog::class);

    expect(array_column($component->get('rows'), 'id'))->toBe([$match])
        ->and($component->get('subjectType'))->toBe('user')
        ->and(auditReads()[0]['detail'])->toMatchArray(['subject_type' => 'user', 'subject_id' => $subject]);
});

it('has no edit, delete or export action', function () {
    $methods = array_map(fn (ReflectionMethod $method): string => strtolower($method->getName()), (new ReflectionClass(AuditLog::class))->getMethods(ReflectionMethod::IS_PUBLIC));

    expect(preg_grep('/export|delete|edit|update|destroy|download/', $methods))->toBe([]);
});

it('renders in Arabic right to left with Western digits', function () {
    logEntry();

    $html = $this->withSession([ResolveAdminLocale::SESSION_KEY => 'ar'])->get(route('admin.audit'))->assertOk()->getContent();

    expect($html)->toContain('dir="rtl"')
        ->toContain('سجل التدقيق')
        ->toContain('audit_read')
        ->toContain('data-test="audit-apply"')
        ->and($html)->toUseWesternDigitsOnly();
});
