<?php

namespace App\Modules\Reporting\Http\Controllers;

use App\Http\Api\Envelope;
use App\Modules\Reporting\Application\ReportQueryService;
use App\Modules\Reporting\Http\ReportQueryParser;
use App\Modules\Reporting\Http\Resources\ByCategoryResource;
use App\Modules\Reporting\Http\Resources\ReportMoney;
use App\Modules\Reporting\Http\Resources\SummaryResource;
use App\Modules\Reporting\Http\Resources\TrendResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The three reports across the caller's wallets in one currency (spec 019);
 * the wallets in other currencies are listed in `meta.excluded_wallets`.
 */
final class ReportController
{
    public function summary(Request $request, ReportQueryService $reports): JsonResponse
    {
        $report = $reports->summary($this->callerId($request), ReportQueryParser::parse($request, ReportQueryParser::RANGE, true));

        return Envelope::ok(SummaryResource::toArray($report), ReportMoney::meta($report->excluded ?? []));
    }

    public function byCategory(Request $request, ReportQueryService $reports): JsonResponse
    {
        $report = $reports->byCategory($this->callerId($request), ReportQueryParser::parse($request, ReportQueryParser::RANGE, true));

        return Envelope::ok(ByCategoryResource::toArray($report), ReportMoney::meta($report->excluded ?? []));
    }

    public function trend(Request $request, ReportQueryService $reports): JsonResponse
    {
        $report = $reports->trend($this->callerId($request), ReportQueryParser::parse($request, ReportQueryParser::TREND, true));

        return Envelope::ok(TrendResource::toArray($report), ReportMoney::meta($report->excluded ?? []));
    }

    private function callerId(Request $request): string
    {
        $user = $request->user() ?? throw new \RuntimeException('Route is missing auth:api.');

        return (string) $user->getAuthIdentifier();
    }
}
