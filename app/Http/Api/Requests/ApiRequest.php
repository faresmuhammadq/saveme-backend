<?php

namespace App\Http\Api\Requests;

use App\Http\Api\Problems\ApiRefusal;
use App\Http\Api\Problems\FieldErrors;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;

/**
 * Base form request for every /api/v1 body.
 *
 * Unknown members are refused, not ignored (docs/api-conventions.md, Media
 * types): a member that no rule declares is reported as UNKNOWN_FIELD, in the
 * same VALIDATION_FAILED as any other fault, so a client that renamed a field
 * learns at once instead of silently dropping data.
 */
abstract class ApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    abstract public function rules(): array;

    protected function failedValidation(Validator $validator): never
    {
        throw $this->refusal([...FieldErrors::fromValidator($validator), ...$this->unknownMembers()]);
    }

    /**
     * Runs the unknown-member check even when every declared rule passes.
     */
    protected function passedValidation(): void
    {
        $unknown = $this->unknownMembers();

        if ($unknown !== []) {
            throw $this->refusal($unknown);
        }
    }

    /**
     * @param  list<FieldError>  $errors
     */
    protected function refusal(array $errors): ApiRefusal
    {
        return new ApiRefusal(ErrorCode::ValidationFailed, fieldErrors: $errors);
    }

    /**
     * @return list<FieldError>
     */
    protected function unknownMembers(): array
    {
        $declared = array_keys($this->rules());
        $unknown = [];

        foreach (array_keys(Arr::dot($this->json()->all())) as $key) {
            $key = (string) $key;

            if (! $this->isDeclared($key, $declared)) {
                $unknown[] = new FieldError(FieldErrors::pointer($this->outermostUndeclared($key, $declared)), ErrorCode::UnknownField);
            }
        }

        return array_values(array_unique($unknown, SORT_REGULAR));
    }

    /**
     * @param  list<string>  $declared
     */
    private function isDeclared(string $key, array $declared): bool
    {
        foreach ($declared as $rule) {
            $pattern = '/^'.str_replace('\*', '[^.]+', preg_quote($rule, '/')).'$/';

            if (preg_match($pattern, $key) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * For `device.extra.deep`, reports `device.extra`: the first prefix that
     * is neither a declared member nor the parent of one.
     *
     * @param  list<string>  $declared
     */
    private function outermostUndeclared(string $key, array $declared): string
    {
        $segments = explode('.', $key);

        for ($length = 1; $length < count($segments); $length++) {
            $prefix = implode('.', array_slice($segments, 0, $length));

            if (! $this->isDeclared($prefix, $declared) && ! $this->isParentOfDeclared($prefix, $declared)) {
                return $prefix;
            }
        }

        return $key;
    }

    /**
     * @param  list<string>  $declared
     */
    private function isParentOfDeclared(string $prefix, array $declared): bool
    {
        $depth = substr_count($prefix, '.') + 1;

        foreach ($declared as $rule) {
            $ruleSegments = explode('.', $rule);

            if (count($ruleSegments) > $depth && $this->isDeclared($prefix, [implode('.', array_slice($ruleSegments, 0, $depth))])) {
                return true;
            }
        }

        return false;
    }
}
