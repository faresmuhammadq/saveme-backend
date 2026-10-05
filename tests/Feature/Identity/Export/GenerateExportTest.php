<?php

use App\Modules\Identity\Application\Export\ExportSection;
use App\Modules\Identity\Application\Export\ExportTarget;
use App\Modules\Identity\Application\Export\ExportWriter;
use App\Modules\Identity\Application\Export\GenerateExport;
use App\Modules\Identity\Application\Export\WalletUnavailable;
use App\Modules\Identity\Application\Jobs\GenerateExportJob;
use App\Modules\Identity\Application\Jobs\SendExportReadyMail;
use App\Modules\Identity\Application\Mail\ExportReadyMail;
use App\Modules\Identity\Domain\Ports\ExportRepository;
use App\Modules\Identity\Domain\Ports\ExportStore;
use App\Modules\Identity\Domain\Ports\UserRepository;
use App\Modules\Identity\Infrastructure\Models\DataExport;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * Producing the archive (spec 022, FR-011, FR-012, FR-015, R-5).
 */

beforeEach(function () {
    Storage::fake('local');
    $this->freezeTime();
    $this->world = exportWorld();
    $this->user = $this->world['d'];
});

/**
 * Binds the generator with the given sections instead of the tagged ones.
 *
 * @param  list<ExportSection>  $sections
 */
function generatorWith(array $sections): void
{
    app()->bind(GenerateExport::class, fn () => new GenerateExport(app(ExportRepository::class), app(ExportStore::class), app(UserRepository::class), $sections));
}

test('a queued export becomes ready with its size, instants and a file at the server-chosen path', function () {
    Queue::fake();
    $export = DataExport::factory()->create(['user_id' => $this->user->id]);

    app(GenerateExport::class)->run($export->id);

    $export->refresh();
    $path = "exports/{$this->user->id}/{$export->id}.zip";
    expect($export->status)->toBe('ready')
        ->and($export->file_path)->toBe($path)
        ->and($export->size_bytes)->toBe(Storage::disk('local')->size($path))
        ->and($export->started_at)->not->toBeNull()
        ->and($export->completed_at->equalTo(now()))->toBeTrue()
        ->and($export->expires_at->equalTo(now()->addHours(24)))->toBeTrue()
        ->and(Storage::disk('local')->exists($path.'.part'))->toBeFalse();
    Queue::assertPushed(SendExportReadyMail::class, 1);
});

test('the ready mail is sent once, in the profile language, with a link that works until the archive expires', function () {
    Mail::fake();
    $arabic = namedPerson('arabic@example.test', null, 'ar');
    $export = DataExport::factory()->create(['user_id' => $arabic->id]);

    app(GenerateExport::class)->run($export->id);

    Mail::assertSent(ExportReadyMail::class, 1);
    Mail::assertSent(ExportReadyMail::class, function (ExportReadyMail $mail) use ($export): bool {
        parse_str((string) parse_url($mail->link, PHP_URL_QUERY), $query);

        return $mail->hasTo('arabic@example.test')
            && $mail->locale === 'ar'
            && str_contains($mail->link, "/exports/{$export->id}/file")
            && (int) $query['expires'] === $export->refresh()->expires_at->getTimestamp()
            && ! str_contains($mail->render(), 'Family');
    });
});

test('the ready-mail job is encrypted at rest', function () {
    expect(new SendExportReadyMail('x'))->toBeInstanceOf(ShouldBeEncrypted::class);
});

test('a section that throws leaves nothing behind, and after the last attempt the export is failed with no mail', function () {
    Queue::fake();
    Mail::fake();
    $export = DataExport::factory()->create(['user_id' => $this->user->id]);
    generatorWith([new class implements ExportSection
    {
        public function write(ExportTarget $target, ExportWriter $writer): void
        {
            $writer->append('wallets.json', ['id' => 'half-written']);

            throw new RuntimeException('boom');
        }
    }]);

    $job = new GenerateExportJob($export->id);
    expect(fn () => $job->handle(app(GenerateExport::class)))->toThrow(RuntimeException::class);

    expect($job->tries)->toBe(3)->and($job->timeout)->toBe(900)->and($job->backoff())->toBe([30, 120])
        ->and(DataExport::query()->find($export->id)->status)->toBe('running');
    expect(Storage::disk('local')->allFiles('exports'))->toBe([]);

    $job->failed(new RuntimeException('boom'));

    $failed = DataExport::query()->find($export->id);
    expect($failed->status)->toBe('failed')->and($failed->failure_code)->toBe('generation_failed')
        ->and(Storage::disk('local')->allFiles('exports'))->toBe([]);
    Queue::assertNotPushed(SendExportReadyMail::class);
    Mail::assertNothingSent();
});

