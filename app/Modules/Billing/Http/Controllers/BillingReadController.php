<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Api\Envelope;
use App\Modules\Billing\Application\Entitlements\EntitlementService;
use App\Modules\Billing\Application\Plans\PlanCatalogue;
use App\Modules\Billing\Application\Subscriptions\MySubscription;
use App\Modules\Billing\Domain\Plans\PlanRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The three Billing reads (spec 009). Nothing here writes (P-10).
 */
final class BillingReadController
{
    private const CATALOGUE_PAGE = 50;

    public function plans(PlanCatalogue $catalogue): JsonResponse
    {
        $locale = app()->getLocale();

        return Envelope::collection(
            array_map(fn (PlanRecord $plan): array => PlanCatalogue::toWire($plan, $locale), $catalogue->active()),
            null,
            self::CATALOGUE_PAGE,
        );
    }

    public function entitlements(Request $request, EntitlementService $entitlements): JsonResponse
    {
        return Envelope::ok($entitlements->summaryFor(self::caller($request))->toWire(app()->getLocale()));
    }

    public function subscription(Request $request, MySubscription $subscription): JsonResponse
    {
        return Envelope::ok($subscription->toWire(self::caller($request), app()->getLocale()));
    }

    private static function caller(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }
}
