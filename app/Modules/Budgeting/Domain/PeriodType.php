<?php

namespace App\Modules\Budgeting\Domain;

/**
 * The Budget Period type (spec 015, FR-002). Only monthly in this release.
 */
enum PeriodType: string
{
    case Monthly = 'monthly';
}
