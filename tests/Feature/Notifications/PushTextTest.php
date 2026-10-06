<?php

use App\Modules\Notifications\Application\Delivery\PushText;
use App\Modules\Notifications\Domain\Messages\MessageType;

/*
 * The push text is fixed, generic and in the profile language (spec 023,
 * FR-021, P-14): no placeholder, no number, no date, no name.
 */

test('every type has a title and a body in both languages', function (MessageType $type, string $locale) {
    $text = app(PushText::class)->render($type, $locale);

    expect($text['title'])->not->toBe('notifications::push.'.$type->value.'.title')->not->toBe('')
        ->and($text['body'])->not->toBe('notifications::push.'.$type->value.'.body')->not->toBe('');
})->with(MessageType::cases())->with(['en', 'ar']);

test('the two languages carry exactly the same keys', function () {
    $en = require base_path('app/Modules/Notifications/Http/Lang/en/push.php');
    $ar = require base_path('app/Modules/Notifications/Http/Lang/ar/push.php');

    expect(array_keys($ar))->toBe(array_keys($en))
        ->and(array_map(fn (array $entry): array => array_keys($entry), $ar))->toBe(array_map(fn (array $entry): array => array_keys($entry), $en))
        ->and(array_keys($en))->toBe(array_map(fn (MessageType $type): string => $type->value, MessageType::cases()));
});

test('no push string has a placeholder, a digit of any script, or a URL', function () {
    foreach (['en', 'ar'] as $locale) {
        $strings = require base_path("app/Modules/Notifications/Http/Lang/{$locale}/push.php");

        foreach ($strings as $type => $entry) {
            foreach ($entry as $part => $text) {
                expect($text)->not->toMatch('/:[a-z_]+/i', "{$locale} {$type}.{$part} has a placeholder")
                    ->not->toMatch('/\p{Nd}/u', "{$locale} {$type}.{$part} has a digit")
                    ->not->toContain('http')
                    ->not->toContain('@');
            }
        }
    }
});

test('a message of an unknown language falls back to English', function () {
    expect(app(PushText::class)->render(MessageType::BudgetExceeded, 'fr')['title'])->toBe('A budget is over its limit');
});
