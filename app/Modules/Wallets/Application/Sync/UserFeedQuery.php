<?php

namespace App\Modules\Wallets\Application\Sync;

use App\Modules\Identity\Application\Profiles\DisplayNames;
use App\Modules\Wallets\Application\Categories\CategoryUsageQuery;
use App\Modules\Wallets\Application\Categories\Dto\CategoryView;
use App\Modules\Wallets\Application\Dto\MemberView;
use App\Modules\Wallets\Domain\Ports\SyncFeedReader;

/**
 * Wallets' side of GET /sync/user/changes (spec 013, R-8): the person's own
 * categories by `change_seq` and their own memberships by `member_change_seq`
 * (ADR-0017), rendered as their reads render them. Both positions come from
 * the person's user counter.
 */
final class UserFeedQuery
{
    public function __construct(
        private readonly SyncFeedReader $feed,
        private readonly CategoryUsageQuery $usage,
        private readonly DisplayNames $displayNames,
        private readonly FeedPresenter $presenter,
    ) {}

    /**
     * Rows above $after, in position order, at most $limit + 1.
     *
     * @return list<FeedRecord>
     */
    public function page(string $userId, int $after, int $limit): array
    {
        $rows = [];

        $categories = $this->feed->categoriesChangedFor($userId, $after, $limit + 1);
        $used = $this->usage->usedAmong(array_map(fn ($category): string => $category->id, $categories));
        foreach ($categories as $category) {
            $rows[] = new FeedRecord('category', $category->changeSeq, $this->presenter->category(new CategoryView($category, $used[$category->id] ?? false)));
        }

        $memberships = $this->feed->membershipsChangedFor($userId, $after, $limit + 1);
        $names = $this->displayNames->of(array_values(array_unique(array_map(fn ($member): string => $member->userId, $memberships))));
        foreach ($memberships as $member) {
            $rows[] = new FeedRecord('wallet_member', $member->memberChangeSeq, $this->presenter->member(new MemberView($member, $names[$member->userId] ?? null)));
        }

        usort($rows, fn (FeedRecord $a, FeedRecord $b): int => $a->position <=> $b->position);

        return array_slice($rows, 0, $limit + 1);
    }
}
