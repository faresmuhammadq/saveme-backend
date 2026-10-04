<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Application\Audit\AdminAuditService;
use App\Modules\Admin\Domain\Operators\AdminAction;
use App\Modules\Admin\Http\Auth\OperatorSession;
use App\Modules\Billing\Application\Claims\ClaimReceipts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a claim's receipt image to a signed-in operator through a signed link
 * valid 5 minutes (spec 020, FR-014, FR-014a, R-10; constitution P-18 rule 4).
 * The view is recorded before the image is streamed. The image comes back
 * decrypted by Billing's application service; it is shown inline with the
 * stored type, never cached, never sniffed, and under a policy that lets it
 * load nothing else.
 */
final class ClaimReceiptController
{
    public function __invoke(Request $request, string $claim, ClaimReceipts $receipts, AdminAuditService $audit, OperatorSession $session): Response
    {
        abort_unless(Str::isUuid($claim), 404);

        $receipt = $receipts->open($claim) ?? abort(404);
        $operatorId = $session->operatorId() ?? abort(403);

        DB::transaction(fn () => $audit->claimReadByOperator($operatorId, $claim, AdminAction::ClaimReceiptViewed));

        return response($receipt['bytes'], 200, [
            'Content-Type' => $receipt['media_type'],
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }
}
