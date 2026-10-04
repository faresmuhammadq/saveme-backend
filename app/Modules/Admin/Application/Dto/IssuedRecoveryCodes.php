<?php

namespace App\Modules\Admin\Application\Dto;

/**
 * A fresh set of recovery codes, in the form they are displayed. This object
 * exists for the one response that shows them; it is never stored, logged,
 * serialised into the session or held in public Livewire state.
 */
final readonly class IssuedRecoveryCodes
{
    /**
     * @param  list<string>  $codes  XXXXX-XXXXX
     */
    public function __construct(public array $codes) {}
}
