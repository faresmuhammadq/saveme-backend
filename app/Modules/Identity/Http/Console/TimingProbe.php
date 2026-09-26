<?php

namespace App\Modules\Identity\Http\Console;

use App\Modules\Identity\Application\Registration\RegisterAccount;
use App\Modules\Identity\Application\SignIn\SignIn;
use App\Modules\Identity\Domain\Sessions\DeviceDescription;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * SC-003: a wrong password and an unknown address must take comparable time.
 * Times both at the deployment's real hash cost, inside a transaction that is
 * rolled back. Local only; never registered in production.
 */
#[Signature('identity:timing-probe {--trials=1000}')]
#[Description('Compare sign-in timing for a wrong password and an unknown address (local only)')]
class TimingProbe extends Command
{
    public function handle(RegisterAccount $register, SignIn $signIn): int
    {
        $trials = max(10, (int) $this->option('trials'));
        Queue::fake();
        DB::beginTransaction();

        try {
            $register->handle('probe@example.test', 'correct horse', null, null, 'en');
            config(['identity.lockout.account' => [['failures' => PHP_INT_MAX, 'minutes' => 1]], 'identity.lockout.origin.failures' => PHP_INT_MAX]);

            $wrong = $this->measure($trials, fn () => $signIn->handle('probe@example.test', 'not the password', $this->device(), '127.0.0.1'));
            $unknown = $this->measure($trials, fn () => $signIn->handle('nobody@example.test', 'not the password', $this->device(), '127.0.0.1'));
        } finally {
            DB::rollBack();
            RateLimiter::clear('identity-origin:127.0.0.1');
        }

        $difference = abs($wrong - $unknown) / max($wrong, $unknown);
        $this->table(['case', 'median ms'], [['wrong password', round($wrong, 2)], ['unknown address', round($unknown, 2)]]);
        $this->line(sprintf('Difference: %.1f%% (limit 10%%)', $difference * 100));

        return $difference < 0.10 ? self::SUCCESS : self::FAILURE;
    }

    private function measure(int $trials, callable $attempt): float
    {
        $samples = [];

        for ($i = 0; $i < $trials; $i++) {
            $start = hrtime(true);
            try {
                $attempt();
            } catch (Throwable) {
                // Every attempt is refused; only its duration matters.
            }
            $samples[] = (hrtime(true) - $start) / 1e6;
        }

        sort($samples);

        return $samples[intdiv(count($samples), 2)];
    }

    private function device(): DeviceDescription
    {
        return new DeviceDescription((string) Str::uuid7(), 'probe', 'Probe', 'android', '1.0.0');
    }
}
