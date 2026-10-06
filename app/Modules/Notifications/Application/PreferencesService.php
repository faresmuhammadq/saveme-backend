<?php

namespace App\Modules\Notifications\Application;

use App\Modules\Notifications\Domain\Ports\PreferencesRepository;
use App\Modules\Notifications\Domain\Preferences\Preferences;
use App\Modules\Notifications\Domain\Preferences\PreferencesVersionConflict;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Reads and saves a person's Notification Preferences (spec 023, US2, R-6).
 * A read never writes: before the first save the answer is the defaults at
 * version 1. A save carries the whole object and the last-seen version; a
 * stale version refuses it all with the stored preferences, an identical
 * save changes nothing, anything else moves the version up by one.
 */
final class PreferencesService
{
    public function __construct(private readonly PreferencesRepository $preferences) {}

    public function read(string $userId): Preferences
    {
        return $this->preferences->find($userId) ?? $this->defaults();
    }

    /**
     * What the policy applies: the saved preferences, or the defaults.
     */
    public function effective(string $userId): Preferences
    {
        return $this->read($userId);
    }

    public function defaults(): Preferences
    {
        /** @var array{enabled: bool, start: int, end: int} $quiet */
        $quiet = config('notifications.defaults.quiet_hours');

        return Preferences::defaults($quiet);
    }

    /**
     * @param  Preferences  $choices  the categories and quiet hours sent (version and time ignored)
     * @param  Closure(Preferences): array<string, mixed>  $present  how a read renders preferences, for a conflict's `current`
     *
     * @throws PreferencesVersionConflict
     */
    public function save(string $userId, Preferences $choices, int $version, Closure $present): Preferences
    {
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $result = DB::transaction(function () use ($userId, $choices, $version, $present): ?Preferences {
                $stored = $this->preferences->findForUpdate($userId);
                $current = $stored ?? $this->defaults();

                if ($version !== $current->version) {
                    throw new PreferencesVersionConflict($present($current));
                }

                if ($current->sameChoicesAs($choices)) {
                    return $current;
                }

                $next = $current->withChoices($choices, $current->version + 1, now()->toDateTimeImmutable());

                if ($stored === null) {
                    // The first save: version 1 is the unsaved defaults, so the row starts at 2.
                    return $this->preferences->insertIfAbsent($userId, $next) ? $next : null;
                }

                $this->preferences->update($userId, $next);

                return $next;
            });

            if ($result !== null) {
                return $this->read($userId);
            }
        }

        // Lost the race to another first save twice: judge against what it stored.
        throw new PreferencesVersionConflict($present($this->read($userId)));
    }
}
