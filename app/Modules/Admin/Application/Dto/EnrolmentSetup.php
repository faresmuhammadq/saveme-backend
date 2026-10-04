<?php

namespace App\Modules\Admin\Application\Dto;

/**
 * What the setup page shows: the key in groups of four, the setup link and
 * the account label. Handed to one view and not kept.
 */
final readonly class EnrolmentSetup
{
    public function __construct(
        public string $keyGrouped,
        public string $link,
        public string $label,
    ) {}
}
