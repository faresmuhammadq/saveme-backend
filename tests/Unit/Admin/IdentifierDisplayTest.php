<?php

/*
| Identifiers are middle-truncated for display (spec 006, FR-024).
*/

use App\Modules\Admin\Http\Presenters\IdentifierPresenter;

it('truncates long identifiers in the middle', function () {
    expect((new IdentifierPresenter)->truncate('0192f4c1-7a3e-7b21-9c0d-5e8a41f2b6d3'))->toBe('0192f4…f2b6d3');
});

it('keeps identifiers of sixteen characters or fewer whole', function () {
    expect((new IdentifierPresenter)->truncate('IP2609251447A7'))->toBe('IP2609251447A7');
});
