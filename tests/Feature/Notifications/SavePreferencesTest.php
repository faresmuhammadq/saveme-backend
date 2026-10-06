<?php

use App\Modules\Notifications\Application\PreferencesService;
use App\Modules\Notifications\Domain\Preferences\Category;
use App\Modules\Notifications\Domain\Preferences\Preferences;
use App\Modules\Notifications\Domain\Preferences\PreferencesVersionConflict;
use App\Modules\Notifications\Domain\Preferences\QuietHours;
use App\Modules\Notifications\Infrastructure\Models\NotificationPreference;
use Illuminate\Database\QueryException;

function choices(array $categories = [], ?QuietHours $quiet = null): Preferences
{
    $defaults = app(PreferencesService::class)->defaults();

    return new Preferences(0, [...$defaults->categories, ...$categories], $quiet ?? $defaults->quietHours, null);
}

function present(Preferences $preferences): array
{
    return ['version' => $preferences->version];
}

test('a read does not insert', function () {
    $user = person();

    $read = app(PreferencesService::class)->read($user->id);

    expect($read->version)->toBe(1)->and($read->updatedAt)->toBeNull()
        ->and(NotificationPreference::query()->count())->toBe(0);
});

test('the first save carries version 1 and inserts at version 2', function () {
    $user = person();

    $saved = app(PreferencesService::class)->save($user->id, choices(['sharing' => false]), 1, present(...));

    expect($saved->version)->toBe(2)
        ->and($saved->allows(Category::Sharing))->toBeFalse()
        ->and(NotificationPreference::query()->count())->toBe(1);
});

test('saving the defaults at version 1 changes nothing and inserts nothing', function () {
    $user = person();

    $saved = app(PreferencesService::class)->save($user->id, choices(), 1, present(...));

    expect($saved->version)->toBe(1)->and(NotificationPreference::query()->count())->toBe(0);
});

test('a stale version applies nothing and carries the stored preferences', function () {
    $user = person();
    $service = app(PreferencesService::class);
    $service->save($user->id, choices(['sharing' => false]), 1, present(...));

    try {
        $service->save($user->id, choices(['billing' => false]), 1, present(...));
        $this->fail('Expected a conflict.');
    } catch (PreferencesVersionConflict $conflict) {
        expect($conflict->extensions['current'])->toBe(['version' => 2]);
    }

    expect($service->read($user->id)->allows(Category::Billing))->toBeTrue();
});

test('a first save with a wrong version is refused with the defaults as current', function () {
    $user = person();

    expect(fn () => app(PreferencesService::class)->save($user->id, choices(['sharing' => false]), 5, present(...)))
        ->toThrow(PreferencesVersionConflict::class)
        ->and(NotificationPreference::query()->count())->toBe(0);
});

test('an identical save at the current version is unchanged', function () {
    $user = person();
    $service = app(PreferencesService::class);
    $first = $service->save($user->id, choices(['sharing' => false]), 1, present(...));

    $again = $service->save($user->id, choices(['sharing' => false]), 2, present(...));

    expect($again->version)->toBe(2)->and($again->updatedAt)->toEqual($first->updatedAt);
});

test('every later change adds one', function () {
    $user = person();
    $service = app(PreferencesService::class);

    $service->save($user->id, choices(['sharing' => false]), 1, present(...));
    $service->save($user->id, choices(['sharing' => false, 'billing' => false]), 2, present(...));
    $third = $service->save($user->id, choices([], new QuietHours(false, 22 * 60, 8 * 60)), 3, present(...));

    expect($third->version)->toBe(4)->and($third->quietHours->enabled)->toBeFalse();
});

test('two saves at the same version: one wins, the other is a conflict', function () {
    $user = person();
    $service = app(PreferencesService::class);
    $service->save($user->id, choices(['sharing' => false]), 1, present(...));

    $results = [];
    foreach ([['billing', 'a'], ['export_ready', 'b']] as [$category, $name]) {
        try {
            $service->save($user->id, choices(['sharing' => false, $category => false]), 2, present(...));
            $results[$name] = 'ok';
        } catch (PreferencesVersionConflict) {
            $results[$name] = 'conflict';
        }
    }

    expect($results)->toBe(['a' => 'ok', 'b' => 'conflict']);
});

test('the database refuses quiet hours that are equal or off the quarter hour', function () {
    $user = person();
    $row = ['user_id' => $user->id, 'version' => 2, 'created_at' => now(), 'updated_at' => now()];

    expect(fn () => DB::transaction(fn () => DB::table('notification_preferences')->insert([...$row, 'quiet_hours_start' => 480, 'quiet_hours_end' => 480])))->toThrow(QueryException::class);
});
