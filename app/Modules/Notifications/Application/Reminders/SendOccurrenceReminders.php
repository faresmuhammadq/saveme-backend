<?php

namespace App\Modules\Notifications\Application\Reminders;

use App\Modules\Identity\Application\Profiles\RecipientProfile;
use App\Modules\Notifications\Application\NotificationService;
use App\Modules\Notifications\Domain\Messages\MessageType;
use App\Modules\Notifications\Domain\Messages\NotificationDraft;
use App\Modules\Notifications\Domain\Ports\NotificationRepository;
use App\Modules\Wallets\Application\Recurring\Dto\OccurrenceReminder;
use App\Modules\Wallets\Application\Recurring\OccurrenceReminderQuery;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The hourly reminder pass (spec 023, FR-023, US3). It asks Wallets which
 * occurrences need someone now (due manual ones, refused automatic ones),
 * drops people whose local time is before 09:00, and for each person creates
 * one notification per occurrence not yet reminded, or, above the threshold
 * (3), one summary and a `summarised` marker under each occurrence's own key.
 * "Reminded" is the only thing stored; due-ness stays derived (ADR-0018). A
 * failure for one person is logged by exception class and the pass goes on
 * (FR-051). One counts-only line per run (FR-048).
 */
final class SendOccurrenceReminders
{
    private const PAGE = 500;

    public function __construct(
        private readonly OccurrenceReminderQuery $query,
        private readonly NotificationService $notifications,
        private readonly NotificationRepository $ledger,
        private readonly RecipientProfile $recipients,
    ) {}

    /**
     * @return array{created: int, summarised: int, skipped: int}
     */
    public function run(?DateTimeImmutable $now = null): array
    {
        $now ??= now()->toDateTimeImmutable();
        $counts = ['created' => 0, 'summarised' => 0, 'skipped' => 0];

        foreach ($this->gatherByRecipient($now) as $userId => $reminders) {
            try {
                $this->remind((string) $userId, $reminders, $now, $counts);
            } catch (Throwable $failure) {
                Log::warning('notifications_pass_item_failed', ['exception' => $failure::class]);
                Log::debug('notifications_pass_item_failed_ids', ['user_id' => $userId]);
            }
        }

        Log::info('notifications_pass', $counts);

        return $counts;
    }

    /**
     * @return array<string, list<array{occurrence_id: string, wallet_id: string, kind: string}>>
     */
    private function gatherByRecipient(DateTimeImmutable $now): array
    {
        $byRecipient = [];
        $after = null;

        do {
            $page = $this->query->needingAttention($now, $after, self::PAGE);

            foreach ($page as $reminder) {
                $this->spread($reminder, $byRecipient);
                $after = $reminder->occurrenceId;
            }
        } while (count($page) === self::PAGE);

        return $byRecipient;
    }

    /**
     * @param  array<string, list<array{occurrence_id: string, wallet_id: string, kind: string}>>  $byRecipient
     */
    private function spread(OccurrenceReminder $reminder, array &$byRecipient): void
    {
        foreach ($reminder->recipientUserIds as $userId) {
            $byRecipient[$userId][] = ['occurrence_id' => $reminder->occurrenceId, 'wallet_id' => $reminder->walletId, 'kind' => $reminder->kind];
        }
    }

    /**
     * @param  list<array{occurrence_id: string, wallet_id: string, kind: string}>  $reminders
     * @param  array{created: int, summarised: int, skipped: int}  $counts
     */
    private function remind(string $userId, array $reminders, DateTimeImmutable $now, array &$counts): void
    {
        $profile = $this->recipients->of($userId);
        $localHour = (int) $now->setTimezone(new DateTimeZone($profile->timezone))->format('G');

        if ($localHour < (int) config('notifications.reminder_hour')) {
            $counts['skipped']++;

            return;
        }

        $drafts = [];

        foreach ($reminders as $reminder) {
            $drafts[] = $this->draftFor($userId, $reminder);
        }

        $existing = $this->ledger->existingKeys($userId, array_map(fn (NotificationDraft $draft): string => $draft->dedupeKey, $drafts));
        $pending = array_values(array_filter($drafts, fn (NotificationDraft $draft): bool => ! in_array($draft->dedupeKey, $existing, true)));

        if ($pending === []) {
            return;
        }

        if (count($pending) <= (int) config('notifications.summary_threshold')) {
            foreach ($pending as $draft) {
                if ($this->notifications->create($draft) !== null) {
                    $counts['created']++;
                }
            }

            return;
        }

        $hour = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH');

        if ($this->notifications->create(new NotificationDraft($userId, MessageType::OccurrencesDueSummary, 'summary:'.$hour)) !== null) {
            $counts['created']++;
        }

        foreach ($pending as $draft) {
            if ($this->notifications->recordSummarised($draft)) {
                $counts['summarised']++;
            }
        }
    }

    /**
     * @param  array{occurrence_id: string, wallet_id: string, kind: string}  $reminder
     */
    private function draftFor(string $userId, array $reminder): NotificationDraft
    {
        $refused = $reminder['kind'] === 'refused';

        return new NotificationDraft(
            $userId,
            $refused ? MessageType::OccurrenceAutoConfirmRefused : MessageType::OccurrenceDue,
            ($refused ? 'occurrence_refused:' : 'occurrence:').$reminder['occurrence_id'],
            ['wallet_id' => $reminder['wallet_id'], 'occurrence_id' => $reminder['occurrence_id']],
        );
    }
}
