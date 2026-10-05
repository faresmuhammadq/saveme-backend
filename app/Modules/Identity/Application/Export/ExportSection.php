<?php

namespace App\Modules\Identity\Application\Export;

/**
 * One module's share of an export. Identity orchestrates; Wallets,
 * Budgeting, Sharing and Billing implement this in their own Application
 * layer and tag it `identity.export-sections` (P-02). A section reads only
 * through its own module's repositories and query services, as the person,
 * and writes only what the person entered or can already see: never
 * another person's name, address, identifier or categories.
 */
interface ExportSection
{
    public function write(ExportTarget $target, ExportWriter $writer): void;
}
