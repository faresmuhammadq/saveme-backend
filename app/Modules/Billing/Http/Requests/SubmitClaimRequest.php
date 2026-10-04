<?php

namespace App\Modules\Billing\Http\Requests;

use App\Http\Api\Problems\FieldErrors;
use App\Http\Api\Requests\ApiRequest;
use App\Http\Api\Rules\MinorUnits;
use App\Modules\Billing\Application\Claims\SubmitClaim;
use App\Modules\Billing\Domain\Claims\ClaimPrice;
use App\Modules\Billing\Domain\Claims\ClaimReference;
use App\Modules\Billing\Domain\Claims\ReceiptImage;
use App\Modules\Billing\Domain\Claims\ReceiptMediaType;
use App\Modules\Billing\Domain\Ports\PlanRepository;
use App\Modules\Billing\Domain\Refusals\InvalidReceiptImage;
use App\Modules\Identity\Application\Billing\ClaimAddresses;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * The claim form (spec 020, FR-003 to FR-007, FR-012): `email`, `plan_id`,
 * `amount_minor`, `currency`, `reference` and an optional `screenshot`.
 * Shape rules run first; then the meaning of the members that passed them:
 * the plan, its exact price, the address and reference forms, the image.
 * Every fault is one entry in one VALIDATION_FAILED.
 */
final class SubmitClaimRequest extends ApiRequest
{
    /** @var list<FieldError> */
    private array $semanticFaults = [];

    private ?ReceiptImage $receipt = null;

    private ?string $addressLookup = null;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $screenshot = $this->has('screenshot');

        return [
            'email' => ['bail', 'required', 'string', 'max:254'],
            'plan_id' => ['bail', 'required', 'string'],
            'amount_minor' => ['bail', 'required', new MinorUnits, 'min:0'],
            'currency' => ['bail', 'required', 'string'],
            'reference' => ['bail', 'required', 'string'],
            'screenshot' => ['sometimes', 'array'],
            'screenshot.media_type' => $screenshot ? ['bail', 'required', 'string', Rule::in(array_map(fn (ReceiptMediaType $t): string => $t->value, ReceiptMediaType::cases()))] : ['sometimes'],
            'screenshot.data' => $screenshot ? ['bail', 'required', 'string'] : ['sometimes'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->semanticFaults = $this->semanticFaults($validator);

            if ($this->semanticFaults !== []) {
                $validator->errors()->add('semantic', 'invalid');
            }
        });
    }

    protected function failedValidation(Validator $validator): never
    {
        throw $this->refusal(array_values(array_unique(
            [...FieldErrors::fromValidator($validator), ...$this->semanticFaults, ...$this->unknownMembers()],
            SORT_REGULAR,
        )));
    }

    public function toCommand(): SubmitClaim
    {
        return new SubmitClaim(
            address: $this->string('email')->toString(),
            planId: $this->string('plan_id')->toString(),
            amountMinor: $this->integer('amount_minor'),
            currency: $this->string('currency')->toString(),
            reference: $this->string('reference')->toString(),
            locale: app()->getLocale() === 'ar' ? 'ar' : 'en',
            receipt: $this->receipt,
        );
    }

    /**
     * The address's keyed lookup value, for the per-address budget.
     */
    public function addressLookup(): string
    {
        return $this->addressLookup ?? throw new \LogicException('The request has not been validated.');
    }

    /**
     * @return list<FieldError>
     */
    private function semanticFaults(Validator $validator): array
    {
        $errors = $validator->errors();
        $faults = [];

        if (! $errors->has('email') && is_string($this->input('email'))) {
            try {
                $this->addressLookup = app(ClaimAddresses::class)->normaliseAndLookup($this->input('email'))['lookup'];
            } catch (InvalidArgumentException) {
                $faults[] = new FieldError('/email', ErrorCode::InvalidType);
            }
        }

        if (! $errors->has('reference') && is_string($this->input('reference')) && ! ClaimReference::isValid($this->input('reference'))) {
            $faults[] = new FieldError('/reference', ErrorCode::InvalidType);
        }

        if (! $errors->has('plan_id') && is_string($this->input('plan_id'))) {
            $planId = $this->input('plan_id');
            $plan = Str::isUuid($planId) ? app(PlanRepository::class)->find($planId) : null;
            $amount = ! $errors->has('amount_minor') && is_int($this->input('amount_minor')) ? $this->input('amount_minor') : null;
            $currency = ! $errors->has('currency') && is_string($this->input('currency')) ? $this->input('currency') : null;

            array_push($faults, ...ClaimPrice::check($plan, $amount, $currency));
        }

        if ($this->has('screenshot') && ! $errors->has('screenshot') && ! $errors->has('screenshot.media_type') && ! $errors->has('screenshot.data')
            && is_string($this->input('screenshot.media_type')) && is_string($this->input('screenshot.data'))) {
            try {
                $this->receipt = ReceiptImage::from($this->input('screenshot.media_type'), $this->input('screenshot.data'), (int) config('billing.instapay.receipt_max_bytes'));
            } catch (InvalidReceiptImage $e) {
                array_push($faults, ...$e->fieldErrors);
            }
        }

        return $faults;
    }
}
