<?php

use App\Modules\Identity\Application\Export\GenerateExport;
use App\Modules\Identity\Infrastructure\Models\AuditEntry;
use App\Modules\Identity\Infrastructure\Models\DataExport;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Reading a ready export and following its signed link (spec 022, FR-013,
 * FR-014, FR-018): no bearer, no client headers, 404 for everything wrong.
 */

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    $this->freezeTime();
    $this->user = person();
    $this->credential = signInAs()->json('data.credential');
    $this->export = DataExport::factory()->create(['user_id' => $this->user->id]);
    app(GenerateExport::class)->run($this->export->id);
    $this->link = fn (): string => exportCall('GET', $this->credential, '/exports/'.$this->export->id)->json('data.download_url');
});

test('a ready export is read with a link expiring in 15 minutes, and following it needs no header at all', function () {
    $read = exportCall('GET', $this->credential, '/exports/'.$this->export->id)->assertOk();

    expect($read->json('data.status'))->toBe('ready')
        ->and($read->json('data.download_url_expires_at'))->toBe(now()->addMinutes(15)->utc()->format('Y-m-d\TH:i:s.u\Z'))
        ->and($read->json('data.expires_at'))->toBe(now()->addHours(24)->utc()->format('Y-m-d\TH:i:s.u\Z'));

    $download = $this->get($read->json('data.download_url'));

    $download->assertOk()->assertHeader('Content-Type', 'application/zip')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($download->headers->get('Content-Disposition'))->toBe('attachment; filename=export-'.now()->format('Y-m-d').'.zip')
        ->and($download->headers->get('Cache-Control'))->toContain('no-store');
});

test('a link read near expiry is never valid past the archive\'s own expiry', function () {
    $this->travel(23 * 60 + 55)->minutes();

    $read = exportCall('GET', $this->credential, '/exports/'.$this->export->id)->assertOk();

    expect($read->json('data.download_url_expires_at'))->toBe($read->json('data.expires_at'));
});

test('only the first successful download is audited', function () {
    $link = ($this->link)();

    $this->get($link)->assertOk();
    $this->get($link)->assertOk();

    $entries = AuditEntry::query()->where('event', 'export_delivered')->get();
    expect($entries)->toHaveCount(1)
        ->and($entries[0]->detail)->toBe(['export_id' => $this->export->id])
        ->and($entries[0]->actor_id)->toBe($this->user->id)
        ->and(DataExport::query()->find($this->export->id)->delivered_at)->not->toBeNull();
});

test('a tampered or missing signature, an expired link and an unknown export are all 404, nothing recorded', function () {
    $link = ($this->link)();

    $this->get(preg_replace('/signature=[0-9a-f]+/', 'signature=deadbeef', $link))->assertNotFound();
    $this->get(strtok($link, '?'))->assertNotFound();
    $this->get(str_replace($this->export->id, (string) Str::uuid7(), $link))->assertNotFound();

    $this->travel(16)->minutes();
    $this->get($link)->assertNotFound();

    expect(AuditEntry::query()->where('event', 'export_delivered')->count())->toBe(0);
});

test('an expired export, a missing file and a departed account are 404', function () {
    $link = ($this->link)();

    Storage::disk('local')->delete($this->export->refresh()->file_path);
    $this->get($link)->assertNotFound();
    expect(AuditEntry::query()->where('event', 'export_delivered')->count())->toBe(0);

    $other = DataExport::factory()->create(['user_id' => person('o@example.test')->id]);
    app(GenerateExport::class)->run($other->id);
    $otherLink = exportCall('GET', signInAs('o@example.test')->json('data.credential'), '/exports/'.$other->id)->json('data.download_url');
    DataExport::query()->whereKey($other->id)->update(['status' => 'expired']);
    $this->get($otherLink)->assertNotFound();
});

test('no download while the account is purging or purged', function () {
    $link = ($this->link)();

    User::query()->whereKey($this->user->id)->update(['state' => 'purging', 'deletion_requested_at' => now(), 'deletion_scheduled_for' => now()]);
    $this->get($link)->assertNotFound();

    User::query()->whereKey($this->user->id)->update(['state' => 'purged', 'purged_at' => now(), 'deletion_requested_at' => null, 'deletion_scheduled_for' => null]);
    $this->get($link)->assertNotFound();

    expect(AuditEntry::query()->where('event', 'export_delivered')->count())->toBe(0);
});

test('a grace-period account may still download', function () {
    $link = ($this->link)();
    User::query()->whereKey($this->user->id)->update(['state' => 'pending_deletion', 'deletion_requested_at' => now(), 'deletion_scheduled_for' => now()->addDays(30)]);

    $this->get($link)->assertOk();
});

test('another person\'s export is 404 on the API, and the file route budget is 20 per 10 minutes per origin', function () {
    $stranger = person('stranger@example.test');
    $credential = signInAs('stranger@example.test')->json('data.credential');
    exportCall('GET', $credential, '/exports/'.$this->export->id)->assertStatus(404);

    $link = ($this->link)();
    RateLimiter::clear('export-file:127.0.0.1');

    foreach (range(1, 20) as $attempt) {
        $this->get($link)->assertOk();
    }

    $this->get($link)->assertStatus(429);
});
