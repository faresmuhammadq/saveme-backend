<?php

namespace App\Modules\Wallets\Infrastructure\Models;

use App\Modules\Wallets\Infrastructure\Database\Factories\CategoryFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A person's category or a platform one (ADR-0014): see the migration.
 *
 * @property string $id
 * @property string|null $user_id
 * @property string|null $system_key
 * @property string $kind
 * @property string|null $name
 * @property array{ar: string, en: string}|null $names
 * @property string|null $icon
 * @property string|null $color
 * @property int|null $position
 * @property CarbonImmutable|null $archived_at
 * @property int $version
 * @property int|null $change_seq
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    /** A person's id is the client's UUIDv7, a platform id is seeded; neither is generated here. */
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /** Instants keep microseconds. */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'names' => 'array',
            'position' => 'integer',
            'version' => 'integer',
            'change_seq' => 'integer',
            'archived_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): CategoryFactory
    {
        return CategoryFactory::new();
    }
}
