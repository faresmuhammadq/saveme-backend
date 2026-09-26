<?php

namespace App\Modules\Identity\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Database\Factories\DeviceSessionFactory;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * A Device Session, which is also Sanctum's token record (spec 007, R-2): one
 * row, so revoking the session is revoking the credential. The id is the
 * client-generated session id; `token` is the SHA-256 of the secret.
 *
 * @property string $id
 * @property string $user_id
 * @property string $token
 * @property string $device_id
 * @property string $device_name
 * @property string $platform
 * @property string $app_version
 * @property string $state
 * @property string|null $end_reason
 * @property Carbon $signed_in_at
 * @property Carbon|null $last_used_at
 * @property Carbon|null $ended_at
 * @property int $version
 * @property int $change_seq
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 */
class DeviceSession extends PersonalAccessToken
{
    /** @use HasFactory<DeviceSessionFactory> */
    use HasFactory;

    protected $table = 'device_sessions';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /** Instants keep microseconds, as the syncable envelope promises. */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'abilities' => 'json',
        'signed_in_at' => 'datetime',
        'last_used_at' => 'datetime',
        'ended_at' => 'datetime',
        'expires_at' => 'datetime',
        'deleted_at' => 'datetime',
        'version' => 'integer',
        'change_seq' => 'integer',
    ];

    /**
     * Sanctum's credential lookup, guarded so a malformed id is simply "no
     * such credential" rather than a database error.
     *
     * @param  string  $token
     */
    public static function findToken($token): ?static
    {
        if (! str_contains($token, '|')) {
            return null;
        }

        [$id, $secret] = explode('|', $token, 2);

        if (! Str::isUuid($id)) {
            return null;
        }

        $session = static::query()->find($id);

        return $session !== null && hash_equals($session->token, hash('sha256', $secret)) ? $session : null;
    }

    /**
     * Sanctum resolves the authenticated user through `tokenable`.
     *
     * @return BelongsTo<User, $this>
     */
    public function tokenable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Active, and used (or signed in) within the inactivity window (R-3).
     */
    public function isUsable(CarbonInterface $now): bool
    {
        $lastActivity = $this->last_used_at ?? $this->signed_in_at;

        return $this->state === 'active'
            && $lastActivity->gt($now->copy()->subDays((int) config('identity.session_inactivity_days')));
    }

    protected static function newFactory(): DeviceSessionFactory
    {
        return DeviceSessionFactory::new();
    }
}
