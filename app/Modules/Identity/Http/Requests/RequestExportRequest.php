<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Domain\Export\ExportScope;
use Illuminate\Validation\Validator;

/**
 * `{ scope: account|wallet, wallet_id? }` (spec 022, FR-008): `wallet_id`
 * is required with `wallet` and refused with `account`.
 */
final class RequestExportRequest extends IdentityRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'scope' => ['required', 'string', 'in:account,wallet'],
            'wallet_id' => ['sometimes', 'string', 'uuid'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $scope = $this->input('scope');

            if ($scope === 'wallet' && ! $this->has('wallet_id')) {
                $validator->addFailure('wallet_id', 'required');
            }

            if ($scope === 'account' && $this->has('wallet_id')) {
                $validator->addFailure('wallet_id', 'prohibited');
            }
        });
    }

    public function scope(): ExportScope
    {
        return ExportScope::from($this->string('scope')->toString());
    }

    public function walletId(): ?string
    {
        return $this->has('wallet_id') ? $this->string('wallet_id')->toString() : null;
    }
}
