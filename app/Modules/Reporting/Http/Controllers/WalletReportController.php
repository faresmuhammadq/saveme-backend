<?php

namespace App\Modules\Reporting\Http\Controllers;

use App\Http\Api\Envelope;
use App\Modules\Reporting\Application\ReportQueryService;
use App\Modules\Reporting\Http\ReportQueryParser;
use App\Modules\Reporting\Http\ReportWalletId;
use App\Modules\Reporting\Http\Resources\ByCategoryResource;
use App\Modules\Reporting\Http\Resources\ReportMoney;
use App\Modules\Reporting\Http\Resources\SummaryResource;
use App\Modules\Reporting\Http\Resources\TrendResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The three reports of one wallet (spec 019). The order of refusals is fixed:
 * 401, then 422 INVALID_ID, then 404 (a non-member learns nothing about the
 * parameters), then 400, then the report.
 */
final class WalletReportController
{
    public function summary(Request $request, string $id, ReportQueryService $reports): JsonResponse
    {
        [$userId, $walletId] = $this->readable($request, $id, $reports);
        $query = ReportQueryParser::parse($request, ReportQueryParser::RANGE, false);

        return Envelope::ok(SummaryResource::toArray($reports->walletSummary($userId, $walletId, $query)), ReportMoney::meta(null));
    }

    public function byCategory(Request $request, string $id, ReportQueryService $reports): JsonResponse
    {
        [$userId, $walletId] = $this->readable($request, $id, $reports);
        $query = ReportQueryParser::parse($request, ReportQueryParser::RANGE, false);

        return Envelope::ok(ByCategoryResource::toArray($reports->walletByCategory($userId, $walletId, $query)), ReportMoney::meta(null));
    }

    public function trend(Request $request, string $id, ReportQueryService $reports): JsonResponse
    {
        [$userId, $walletId] = $this->readable($request, $id, $reports);
        $query = ReportQueryParser::parse($request, ReportQueryParser::TREND, false);

        return Envelope::ok(TrendResource::toArray($reports->walletTrend($userId, $walletId, $query)), ReportMoney::meta(null));
    }

    /**
     * @return array{0: string, 1: string} the caller and the wallet they may read
     */
    private function readable(Request $request, string $id, ReportQueryService $reports): array
    {
        $walletId = ReportWalletId::assert($id);
        $user = $request->user() ?? throw new \RuntimeException('Route is missing auth:api.');
        $userId = (string) $user->getAuthIdentifier();

        $reports->assertWalletReadable($userId, $walletId);

        return [$userId, $walletId];
    }
}
