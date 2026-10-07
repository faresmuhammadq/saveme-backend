<?php

namespace App\Modules\Billing\Http\Requests;

use App\Http\Api\Requests\ApiRequest;
use App\Modules\Billing\Application\Plans\PlanCatalogue;
use Illuminate\Validation\Rule;

/**
 * The body of POST /billing/google/purchases (spec 024, FR-001, FR-003):
 * `purchase_token` (1 to 2048 characters) and `product_id`, a Play product id
 * the catalogue maps. Nothing else: never a price, amount or currency.
 */
final class VerifyPlayPurchaseRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'purchase_token' => ['bail', 'required', 'string', 'min:1', 'max:2048'],
            'product_id' => ['bail', 'required', 'string', 'max:150', Rule::in(app(PlanCatalogue::class)->playProductIds())],
        ];
    }

    public function purchaseToken(): string
    {
        return $this->string('purchase_token')->toString();
    }

    public function productId(): string
    {
        return $this->string('product_id')->toString();
    }

    public function callerId(): string
    {
        $user = $this->user() ?? throw new \RuntimeException('Route is missing auth:api.');

        return (string) $user->getAuthIdentifier();
    }
}
