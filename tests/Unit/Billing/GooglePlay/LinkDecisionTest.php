<?php

use App\Modules\Billing\Domain\GooglePlay\LinkDecision;
use App\Modules\Billing\Domain\GooglePlay\LinkOutcome;

/*
 * Who may own a purchase (spec 024, R-3, FR-006 to FR-010): one row per line of the table.
 */

test('a purchase linked to the caller is already theirs', function () {
    $d = LinkDecision::decide('ada', 'ada', true, 'ada');

    expect($d->outcome)->toBe(LinkOutcome::AlreadyYours)->and($d->userId)->toBe('ada');
});

test('a purchase linked to another account is linked elsewhere, whatever its reference says', function () {
    expect(LinkDecision::decide('bob', 'ada', true, 'ada')->outcome)->toBe(LinkOutcome::LinkedElsewhere);
    expect(LinkDecision::decide('bob', 'ada', false, null)->outcome)->toBe(LinkOutcome::LinkedElsewhere);
});

test('an unlinked purchase whose reference names the caller is linked to the caller', function () {
    $d = LinkDecision::decide(null, 'ada', true, 'ada');

    expect($d->outcome)->toBe(LinkOutcome::Link)->and($d->userId)->toBe('ada');
});

test('an unlinked purchase whose reference names another active account is never linked to the caller', function () {
    expect(LinkDecision::decide(null, 'ada', true, 'bob')->outcome)->toBe(LinkOutcome::LinkedElsewhere);
});

test('an unlinked purchase whose reference resolves to no active account is never linked', function () {
    expect(LinkDecision::decide(null, 'ada', true, null)->outcome)->toBe(LinkOutcome::LinkedElsewhere);
    expect(LinkDecision::decide(null, null, true, null)->outcome)->toBe(LinkOutcome::LinkedElsewhere);
});

test('an unlinked purchase with no reference is linked to the first caller', function () {
    expect(LinkDecision::decide(null, 'ada', false, null)->outcome)->toBe(LinkOutcome::Link);
});

test('a replacing purchase inherits the replaced one\'s account and refuses anyone else', function () {
    expect(LinkDecision::decide(null, 'ada', false, null, true, 'ada')->outcome)->toBe(LinkOutcome::Link);
    expect(LinkDecision::decide(null, 'ada', false, null, true, 'bob')->outcome)->toBe(LinkOutcome::LinkedElsewhere);
    expect(LinkDecision::decide(null, 'ada', true, 'ada', true, 'bob')->outcome)->toBe(LinkOutcome::LinkedElsewhere);
    expect(LinkDecision::decide(null, 'ada', false, null, true, null)->outcome)->toBe(LinkOutcome::Link);
});

test('a notification links only to a named account, otherwise it stays unlinked', function () {
    $named = LinkDecision::decide(null, null, true, 'ada');
    $replaced = LinkDecision::decide(null, null, false, null, true, 'ada');

    expect($named->outcome)->toBe(LinkOutcome::Link)->and($named->userId)->toBe('ada')
        ->and($replaced->userId)->toBe('ada')
        ->and(LinkDecision::decide(null, null, false, null)->outcome)->toBe(LinkOutcome::Unlinked);
});

test('a notification for an already linked purchase leaves it with its owner', function () {
    $d = LinkDecision::decide('ada', null, false, null);

    expect($d->outcome)->toBe(LinkOutcome::AlreadyYours)->and($d->userId)->toBe('ada');
});
