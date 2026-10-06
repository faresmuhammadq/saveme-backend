<?php

namespace App\Modules\Notifications\Application;

use App\Modules\Notifications\Domain\Ports\RegistrationRepository;
use App\Modules\Notifications\Domain\Ports\TokenLookup;
use App\Modules\Notifications\Domain\Ports\TokenSealer;
use App\Modules\Notifications\Domain\Registration\PushRegistrationRecord;
use App\Modules\Notifications\Domain\Registration\SessionNotCurrent;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Where a person's phones can be reached (spec 023, US1): one registration per
 * device session, one per push token. A caller may only act on their own
 * current session; every other id is a 404. The token is sealed here and
 * nowhere else is it kept; it is never logged.
 */
final class DeviceService
{
    public function __construct(
        private readonly RegistrationRepository $registrations,
        private readonly TokenSealer $sealer,
        private readonly TokenLookup $lookup,
    ) {}

    /**
     * Registers, rotates or refreshes the push token of the caller's current
     * session. Always re-seals under the active key, so key rotation finishes
     * as phones refresh (FR-006).
     *
     * @throws SessionNotCurrent
     */
    public function register(string $userId, string $currentSessionId, string $pathId, #[\SensitiveParameter] string $token): PushRegistrationRecord
    {
        $this->requireCurrent($currentSessionId, $pathId);

        return $this->registrations->upsertForSession(
            $currentSessionId,
            $userId,
            $this->sealer->seal($token, $currentSessionId),
            $this->lookup->for($token),
            now()->toDateTimeImmutable(),
        );
    }

    /**
     * Removes the registration of the caller's current session; nothing to
     * remove is fine.
     *
     * @throws SessionNotCurrent
     */
    public function unregister(string $userId, string $currentSessionId, string $pathId): void
    {
        $this->requireCurrent($currentSessionId, $pathId);

        $this->registrations->deleteForSession($currentSessionId);
    }

    /**
     * The session ended, for any reason: its registration goes. Idempotent.
     */
    public function dropForSession(string $sessionId): void
    {
        $this->registrations->deleteForSession($sessionId);
    }

    /**
     * Deletes registrations not refreshed for the configured idle days (270).
     *
     * @return int how many
     */
    public function pruneIdle(DateTimeImmutable $now): int
    {
        $days = (int) config('notifications.registration_idle_days');
        $cut = $now->setTimezone(new DateTimeZone('UTC'))->modify("-{$days} days");

        return $this->registrations->deleteIdleBefore($cut);
    }

    /**
     * @throws SessionNotCurrent
     */
    private function requireCurrent(string $currentSessionId, string $pathId): void
    {
        if ($pathId !== $currentSessionId) {
            throw new SessionNotCurrent;
        }
    }
}
