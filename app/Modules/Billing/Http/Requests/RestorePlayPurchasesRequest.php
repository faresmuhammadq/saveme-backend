<?php

namespace App\Modules\Billing\Http\Requests;

use App\Http\Api\Requests\ApiRequest;
use App\Modules\Billing\Application\Plans\PlanCatalogue;
use Closure;
use Illuminate\Validation\Rule;

/**
 * The body of POST /billing/google/restore (spec 024, FR-017): 0 to 25
 * `{purchase_token, product_id}` items. A token repeated within the request
 * is INVALID_TYPE at the repeat's `/purchases/{i}/purchase_token`.
 */
final class RestorePlayPurchasesRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'purchases' => ['bail', 'present', 'array', 'max:25'],
            'purchases.*.purchase_token' => [
                'bail', 'required', 'string', 'min:1', 'max:2048',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $index = (int) explode('.', $attribute)[1];
                    $earlier = array_slice(array_values((array) $this->input('purchases')), 0, $index);

                    foreach ($earlier as $item) {
                        if (is_array($item) && ($item['purchase_token'] ?? null) === $value) {
                            $fail('problems.fields.INVALID_TYPE')->translate();

                            return;
                        }
                    }
                },
            ],
            'purchases.*.product_id' => ['bail', 'required', 'string', 'max:150', Rule::in(app(PlanCatalogue::class)->playProductIds())],
        ];
    }

    /**
     * @return list<array{purchase_token: string, product_id: string}>
     */
    public function items(): array
    {
        return array_values(array_map(
            fn (array $item): array => ['purchase_token' => (string) $item['purchase_token'], 'product_id' => (string) $item['product_id']],
            (array) $this->input('purchases', []),
        ));
    }

    public function callerId(): string
    {
        $user = $this->user() ?? throw new \RuntimeException('Route is missing auth:api.');

        return (string) $user->getAuthIdentifier();
    }
}
