<?php

namespace App\Modules\Identity\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Database\Factories\DataExportFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Export (glossary): persistence of one requested copy. Private to Identity.
 *
 * @property string $id
 * @property string $user_id
 * @property string $scope
 * @property string|null $wallet_id
 * @property string $status
 * @property string|null $failure_code
 * @property string|null $file_path
 * @property int|null $size_bytes
 * @property Carbon $requested_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $delivered_at
 */
class DataExport extends Model
{
    /** @use HasFactory<DataExportFactory> */
    use HasFactory, HasUuids;

    protected $guarded = [];

    /** Instants keep microseconds, as the contract promises. */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requested_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'size_bytes' => 'integer',
        ];
    }

    protected static function newFactory(): DataExportFactory
    {
        return DataExportFactory::new();
    }
}
