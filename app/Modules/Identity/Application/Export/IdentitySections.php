<?php

namespace App\Modules\Identity\Application\Export;

use App\Modules\Identity\Application\Accounts;
use App\Modules\Identity\Application\Profiles\Profiles;
use App\Modules\Identity\Domain\Ports\BudgetStartDayHistory;
use App\Modules\Identity\Domain\Ports\SessionRepository;
use App\Modules\Identity\Domain\Ports\UserRepository;
use App\Modules\Identity\Domain\Sessions\SessionRecord;
use DateTimeImmutable;
use RuntimeException;

/**
 * Identity's own files of an account export (spec 022, FR-002): the account,
 * the profile with its budget start days, and the devices. Never a
 * credential, a hash or an installation identifier. A wallet export carries
 * none of them (FR-007).
 */
final class IdentitySections implements ExportSection
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly Accounts $accounts,
        private readonly Profiles $profiles,
        private readonly BudgetStartDayHistory $startDays,
        private readonly SessionRepository $sessions,
    ) {}

    public function write(ExportTarget $target, ExportWriter $writer): void
    {
        if (! $target->isAccount()) {
            return;
        }

        $userId = $target->userId;
        $user = $this->users->find($userId) ?? throw new RuntimeException('No such account.');

        $writer->append('account.json', [
            'email' => $this->accounts->address($userId),
            'email_verified_at' => self::instant($user->emailVerifiedAt),
            'created_at' => self::instant($this->users->createdAt($userId)),
            'state' => $user->state->value,
            'deletion_scheduled_for' => self::instant($user->deletionScheduledFor),
        ]);

        $profile = $this->profiles->find($userId);
        $writer->append('profile.json', [
            'display_name' => $profile->displayName,
            'locale' => $profile->locale,
            'timezone' => $profile->timezone,
            'default_currency' => $profile->defaultCurrency,
            'budget_period_start_day' => $profile->budgetPeriodStartDay,
            'budget_period_start_day_from' => $profile->budgetPeriodStartDayFrom,
            'budget_start_days' => $this->startDays->entriesOf($userId),
            'created_at' => self::instant($profile->createdAt),
            'updated_at' => self::instant($profile->updatedAt),
        ]);

        $writer->declare('devices.json');
        $after = null;

        do {
            $rows = $this->sessions->page($userId, null, 'signed_in_at', false, $after, 101);
            $page = array_slice($rows, 0, 100);

            foreach ($page as $session) {
                $writer->append('devices.json', $this->device($session));
            }

            $last = $page === [] ? null : $page[array_key_last($page)];
            $after = count($rows) > 100 && $last !== null ? ['value' => $last->signedInAt->format('Y-m-d\TH:i:s.uP'), 'id' => $last->id] : null;
        } while ($after !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function device(SessionRecord $session): array
    {
        return [
            'id' => $session->id,
            'name' => $session->deviceName,
            'platform' => $session->platform,
            'app_version' => $session->appVersion,
            'state' => $session->state->value,
            'signed_in_at' => self::instant($session->signedInAt),
            'last_used_at' => self::instant($session->lastUsedAt),
            'ended_at' => self::instant($session->endedAt),
        ];
    }

    private static function instant(?DateTimeImmutable $at): ?string
    {
        return $at?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
