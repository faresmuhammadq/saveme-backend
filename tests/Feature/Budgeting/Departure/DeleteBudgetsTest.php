<?php

use App\Modules\Budgeting\Application\Departure\DeleteBudgets;
use App\Modules\Identity\Domain\Deletion\PurgeStep;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * Budgeting's step of an account purge (spec 022, FR-046): every budget is
 * tombstoned from the person's counter, then removed, the user scope's purge
 * horizon raised past it.
 */

beforeEach(function () {
    $this->d = person();
    $this->p = person('pat@example.test');
});

test('every budget of the person is removed, the horizon passes the highest stamp, counts are returned, a second call is a no-op', function () {
    $first = createBudget($this->d->id, ['category_id' => null]);
    $second = createBudget($this->d->id, ['category_id' => platformCategoryId()]);
    $archived = createBudget($this->d->id, ['category_id' => nthPlatformCategoryId(1)]);
    $theirs = createBudget($this->p->id, ['category_id' => null]);
    $step = app(DeleteBudgets::class);
    $highest = (int) DB::table('users')->where('id', $this->d->id)->value('change_seq');

    $counts = $step->purge($this->d->id, purgeContextFor($this->d->id));

    expect($step->step())->toBe(PurgeStep::Budgets)
        ->and($counts->get('budgets_deleted'))->toBe(3)
        ->and(DB::table('budgets')->where('user_id', $this->d->id)->count())->toBe(0)
        ->and(DB::table('budgets')->where('id', $theirs->id)->exists())->toBeTrue()
        ->and((int) DB::table('users')->where('id', $this->d->id)->value('purged_change_seq'))->toBeGreaterThan($highest)
        ->and((int) DB::table('users')->where('id', $this->d->id)->value('purged_change_seq'))->toBe((int) DB::table('users')->where('id', $this->d->id)->value('change_seq'));

    expect($step->purge($this->d->id, purgeContextFor($this->d->id))->get('budgets_deleted'))->toBe(0);
});

test('a budget already tombstoned by the person is removed too', function () {
    $budget = createBudget($this->d->id, ['category_id' => null]);
    budgetCall('DELETE', signInAsCredential($this->d), '/budgets/'.$budget->id, ['version' => 1]);

    expect(DB::table('budgets')->where('id', $budget->id)->whereNotNull('deleted_at')->exists())->toBeTrue();

    app(DeleteBudgets::class)->purge($this->d->id, purgeContextFor($this->d->id));

    expect(DB::table('budgets')->where('user_id', $this->d->id)->count())->toBe(0);
});

function signInAsCredential(User $user): string
{
    return signInAs('ada@example.test')->json('data.credential');
}
