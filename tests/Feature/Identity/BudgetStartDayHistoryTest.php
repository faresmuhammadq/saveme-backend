<?php

use App\Modules\Identity\Application\Profiles\BudgetStartDayQuery;
use App\Modules\Identity\Application\Profiles\Profiles;
use App\Modules\Identity\Application\Profiles\SaveProfile;
use App\Modules\Identity\Domain\Ports\BudgetStartDayHistory;
use App\Modules\Identity\Infrastructure\Models\Profile;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * The budget start day history (spec 015, FR-007, research R-3): written only
 * with the profile, in its transaction; periods already begun keep their dates.
 */

function changeStartDay(string $userId, int $day, array $changes = []): void
{
    $current = app(Profiles::class)->find($userId);
    $values = [...profileBody(['budget_period_start_day' => $day, ...$changes]), 'display_name' => null];
    unset($values['version']);

    app(SaveProfile::class)->handle($userId, $values, $current->version, fn (): array => []);
}

function historyOf(string $userId): array
{
    return app(BudgetStartDayQuery::class)->entriesOf($userId);
}

beforeEach(function () {
    freezeClock('2026-10-02 10:00:00');
    $this->user = person();
});

test('the first change from an empty history records the day in force, then the new day from its date', function () {
    expect(historyOf($this->user->id))->toBe([]);

    changeStartDay($this->user->id, 25);

    expect(historyOf($this->user->id))->toBe([
        ['start_day' => 1, 'applies_from' => null],
        ['start_day' => 25, 'applies_from' => '2026-10-25'],
    ]);
});

test('a save that leaves the day alone writes no entry', function () {
    changeStartDay($this->user->id, 1, ['locale' => 'ar']);

    expect(historyOf($this->user->id))->toBe([]);

    changeStartDay($this->user->id, 25);
    changeStartDay($this->user->id, 25, ['locale' => 'en']);

    expect(historyOf($this->user->id))->toHaveCount(2);
});

test('a second change while the first is pending replaces the pending entry', function () {
    changeStartDay($this->user->id, 25);
    changeStartDay($this->user->id, 10);

    expect(historyOf($this->user->id))->toBe([
        ['start_day' => 1, 'applies_from' => null],
        ['start_day' => 10, 'applies_from' => '2026-10-10'],
    ]);
});

test('a change after the first has taken effect appends and keeps the earlier entries', function () {
    changeStartDay($this->user->id, 25);

    freezeClock('2026-11-01 10:00:00');
    changeStartDay($this->user->id, 10);

    expect(historyOf($this->user->id))->toBe([
        ['start_day' => 1, 'applies_from' => null],
        ['start_day' => 25, 'applies_from' => '2026-10-25'],
        ['start_day' => 10, 'applies_from' => '2026-11-10'],
    ]);
});

test('history and profile are written in one transaction: a failed history write leaves the profile alone', function () {
    app()->bind(BudgetStartDayHistory::class, fn () => new class implements BudgetStartDayHistory
    {
        public function entriesOf(string $userId): array
        {
            return [];
        }

        public function record(string $userId, int $previousDay, int $newDay, string $appliesFrom, string $today): void
        {
            throw new RuntimeException('history failed');
        }

        public function currentDayOf(string $userId): int
        {
            return 1;
        }
    });

    expect(fn () => changeStartDay($this->user->id, 25))->toThrow(RuntimeException::class);

    $profile = Profile::query()->where('user_id', $this->user->id)->sole();
    expect($profile->budget_period_start_day)->toBe(1)
        ->and($profile->version)->toBe(1)
        ->and(DB::table('budget_start_days')->count())->toBe(0);
});

test('the backfill gives a changed profile (1, null) and (day, from) and the others nothing', function () {
    $changed = person('changed@example.test');
    Profile::query()->where('user_id', $changed->id)->update(['budget_period_start_day' => 25, 'budget_period_start_day_from' => '2026-10-25']);
    DB::table('budget_start_days')->delete();

    DB::statement('DROP TABLE budget_start_days');
    (require base_path('app/Modules/Identity/Infrastructure/Database/Migrations/2026_10_06_000002_create_budget_start_days_table.php'))->up();

    expect(historyOf($changed->id))->toBe([
        ['start_day' => 1, 'applies_from' => null],
        ['start_day' => 25, 'applies_from' => '2026-10-25'],
    ])->and(historyOf($this->user->id))->toBe([]);
});

test('the history refuses a second first entry and a start day outside 1 to 28', function () {
    DB::table('budget_start_days')->insert(['id' => (string) Str::uuid7(), 'user_id' => $this->user->id, 'start_day' => 1, 'applies_from' => null, 'created_at' => now()]);

    expect(fn () => DB::table('budget_start_days')->insert(['id' => (string) Str::uuid7(), 'user_id' => $this->user->id, 'start_day' => 2, 'applies_from' => null, 'created_at' => now()]))
        ->toThrow(QueryException::class);
});

test('the start day check refuses 29', function () {
    expect(fn () => DB::table('budget_start_days')->insert(['id' => (string) Str::uuid7(), 'user_id' => $this->user->id, 'start_day' => 29, 'applies_from' => '2026-11-01', 'created_at' => now()]))
        ->toThrow(QueryException::class);
});

test('timezone and day fall back to the configured default without a profile', function () {
    $bare = User::factory()->create();
    $query = app(BudgetStartDayQuery::class);

    expect($query->timezoneOf($bare->id))->toBe(config('identity.default_timezone'))
        ->and($query->currentDayOf($bare->id))->toBe(1)
        ->and($query->entriesOf($bare->id))->toBe([]);

    Profile::query()->where('user_id', $this->user->id)->update(['timezone' => 'America/New_York', 'budget_period_start_day' => 25]);

    expect($query->timezoneOf($this->user->id))->toBe('America/New_York')
        ->and($query->currentDayOf($this->user->id))->toBe(25);
});

test('deleting the person removes the history', function () {
    changeStartDay($this->user->id, 25);

    User::query()->whereKey($this->user->id)->delete();

    expect(DB::table('budget_start_days')->where('user_id', $this->user->id)->count())->toBe(0);
});
