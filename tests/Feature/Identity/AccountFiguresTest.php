<?php

use App\Modules\Identity\Application\Figures\AccountFigures;
use App\Modules\Identity\Infrastructure\Models\User;

/*
 * Population counts of accounts for the overview (spec 021, FR-010; R-11).
 */

beforeEach(function () {
    $this->freezeTime();
});

test('totals exclude purged accounts and split verified and deletion pending', function () {
    User::factory()->count(3)->create();
    User::factory()->count(2)->verified()->create();
    User::factory()->pendingDeletion()->create();
    User::factory()->create(['state' => 'purged', 'purged_at' => now()]);

    $figures = app(AccountFigures::class)->compute(now()->toDateTimeImmutable());

    expect($figures->total)->toBe(6)
        ->and($figures->verified)->toBe(2)
        ->and($figures->pendingDeletion)->toBe(1);
});

test('new accounts are counted over the last 7 and 30 days, purged ones never', function () {
    User::factory()->create(['created_at' => now()->subDays(2)]);
    User::factory()->create(['created_at' => now()->subDays(8)]);
    User::factory()->create(['created_at' => now()->subDays(29)]);
    User::factory()->create(['created_at' => now()->subDays(31)]);
    User::factory()->create(['created_at' => now()->subDay(), 'state' => 'purged', 'purged_at' => now()]);

    $figures = app(AccountFigures::class)->compute(now()->toDateTimeImmutable());

    expect($figures->newInSevenDays)->toBe(1)
        ->and($figures->newInThirtyDays)->toBe(3);
});
