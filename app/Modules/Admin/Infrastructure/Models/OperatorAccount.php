<?php

namespace App\Modules\Admin\Infrastructure\Models;

use App\Modules\Admin\Infrastructure\Database\Factories\OperatorAccountFactory;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The Operator Account's persistence (glossary). Private to the Admin module.
 * It is the operator guard's user: the guard only identifies it, because
 * sign-in verifies the password and the second factor itself (R-5). Every
 * sealed and hashed column is hidden; nothing here decrypts.
 *
 * @property string $id
 * @property string $email_lookup
 * @property string $email_sealed
 * @property string $display_name_sealed
 * @property string $password_hash
 * @property string $state
 * @property string|null $secret_sealed
 * @property string|null $pending_secret_sealed
 * @property CarbonImmutable|null $pending_secret_created_at
 * @property int|null $last_code_step
 * @property CarbonImmutable|null $enrolled_at
 * @property int $credential_epoch
 * @property CarbonImmutable|null $password_changed_at
 * @property CarbonImmutable|null $temporary_password_expires_at
 * @property CarbonImmutable|null $last_signed_in_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Hidden(['email_lookup', 'email_sealed', 'display_name_sealed', 'password_hash', 'secret_sealed', 'pending_secret_sealed', 'last_code_step'])]
class OperatorAccount extends Model implements Authenticatable
{
    /** @use HasFactory<OperatorAccountFactory> */
    use AuthenticatableTrait, HasFactory, HasUuids;

    protected $guarded = [];

    /** Instants keep microseconds. */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pending_secret_created_at' => 'immutable_datetime',
            'enrolled_at' => 'immutable_datetime',
            'password_changed_at' => 'immutable_datetime',
            'temporary_password_expires_at' => 'immutable_datetime',
            'last_signed_in_at' => 'immutable_datetime',
            'credential_epoch' => 'integer',
            'last_code_step' => 'integer',
        ];
    }

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    /** "Remember me" is disabled: there is no column (R-5). */
    public function getRememberTokenName(): string
    {
        return '';
    }

    protected static function newFactory(): OperatorAccountFactory
    {
        return OperatorAccountFactory::new();
    }
}
