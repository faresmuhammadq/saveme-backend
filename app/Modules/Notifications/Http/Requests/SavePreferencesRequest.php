<?php

namespace App\Modules\Notifications\Http\Requests;

use App\Http\Api\Requests\ApiRequest;
use App\Modules\Notifications\Domain\Preferences\Category;
use App\Modules\Notifications\Domain\Preferences\Preferences;
use App\Modules\Notifications\Domain\Preferences\QuietHours;
use App\Modules\Notifications\Http\Rules\QuarterHour;
use App\Modules\Notifications\Http\Rules\StrictBoolean;

/**
 * The whole preferences and the last-seen version (spec 023, FR-013 to
 * FR-016): every member required, the six categories booleans, quiet hours on
 * a quarter hour with start never equal to end. Anything else, `updated_at`
 * included, is UNKNOWN_FIELD.
 */
final class SavePreferencesRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'version' => ['required', 'integer', 'min:1'],
            'categories' => ['required', 'array'],
            'quiet_hours' => ['required', 'array'],
            'quiet_hours.enabled' => ['required', new StrictBoolean],
            'quiet_hours.start' => ['required', 'bail', 'string', new QuarterHour],
            'quiet_hours.end' => ['required', 'bail', 'string', new QuarterHour, 'different:quiet_hours.start'],
        ];

        foreach (Category::cases() as $category) {
            $rules["categories.{$category->value}"] = ['required', new StrictBoolean];
        }

        return $rules;
    }

    public function version(): int
    {
        return $this->integer('version');
    }

    /**
     * The choices sent, as preferences without a meaningful version or time.
     */
    public function choices(): Preferences
    {
        $categories = [];

        foreach (Category::cases() as $category) {
            $categories[$category->value] = (bool) $this->input("categories.{$category->value}");
        }

        return new Preferences(
            $this->version(),
            $categories,
            new QuietHours(
                (bool) $this->input('quiet_hours.enabled'),
                (int) QuietHours::parse($this->string('quiet_hours.start')->toString()),
                (int) QuietHours::parse($this->string('quiet_hours.end')->toString()),
            ),
            null,
        );
    }
}
