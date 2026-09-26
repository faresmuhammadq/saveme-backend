<?php

use App\Modules\Identity\Domain\Audit\AuditEvent;

/*
 * The audit vocabulary is fixed (security baseline, What is recorded). This
 * pins the enum to the baseline's table, so a local event name cannot appear.
 */

test('the event enum is exactly the baseline vocabulary', function () {
    $baseline = file_get_contents(dirname(__DIR__, 3).'/docs/security-baseline.md');
    $section = substr($baseline, strpos($baseline, '## What is recorded'), 2000);
    preg_match_all('/`([a-z_]+)`/', substr($section, 0, strpos($section, '**Rules**')), $matches);

    expect(array_map(fn (AuditEvent $event): string => $event->value, AuditEvent::cases()))
        ->toEqualCanonicalizing(array_values(array_unique($matches[1])))
        ->toHaveCount(19);
});
