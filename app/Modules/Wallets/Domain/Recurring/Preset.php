<?php

namespace App\Modules\Wallets\Domain\Recurring;

/**
 * A recurring rule's preset (glossary): `gameya` and `installment` constrain
 * the shape of the rule, `custom` is any rule. Never changes. Behaviour, not
 * reference data: nothing is seeded (spec 014, A-27).
 */
enum Preset: string
{
    case Custom = 'custom';
    case Gameya = 'gameya';
    case Installment = 'installment';
}
