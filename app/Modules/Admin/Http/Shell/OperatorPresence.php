<?php

namespace App\Modules\Admin\Http\Shell;

/**
 * Who is signed in, as the shell's operator block shows it. Bound by the
 * operator sign-in feature (brief D2); the gallery passes a sample (spec 006,
 * FR-051).
 */
final readonly class OperatorPresence
{
    /**
     * @param  string  $initial  one grapheme, for the avatar
     * @param  string  $statusKey  translation key, e.g. admin::shell.operator.two_factor_on
     */
    public function __construct(
        public string $displayName,
        public string $initial,
        public string $statusKey,
    ) {}
}
