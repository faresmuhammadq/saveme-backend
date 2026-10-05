<?php

use App\Modules\Admin\Application\Operators\OperatorDirectory;
use Illuminate\Support\Str;

it('returns display names for known operators in one call and leaves unknown ids out', function () {
    ['operator' => $first] = makeOperator();
    ['operator' => $second] = makeOperator();
    $unknown = (string) Str::uuid();

    $names = app(OperatorDirectory::class)->displayNames([$first->id, $second->id, $unknown]);

    expect($names)->toHaveKeys([$first->id, $second->id])
        ->and($names)->not->toHaveKey($unknown)
        ->and($names[$first->id])->toBeString()->not->toBe('')
        ->and(app(OperatorDirectory::class)->displayNames([]))->toBe([]);
});
