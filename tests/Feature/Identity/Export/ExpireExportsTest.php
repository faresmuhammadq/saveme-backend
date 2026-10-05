<?php

use App\Modules\Identity\Application\Export\ExpireExports;
use App\Modules\Identity\Application\Export\GenerateExport;
use App\Modules\Identity\Infrastructure\Models\DataExport;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * The export housekeeping run (spec 022, FR-016, FR-019): archives live 24
 * hours, runs stuck for an hour fail, records go after 30 days, stray files
 * go at once.
 */

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    $this->freezeTime();
    $this->user = person();
});

function readyExport(string $userId, array $attributes = []): DataExport
{
    $export = DataExport::factory()->create(['user_id' => $userId, ...$attributes]);
    app(GenerateExport::class)->run($export->id);

    return $export->refresh();
}

test('a ready export past its expiry loses its file and becomes expired', function () {
    $export = readyExport($this->user->id);
    $path = $export->file_path;

    $this->travel(23)->hours();
    app(ExpireExports::class)->run();
    expect(DataExport::query()->find($export->id)->status)->toBe('ready')->and(Storage::disk('local')->exists($path))->toBeTrue();

    $this->travel(2)->hours();
    $done = app(ExpireExports::class)->run();

    $expired = DataExport::query()->find($export->id);
    expect($done['expired'])->toBe(1)
        ->and($expired->status)->toBe('expired')
        ->and($expired->file_path)->toBeNull()
        ->and(Storage::disk('local')->exists($path))->toBeFalse();
});

test('a queued or running export older than an hour fails as timed out, and a younger one is left', function () {
    $stale = DataExport::factory()->create(['user_id' => $this->user->id, 'status' => 'running', 'requested_at' => now()->subMinutes(61)]);
    $other = person('other@example.test');
    $young = DataExport::factory()->create(['user_id' => $other->id, 'requested_at' => now()->subMinutes(59)]);
    $part = Storage::disk('local')->put("exports/{$this->user->id}/{$stale->id}.zip.part", 'partial');

    $done = app(ExpireExports::class)->run();

    $failed = DataExport::query()->find($stale->id);
    expect($done['timed_out'])->toBe(1)
        ->and($failed->status)->toBe('failed')->and($failed->failure_code)->toBe('timed_out')
        ->and(Storage::disk('local')->exists("exports/{$this->user->id}/{$stale->id}.zip.part"))->toBeFalse()
        ->and(DataExport::query()->find($young->id)->status)->toBe('queued');
});

test('records older than 30 days are deleted with their files, newer ones stay', function () {
    $old = readyExport($this->user->id);
    DataExport::query()->whereKey($old->id)->update(['requested_at' => now()->subDays(31), 'status' => 'expired']);
    $recent = DataExport::factory()->failed()->create(['user_id' => $this->user->id, 'requested_at' => now()->subDays(29)]);

    $done = app(ExpireExports::class)->run();

    expect($done['deleted'])->toBe(1)
        ->and(DataExport::query()->find($old->id))->toBeNull()
        ->and(DataExport::query()->find($recent->id))->not->toBeNull()
        ->and(Storage::disk('local')->allFiles('exports'))->toBe([]);
});

test('files no row refers to are deleted, and a part file of a running export is kept', function () {
    $orphan = (string) Str::uuid7();
    Storage::disk('local')->put("exports/{$this->user->id}/{$orphan}.zip", 'x');
    $running = DataExport::factory()->create(['user_id' => $this->user->id, 'status' => 'running']);
    Storage::disk('local')->put("exports/{$this->user->id}/{$running->id}.zip.part", 'building');

    $done = app(ExpireExports::class)->run();

    expect($done['orphans'])->toBe(1)
        ->and(Storage::disk('local')->exists("exports/{$this->user->id}/{$orphan}.zip"))->toBeFalse()
        ->and(Storage::disk('local')->exists("exports/{$this->user->id}/{$running->id}.zip.part"))->toBeTrue();
});

test('the run is idempotent and the command and schedule exist', function () {
    $export = readyExport($this->user->id);
    $this->travel(25)->hours();

    app(ExpireExports::class)->run();
    $again = app(ExpireExports::class)->run();

    expect($again)->toBe(['expired' => 0, 'timed_out' => 0, 'deleted' => 0, 'orphans' => 0])
        ->and(Artisan::call('identity:expire-exports'))->toBe(0)
        ->and(collect(app(Schedule::class)->events())->contains(fn ($event): bool => str_contains($event->command, 'identity:expire-exports')))->toBeTrue();
});
