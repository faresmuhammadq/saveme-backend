<?php

use App\Modules\Billing\Application\Jobs\SendClaimApprovedMail;
use App\Modules\Billing\Application\Jobs\SendClaimExpiredMail;
use App\Modules\Billing\Application\Jobs\SendClaimRejectedMail;
use App\Modules\Billing\Application\Jobs\SendRenewalReminderMail;
use App\Modules\Billing\Application\Mail\ClaimApprovedMail;
use App\Modules\Billing\Infrastructure\Models\PaymentClaim;
use App\Modules\Identity\Application\Billing\ClaimAddresses;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * The privacy sweep (spec 020, FR-040, SC-005; P-15 rule 4): across submission,
 * approval, the mail job, redemption and every scheduled command, no receipt,
 * no redeem code and no claim address is written to a log line, an audit
 * entry or a queue payload in clear.
 */

beforeEach(function () {
    $this->freezeTime();
    Storage::fake('local');
    $this->logged = [];
    Log::listen(function (MessageLogged $event): void {
        $this->logged[] = $event->message.' '.json_encode($event->context);
    });
});

test('nothing a person or an operator gave reaches a log line, an audit entry or a queued payload', function () {
    Queue::fake();
    Mail::fake();
    $address = 'mona.private@example.test';
    $receipt = receiptBase64('png');
    $lookup = app(ClaimAddresses::class)->normaliseAndLookup($address)['lookup'];

    $submitted = $this->withHeaders(claimHeaders())
        ->postJson('/api/v1/billing/instapay/claims', claimBody(['email' => $address, 'screenshot' => ['media_type' => 'image/png', 'data' => $receipt]]))
        ->assertStatus(202);
    $claimId = $submitted->json('data.id');

    $operator = makeOperator()['operator'];
    Artisan::call('billing:claim:approve', ['claim' => $claimId, '--operator' => $operator->id]);

    $job = Queue::pushed(SendClaimApprovedMail::class)->sole();
    $code = $job->code;
    app()->call([$job, 'handle']);
    Mail::assertSent(ClaimApprovedMail::class, 1);

    person($address);
    $credential = signInAs($address)->json('data.credential');
    $this->withHeaders([...asCredential($credential), 'Idempotency-Key' => 'redeem-logging-0000001'])
        ->postJson('/api/v1/billing/redeem', ['code' => strtolower($code)])
        ->assertOk();

    foreach (['billing:expire-claims', 'billing:expire-redeem-codes', 'billing:send-renewal-reminders', 'billing:purge-claims', 'billing:purge-idempotency-records', 'billing:claim:list'] as $command) {
        Artisan::call($command);
    }

    $written = implode("\n", [
        ...$this->logged,
        DB::table('audit_entries')->get()->toJson(),
        DB::table('idempotency_records')->get()->toJson(),
        DB::table('payment_claims')->get(['id', 'reference', 'status', 'review_note', 'receipt_path', 'reject_reason'])->toJson(),
    ]);

    expect($this->logged)->not->toBe([]);

    foreach ([$receipt, substr($receipt, 0, 40), $code, str_replace('-', '', $code), strtolower($code), $address, 'mona.private', $lookup] as $secret) {
        expect($written)->not->toContain($secret);
    }

    $claim = PaymentClaim::query()->findOrFail($claimId);
    expect($written)->not->toContain($claim->address_sealed);
});

test('a failing mail job logs a warning without the code or the address', function () {
    $claim = PaymentClaim::factory()->forAddress('leaky.person@example.test')->create();
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp refused leaky.person@example.test with 7KQ2-MX4D-9TRB-H3WE'));

    $job = new SendClaimApprovedMail($claim->id, '7KQ2-MX4D-9TRB-H3WE', '2026-11-04');
    try {
        app()->call([$job, 'handle']);
    } catch (RuntimeException $exception) {
        $job->failed($exception);
    }

    $text = implode("\n", $this->logged);
    expect($text)->toContain('billing_claim_approved_mail_failed')->not->toContain('leaky.person')->not->toContain('7KQ2')->not->toContain('MX4D');
});

test('the queued payload of every job that carries a code or an address is encrypted', function () {
    config(['queue.default' => 'database']);
    $claim = PaymentClaim::factory()->forAddress('queued.person@example.test')->create();

    foreach ([
        new SendClaimApprovedMail($claim->id, '7KQ2-MX4D-9TRB-H3WE', '2026-11-04'),
        new SendClaimRejectedMail($claim->id),
        new SendClaimExpiredMail($claim->id),
        new SendRenewalReminderMail($claim->id, $claim->id),
    ] as $job) {
        expect($job)->toBeInstanceOf(ShouldBeEncrypted::class);
        app('queue')->connection('database')->push($job);
    }

    $payloads = DB::table('jobs')->pluck('payload')->implode("\n");
    expect(DB::table('jobs')->count())->toBe(4)
        ->and($payloads)->not->toContain('7KQ2-MX4D-9TRB-H3WE')->not->toContain('7KQ2')->not->toContain($claim->id)->not->toContain('queued.person');
});
