<?php

use App\Modules\Admin\Application\OperatorNotificationQueryService;
use App\Modules\Admin\Domain\Notifications\OperatorNotificationView;
use App\Modules\Admin\Domain\Ports\OperatorNotificationStore;
use App\Modules\Admin\Http\Livewire\OperatorNotificationBell;
use Illuminate\Support\Arr;
use Livewire\Livewire;

/*
 * The dashboard bell (spec 007, R-14): count and list from the query
 * service, scalars only, both languages.
 */

beforeEach(function () {
    app()->instance(OperatorNotificationStore::class, new class implements OperatorNotificationStore
    {
        public function recordOnce(string $kind, string $subjectUserId, DateTimeImmutable $occurredAt, array $payload, string $sourceEventId): bool
        {
            return true;
        }

        public function latest(int $limit): array
        {
            return [new OperatorNotificationView(
                '0192f7a0-0000-7000-8000-000000000001', 'deletion_cancelled_by_sign_in', '0192f7a0-1c3d-7abc-9f01-2b3c4d5e6f70',
                '2026-09-26T08:30:00.000000Z',
                'admin::notifications.kinds.deletion_cancelled_by_sign_in.title',
                'admin::notifications.kinds.deletion_cancelled_by_sign_in.body',
            )];
        }

        public function countSince(DateTimeImmutable $since): int
        {
            return 1;
        }
    });
});

test('the bell shows the count and the list, in both languages', function (string $locale) {
    ['operator' => $operator] = makeOperator();
    signInOperator($operator);
    app()->setLocale($locale);

    Livewire::test(OperatorNotificationBell::class)
        ->assertSet('unread', 1)
        ->assertSee(trans('admin::notifications.kinds.deletion_cancelled_by_sign_in.title', [], $locale))
        ->assertSee('0192f7a0-1c3d-7abc-9f01-2b3c4d5e6f70', false);
})->with(['en', 'ar']);

test('the bell is refused when nobody is signed in', function () {
    Livewire::test(OperatorNotificationBell::class)->assertStatus(401);
});

test('the bell holds only scalars and arrays', function () {
    $properties = (new ReflectionClass(OperatorNotificationBell::class))->getProperties(ReflectionProperty::IS_PUBLIC);
    $declared = array_filter($properties, fn (ReflectionProperty $property): bool => $property->getDeclaringClass()->getName() === OperatorNotificationBell::class);

    expect(array_map(fn (ReflectionProperty $property): string => (string) $property->getType(), array_values($declared)))->toBe(['int', 'array']);
});

test('every notification string exists in both languages', function () {
    $en = require app_path('Modules/Admin/Http/Lang/en/notifications.php');
    $ar = require app_path('Modules/Admin/Http/Lang/ar/notifications.php');

    expect(array_keys(Arr::dot($ar)))->toBe(array_keys(Arr::dot($en)));
});

test('the query service resolves', function () {
    expect(app(OperatorNotificationQueryService::class)->unreadCount())->toBe(1);
});
