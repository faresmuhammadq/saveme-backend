<?php

namespace App\Modules\Identity\Application\Sync;

use App\Modules\Identity\Application\Dto\DeviceSessionView;
use App\Modules\Identity\Application\Profiles\Profiles;
use App\Modules\Identity\Domain\Ports\UserScope;

/**
 * Identity's side of GET /sync/user/changes (spec 013, R-8): the person's
 * profile and device sessions above a position, rendered as GET /me and
 * GET /auth/sessions render them, plus the user scope's counter and horizon.
 */
final class UserFeedQuery
{
    public function __construct(
        private readonly UserScope $scope,
        private readonly Profiles $profiles,
        private readonly FeedPresenter $presenter,
    ) {}

    /**
     * @return array{counter: int, purged: int}
     */
    public function scope(string $userId): array
    {
        return $this->scope->scopeOf($userId) ?? ['counter' => 0, 'purged' => 0];
    }

    /**
     * Rows above $after, in position order, at most $limit + 1.
     *
     * @return list<FeedRecord>
     */
    public function page(string $userId, string $readerSessionId, int $after, int $limit): array
    {
        $rows = [];

        $profile = $this->profiles->find($userId);
        if ($profile->changeSeq > $after) {
            $rows[] = new FeedRecord('profile', $profile->changeSeq, $this->presenter->profile($profile, $this->profiles->isCurrencyLocked($userId)));
        }

        foreach ($this->scope->sessionsChangedAfter($userId, $after, $limit + 1) as $session) {
            $rows[] = new FeedRecord('device_session', $session->changeSeq, $this->presenter->deviceSession(new DeviceSessionView($session, $session->id === $readerSessionId)));
        }

        usort($rows, fn (FeedRecord $a, FeedRecord $b): int => $a->position <=> $b->position);

        return array_slice($rows, 0, $limit + 1);
    }
}
