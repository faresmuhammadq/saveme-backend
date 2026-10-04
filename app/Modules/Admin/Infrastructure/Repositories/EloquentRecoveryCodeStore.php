<?php

namespace App\Modules\Admin\Infrastructure\Repositories;

use App\Modules\Admin\Domain\Ports\RecoveryCodeStore;
use App\Modules\Admin\Infrastructure\Models\OperatorRecoveryCode;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

final class EloquentRecoveryCodeStore implements RecoveryCodeStore
{
    public function replaceSet(string $operatorId, array $hashes): void
    {
        $this->deleteAll($operatorId);

        $setId = (string) Str::uuid7();

        foreach ($hashes as $hash) {
            OperatorRecoveryCode::query()->create([
                'operator_id' => $operatorId,
                'set_id' => $setId,
                'code_hash' => $hash,
            ]);
        }
    }

    public function deleteAll(string $operatorId): void
    {
        OperatorRecoveryCode::query()->where('operator_id', $operatorId)->delete();
    }

    public function consumeMatching(string $operatorId, string $hash): bool
    {
        $matched = null;

        /** @var Collection<int, OperatorRecoveryCode> $unused */
        $unused = OperatorRecoveryCode::query()->where('operator_id', $operatorId)->whereNull('used_at')->get();

        foreach ($unused as $code) {
            if (hash_equals($code->code_hash, $hash)) {
                $matched = $code;
            }
        }

        if ($matched === null) {
            return false;
        }

        $matched->forceFill(['used_at' => now()])->save();

        return true;
    }

    public function unusedCount(string $operatorId): int
    {
        return OperatorRecoveryCode::query()->where('operator_id', $operatorId)->whereNull('used_at')->count();
    }
}
