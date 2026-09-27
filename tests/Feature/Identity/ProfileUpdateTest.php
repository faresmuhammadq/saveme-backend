<?php

use App\Modules\Identity\Domain\Events\ProfileUpdated;
use App\Modules\Identity\Domain\Preferences\BudgetStartDay;
use App\Modules\Identity\Infrastructure\Models\Profile;
use Illuminate\Support\Facades\Event;

/*
 * Saving the profile (spec 008, US2, R-1): version and change_seq move on a
 * real change and stay put on a replay; a stale version applies nothing.
 */

beforeEach(function () {
    $this->user = person();
    $this->credential = signInAs()->json('data.credential');
});

test('a save changes every value, bumps version and change_seq, and announces the changed names only', function () {
    Event::fake([ProfileUpdated::class]);
    $before = Profile::query()->where('user_id', $this->user->id)->sole();
    $this->travel(1)->seconds();

    saveProfile($this->credential, profileBody(['display_name' => 'Ada', 'locale' => 'ar', 'timezone' => 'Asia/Riyadh', 'default_currency' => 'SAR', 'budget_period_start_day' => 25]))->assertOk();

    $after = $before->fresh();
    expect($after)
        ->locale->toBe('ar')
        ->timezone->toBe('Asia/Riyadh')
        ->default_currency->toBe('SAR')
        ->budget_period_start_day->toBe(25)
        ->version->toBe($before->version + 1)
        ->and($after->change_seq)->toBeGreaterThan($before->change_seq)
        ->and($after->updated_at->gt($before->updated_at))->toBeTrue();
    Event::assertDispatched(ProfileUpdated::class, function (ProfileUpdated $event): bool {
        expect($event->changedFields)->toEqualCanonicalizing(['display_name', 'locale', 'timezone', 'default_currency', 'budget_period_start_day'])
            ->and(json_encode($event))->not->toContain('Ada');

        return true;
    });
});

test('an identical replay moves nothing and announces nothing', function () {
    saveProfile($this->credential, profileBody(['display_name' => 'Ada']))->assertOk();
    $before = Profile::query()->where('user_id', $this->user->id)->sole();
    Event::fake([ProfileUpdated::class]);

    saveProfile($this->credential, profileBody(['display_name' => 'Ada'], version: 2))->assertOk();

    expect($before->fresh()->only(['version', 'change_seq', 'updated_at']))->toEqual($before->only(['version', 'change_seq', 'updated_at']));
    Event::assertNotDispatched(ProfileUpdated::class);
});

test('a stale version applies nothing', function () {
    saveProfile($this->credential, profileBody(['display_name' => 'Ada']))->assertOk();

    saveProfile($this->credential, profileBody(['display_name' => 'Grace', 'locale' => 'ar'], version: 1))->assertStatus(409);

    expect(Profile::query()->where('user_id', $this->user->id)->sole())
        ->locale->toBe('en')
        ->version->toBe(2);
});

test('display names are trimmed, cleared when empty, and kept as written', function () {
    expect(saveProfile($this->credential, profileBody(['display_name' => '  Ada  ']))->json('data.display_name'))->toBe('Ada')
        ->and(saveProfile($this->credential, profileBody(['display_name' => 'عائشة'], version: 2))->json('data.display_name'))->toBe('عائشة')
        ->and(saveProfile($this->credential, profileBody(['display_name' => '   '], version: 3))->json('data.display_name'))->toBeNull();
});

test('a new start day records the date it applies from; an unchanged one keeps it', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-27 12:00:00', new DateTimeZone('Africa/Cairo')));

    $first = saveProfile($this->credential, profileBody(['budget_period_start_day' => 25]))->json('data');
    $second = saveProfile($this->credential, profileBody(['budget_period_start_day' => 25, 'display_name' => 'Ada'], version: 2))->json('data');

    expect($first['budget_period_start_day_from'])->toBe(BudgetStartDay::appliesFrom(25, new DateTimeImmutable('2026-09-27')))
        ->toBe('2026-10-25')
        ->and($second['budget_period_start_day_from'])->toBe('2026-10-25');
});
