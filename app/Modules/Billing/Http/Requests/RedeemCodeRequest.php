<?php

namespace App\Modules\Billing\Http\Requests;

use App\Http\Api\Requests\ApiRequest;
use App\Modules\Billing\Domain\RedeemCodes\RedeemCode;
use Closure;

/**
 * The body of POST /billing/redeem (spec 020, FR-023): `code`, in any case,
 * with or without hyphens or spaces. Anything that is not 16 code characters
 * once read loosely is INVALID_TYPE at /code.
 */
final class RedeemCodeRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => [
                'bail', 'required', 'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (RedeemCode::tryParse((string) $value) === null) {
                        $fail('problems.fields.INVALID_TYPE')->translate();
                    }
                },
            ],
        ];
    }

    public function code(): string
    {
        return $this->string('code')->toString();
    }

    public function callerId(): string
    {
        $user = $this->user() ?? throw new \RuntimeException('Route is missing auth:api.');

        return (string) $user->getAuthIdentifier();
    }
}
