<?php

use App\Modules\Admin\Application\Audit\AdminAuditService;
use App\Modules\Admin\Domain\Operators\AdminAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| The audit entries of spec 021, exactly as contracts/audit.md (FR-024,
| FR-025, FR-038, FR-043).
*/

function onlyEntry(): stdClass
{
    expect(DB::table('audit_entries')->count())->toBe(1);

    return DB::table('audit_entries')->first();
}

it('writes account_viewed about the user', function () {
    $operator = (string) Str::uuid();
    $user = (string) Str::uuid();

    app(AdminAuditService::class)->accountViewed($operator, $user);

    $entry = onlyEntry();
    expect($entry)->toMatchObject(['event' => 'admin_action', 'actor_type' => 'operator', 'actor_id' => $operator, 'subject_type' => 'user', 'subject_id' => $user])
        ->and(json_decode($entry->detail, true))->toEqual(['action' => 'account_viewed']);
});

it('writes account_lookup_missed about the operator with how the term was typed, never the term', function () {
    $operator = (string) Str::uuid();

    app(AdminAuditService::class)->accountLookupMissed($operator, 'address');

    $entry = onlyEntry();
    expect($entry)->toMatchObject(['event' => 'admin_action', 'subject_type' => 'operator', 'subject_id' => $operator])
        ->and(json_decode($entry->detail, true))->toEqual(['action' => 'account_lookup_missed', 'by' => 'address'])
        ->and($entry->detail)->not->toContain('@');
});

it('writes plan_updated with sorted field names and the reason only', function () {
    $operator = (string) Str::uuid();
    $plan = (string) Str::uuid();

    app(AdminAuditService::class)->planChanged($operator, $plan, AdminAction::PlanUpdated, ['sort_order', 'prices.instapay', 'name.ar'], 'Price review');

    $entry = onlyEntry();
    expect($entry)->toMatchObject(['event' => 'admin_action', 'actor_type' => 'operator', 'subject_type' => 'plan', 'subject_id' => $plan])
        ->and(json_decode($entry->detail, true))->toEqual(['action' => 'plan_updated', 'fields' => 'name.ar,prices.instapay,sort_order', 'reason' => 'Price review'])
        ->and($entry->detail)->not->toMatch('/\d{7,}/');
});

it('writes plan_retired with the reason and no fields', function () {
    app(AdminAuditService::class)->planChanged((string) Str::uuid(), (string) Str::uuid(), AdminAction::PlanRetired, ['name.en'], 'No longer sold');

    expect(json_decode(onlyEntry()->detail, true))->toEqual(['action' => 'plan_retired', 'reason' => 'No longer sold']);
});

it('writes audit_read about the reading operator with the page and filters, dropping anything else', function () {
    $operator = (string) Str::uuid();

    app(AdminAuditService::class)->auditRead($operator, ['page' => 'first', 'from' => '2026-10-01', 'to' => '2026-10-08', 'event' => 'sign_in_failed', 'email' => 'a@b.test']);

    $entry = onlyEntry();
    expect($entry)->toMatchObject(['event' => 'audit_read', 'actor_id' => $operator, 'subject_type' => 'operator', 'subject_id' => $operator])
        ->and(json_decode($entry->detail, true))->toEqual(['page' => 'first', 'from' => '2026-10-01', 'to' => '2026-10-08', 'event' => 'sign_in_failed']);
});

it('accepts subject type plan in the database', function () {
    app(AdminAuditService::class)->planChanged((string) Str::uuid(), (string) Str::uuid(), AdminAction::PlanRetired, [], 'x');

    expect(DB::table('audit_entries')->where('subject_type', 'plan')->count())->toBe(1);
});
