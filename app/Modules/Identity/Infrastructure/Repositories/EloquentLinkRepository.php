<?php

namespace App\Modules\Identity\Infrastructure\Repositories;

use App\Modules\Identity\Domain\Account\LinkKind;
use App\Modules\Identity\Domain\Ports\LinkRepository;
use App\Modules\Identity\Infrastructure\Models\IdentityLink;
use Illuminate\Support\Facades\DB;

final class EloquentLinkRepository implements LinkRepository
{
    public function issue(string $userId, LinkKind $kind): string
    {
        $secret = $this->newSecret();

        DB::transaction(function () use ($userId, $kind, $secret): void {
            $this->lockLinksOf($userId, $kind);
            $this->supersedeAll($userId, $kind);
            $this->store($userId, $kind, $secret);
        });

        return $secret;
    }

    public function issueFirst(string $userId, LinkKind $kind): ?string
    {
        return DB::transaction(function () use ($userId, $kind): ?string {
            $this->lockLinksOf($userId, $kind);

            if (IdentityLink::query()->where('user_id', $userId)->where('kind', $kind->value)->exists()) {
                return null;
            }

            $secret = $this->newSecret();
            $this->store($userId, $kind, $secret);

            return $secret;
        });
    }

    public function redeem(string $secret, LinkKind $kind): ?string
    {
        // One statement, so two concurrent clicks cannot both succeed. The
        // application clock is passed in, so expiry follows one notion of now.
        $now = now()->format('Y-m-d H:i:s.uP');
        $row = DB::selectOne(
            'update identity_links set used_at = ?
             where secret_hash = ? and kind = ? and used_at is null and superseded_at is null and expires_at > ?
             returning user_id',
            [$now, hash('sha256', $secret), $kind->value, $now],
        );

        return $row === null ? null : (string) $row->user_id;
    }

    private function newSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function store(string $userId, LinkKind $kind, string $secret): void
    {
        IdentityLink::query()->create([
            'user_id' => $userId,
            'kind' => $kind->value,
            'secret_hash' => hash('sha256', $secret),
            'expires_at' => now()->addMinutes($kind->lifetimeMinutes())->format('Y-m-d H:i:s.uP'),
        ]);
    }

    /**
     * Serialises every issue for one person and kind until the surrounding
     * transaction ends, so the newest request always ends up the live link.
     */
    private function lockLinksOf(string $userId, LinkKind $kind): void
    {
        DB::select('select pg_advisory_xact_lock(hashtext(?))', ['identity_links:'.$kind->value.':'.$userId]);
    }

    public function supersedeAll(string $userId, LinkKind $kind): void
    {
        IdentityLink::query()
            ->where('user_id', $userId)
            ->where('kind', $kind->value)
            ->whereNull('used_at')
            ->whereNull('superseded_at')
            ->update(['superseded_at' => now()->format('Y-m-d H:i:s.uP')]);
    }
}
