<?php

namespace App\Modules\Identity\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * The User aggregate's persistence (glossary: credentials and lifecycle only).
 * Private to the Identity module: nothing outside Infrastructure uses it.
 *
 * @property string $id
 * @property string|null $email_ciphertext
 * @property string|null $email_lookup
 * @property string|null $password_hash
 * @property Carbon|null $email_verified_at
 * @property string $state
 * @property Carbon|null $deletion_requested_at
 * @property Carbon|null $deletion_scheduled_for
 * @property Carbon|null $deletion_reminded_at
 * @property Carbon|null $purged_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Hidden(['password_hash', 'email_ciphertext', 'email_lookup'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids;

    protected $guarded = [];

    /** Instants keep microseconds, as the syncable envelope promises. */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'deletion_requested_at' => 'immutable_datetime',
            'deletion_scheduled_for' => 'immutable_datetime',
            'deletion_reminded_at' => 'immutable_datetime',
            'purged_at' => 'immutable_datetime',
        ];
    }

    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function getRememberTokenName(): string
    {
        return '';
    }

    /**
     * @return HasOne<Profile, $this>
     */
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    /**
     * @return HasMany<DeviceSession, $this>
     */
    public function deviceSessions(): HasMany
    {
        return $this->hasMany(DeviceSession::class);
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