test('a retry rebuilds from scratch under the same name', function () {
    Queue::fake();
    $export = DataExport::factory()->create(['user_id' => $this->user->id]);
    $calls = 0;
    generatorWith([new class($calls) implements ExportSection
    {
        public function __construct(private int &$calls) {}

        public function write(ExportTarget $target, ExportWriter $writer): void
        {
            $this->calls++;
            $writer->append('wallets.json', ['attempt' => $this->calls]);

            if ($this->calls === 1) {
                throw new RuntimeException('first attempt fails');
            }
        }
    }]);

    expect(fn () => (new GenerateExportJob($export->id))->handle(app(GenerateExport::class)))->toThrow(RuntimeException::class);
    (new GenerateExportJob($export->id))->handle(app(GenerateExport::class));

    $archive = openExportArchive($this->user->id, $export->id);
    expect(DataExport::query()->find($export->id)->status)->toBe('ready')
        ->and($archive['json']['wallets.json'])->toBe([['attempt' => 2]]);
});

test('running a ready export again changes nothing and sends nothing', function () {
    Queue::fake();
    $export = DataExport::factory()->create(['user_id' => $this->user->id]);
    app(GenerateExport::class)->run($export->id);
    $first = DataExport::query()->find($export->id);

    $this->travel(5)->minutes();
    app(GenerateExport::class)->run($export->id);

    $second = DataExport::query()->find($export->id);
    expect($second->completed_at->equalTo($first->completed_at))->toBeTrue()
        ->and($second->size_bytes)->toBe($first->size_bytes);
    Queue::assertPushed(SendExportReadyMail::class, 1);
});

test('a run whose row was deleted meanwhile, or whose account began purging, leaves no file', function () {
    Queue::fake();
    $gone = DataExport::factory()->create(['user_id' => $this->user->id]);
    generatorWith([new class($gone->id) implements ExportSection
    {
        public function __construct(private string $id) {}

        public function write(ExportTarget $target, ExportWriter $writer): void
        {
            DataExport::query()->whereKey($this->id)->delete();
        }
    }]);
    app(GenerateExport::class)->run($gone->id);
    expect(Storage::disk('local')->allFiles('exports'))->toBe([]);

    $leaving = DataExport::factory()->create(['user_id' => $this->world['p']->id]);
    $userId = $this->world['p']->id;
    generatorWith([new class($userId) implements ExportSection
    {
        public function __construct(private string $userId) {}

        public function write(ExportTarget $target, ExportWriter $writer): void
        {
            User::query()->whereKey($this->userId)->update(['state' => 'purging', 'deletion_requested_at' => now(), 'deletion_scheduled_for' => now()]);
        }
    }]);
    app(GenerateExport::class)->run($leaving->id);

    expect(Storage::disk('local')->allFiles('exports'))->toBe([])
        ->and(DataExport::query()->find($leaving->id)->status)->not->toBe('ready');
    Queue::assertNotPushed(SendExportReadyMail::class);
});

test('a run for an account that is already purging does not even start', function () {
    Queue::fake();
    $export = DataExport::factory()->create(['user_id' => $this->world['q']->id]);
    User::query()->whereKey($this->world['q']->id)->update(['state' => 'purging', 'deletion_requested_at' => now(), 'deletion_scheduled_for' => now()]);

    app(GenerateExport::class)->run($export->id);

    expect(Storage::disk('local')->allFiles('exports'))->toBe([])
        ->and(DataExport::query()->find($export->id)->status)->toBe('queued');
});

test('a wallet that is no longer readable fails the export at once without a retry', function () {
    Queue::fake();
    $export = DataExport::factory()->create(['user_id' => $this->user->id, 'scope' => 'wallet', 'wallet_id' => $this->world['l']->id]);

    expect(fn () => app(GenerateExport::class)->run($export->id))->toThrow(WalletUnavailable::class);

    (new GenerateExportJob($export->id))->handle(app(GenerateExport::class));

    $failed = DataExport::query()->find($export->id);
    expect($failed->status)->toBe('failed')->and($failed->failure_code)->toBe('wallet_unavailable')
        ->and(Storage::disk('local')->allFiles('exports'))->toBe([]);
    Queue::assertNotPushed(SendExportReadyMail::class);
});

test('nothing logged by a run carries content', function () {
    Queue::fake();
    $records = [];
    Event::listen(MessageLogged::class, function (MessageLogged $log) use (&$records): void {
        $records[] = $log->message.' '.json_encode($log->context);
    });
    $export = DataExport::factory()->create(['user_id' => $this->user->id]);

    app(GenerateExport::class)->run($export->id);

    $logged = implode("\n", $records);
    expect($logged)->toContain($export->id);
    foreach (['Ada', 'Lovelace', 'ada@example.test', 'Family', 'Pat', 'lunch', '2500'] as $content) {
        expect($logged)->not->toContain($content);
    }
});
