<?php

namespace App\Modules\Identity\Application\Profiles;

use App\Modules\Identity\Application\Events;
use App\Modules\Identity\Domain\Events\ProfileUpdated;
use App\Modules\Identity\Domain\Ports\AttributeCipher;
use App\Modules\Identity\Domain\Ports\ProfileRepository;
use App\Modules\Identity\Domain\Preferences\BudgetStartDay;
use App\Modules\Identity\Domain\Preferences\ProfileRecord;
use App\Modules\Identity\Domain\Refusals\DefaultCurrencyLocked;
use App\Modules\Identity\Domain\Refusals\ProfileVersionConflict;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * Saves the whole profile (spec 008, R-1). In order: an identical save
 * changes nothing; a stale version is refused with the current profile; a
 * locked default currency cannot change; otherwise the profile is written,
 * version and change_seq move, and ProfileUpdated follows the commit.
 */
final class SaveProfile
{
    public function __construct(
        private readonly Profiles $profiles,
        private readonly ProfileRepository $repository,
        private readonly AttributeCipher $cipher,
    ) {}

    /**
     * @param  array{display_name: ?string, locale: string, timezone: string, default_currency: string, budget_period_start_day: int}  $values
     * @param  Closure(ProfileRecord, bool): array<string, mixed>  $present  how a read renders the profile, for the conflict's `current`
     */
    public function handle(string $userId, array $values, int $version, Closure $present): ProfileRecord
    {
        $name = $values['display_name'] === null ? '' : trim($values['display_name']);
        $values['display_name'] = $name === '' ? null : $name;

        $current = $this->profiles->find($userId);

        if ($values === $current->writableValues()) {
            return $current;
        }

        if ($version !== $current->version) {
            throw new ProfileVersionConflict($present($current, $this->profiles->isCurrencyLocked($userId)));
        }

        if ($values['default_currency'] !== $current->defaultCurrency && $this->profiles->isCurrencyLocked($userId)) {
            throw new DefaultCurrencyLocked(__('problems.default_currency_locked'));
        }

        $changed = array_keys(array_filter(
            $values,
            fn (mixed $value, string $member): bool => $value !== $current->writableValues()[$member],
            ARRAY_FILTER_USE_BOTH,
        ));

        $startDayFrom = $values['budget_period_start_day'] === $current->budgetPeriodStartDay
            ? $current->budgetPeriodStartDayFrom
            : BudgetStartDay::appliesFrom($values['budget_period_start_day'], new DateTimeImmutable('now', new DateTimeZone($values['timezone'])));

        DB::transaction(function () use ($userId, $values, $version, $startDayFrom, $changed, $present): void {
            $written = $this->repository->saveIfVersion($userId, $version, [
                'display_name_ciphertext' => $values['display_name'] === null ? null : $this->cipher->seal($userId, $values['display_name']),
                'locale' => $values['locale'],
                'timezone' => $values['timezone'],
                'default_currency' => $values['default_currency'],
                'budget_period_start_day' => $values['budget_period_start_day'],
                'budget_period_start_day_from' => $startDayFrom,
            ]);

            if (! $written) {
                throw new ProfileVersionConflict($present($this->profiles->find($userId), $this->profiles->isCurrencyLocked($userId)));
            }

            event(new ProfileUpdated(...Events::stamp(), userId: $userId, changedFields: $changed));
        });

        return $this->profiles->find($userId);
    }
}
