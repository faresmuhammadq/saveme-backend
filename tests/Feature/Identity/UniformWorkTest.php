<?php

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;

/*
 * Sign-in and password reset do the same work whether or not an address is
 * registered (security baseline, Uniform answers; spec 007, R-10). Registration
 * left the uniform set by owner decision (2026-09-27) and answers 409.
 * The statistical timing check is quickstart §3; this proves the work matches.
 */

beforeEach(fn () => Queue::fake());

/**
 * Counts hash operations during $act.
 *
 * @return array{make: int, check: int}
 */
function hashWork(Closure $act): array
{
    $real = Hash::getFacadeRoot();
    $counts = ['make' => 0, 'check' => 0];
    $spy = Mockery::mock($real)->makePartial();
    $spy->shouldReceive('make')->andReturnUsing(function (...$args) use ($real, &$counts) {
        $counts['make']++;

        return $real->make(...$args);
    });
    $spy->shouldReceive('check')->andReturnUsing(function (...$args) use ($real, &$counts) {
        $counts['check']++;

        return $real->check(...$args);
    });
    Hash::swap($spy);

    try {
        $act();
    } finally {
        Hash::swap($real);
    }

    return $counts;
}

test('sign-in checks one hash for a wrong password and for an unknown address', function () {
    person();

    $wrong = hashWork(fn () => signInAs(password: 'not the password'));
    $unknown = hashWork(fn () => signInAs(email: 'nobody@example.test'));

    expect($wrong['check'])->toBe(1)->and($unknown['check'])->toBe(1);
});
