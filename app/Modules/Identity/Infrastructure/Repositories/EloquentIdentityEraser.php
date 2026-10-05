<?php

namespace App\Modules\Identity\Infrastructure\Repositories;

use App\Modules\Identity\Domain\Ports\ChangeSequence;
use App\Modules\Identity\Domain\Ports\IdentityEraser;
use App\Modules\Identity\Infrastructure\Models\DeviceSession;
use App\Modules\Identity\Infrastructure\Models\IdentityLink;
use App\Modules\Identity\Infrastructure\Models\Profile;
use App\Modules\Identity\Infrastructure\Models\SignInFailure;
use App\Modules\Identity\Infrastructure\Models\UserKey;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final class EloquentIdentityEraser implements IdentityEraser
{
    private const INSTANT = 'Y-m-d H:i:s.uP';

    public function __construct(private readonly ChangeSequence $sequence) {}

    public function eraseProfile(string $userId, DateTimeImmutable $at): void
    {
        $profile = Profile::query()->where('user_id', $userId)->first();

        if ($profile === null) {
            return;
        }

        $seq = $this->sequence->next($userId);

        Profile::query()->whereKey($profile->id)->toBase()->update([
            'deleted_at' => $at->format(self::INSTANT),
            'version' => $profile->version + 1,
            'change_seq' => $seq,
            'display_name_ciphertext' => null,
            'updated_at' => $at->format(self::INSTANT),
        ]);

        Profile::query()->whereKey($profile->id)->delete();

        DB::update('UPDATE users SET purged_change_seq = greatest(purged_change_seq, ?) WHERE id = ?', [$seq, $userId]);
    }

    public function eraseDependants(string $userId, string $lookup): void
    {
        DB::table('budget_start_days')->where('user_id', $userId)->delete();
        IdentityLink::query()->where('user_id', $userId)->delete();
        DeviceSession::query()->where('user_id', $userId)->delete();
        DB::table('sessions')->where('user_id', $userId)->delete();
        SignInFailure::query()->whereKey($lookup)->delete();
        UserKey::query()->where('user_id', $userId)->delete();
    }
}
