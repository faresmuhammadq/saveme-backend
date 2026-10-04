<?php

use App\Modules\Billing\Application\Claims\ExpireClaims;
use App\Modules\Billing\Application\Jobs\SendClaimExpiredMail;
use App\Modules\Billing\Infrastructure\Models\PaymentClaim;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

/*
 * The hourly sweep that closes unreviewed claims (spec 020, FR-016, SC-004).
 */

beforeEach(function () {
    $this->freezeTime();
    Queue::fake();
});

test('claims past their 7 days expire, are closed and each queue one notice', function () {
    $due = PaymentClaim::factory()->count(2)->create(['expires_at' => now()->subSecond()]);

    $expired = app(ExpireClaims::class)->run();

    expect($expired)->toBe(2);
    foreach ($due as $claim) {
        $claim->refresh();
        expect($claim->status)->toBe('expired')->and($claim->closed_at->equalTo(now()))->toBeTrue();
        Queue::assertPushed(SendClaimExpiredMail::class, fn (SendClaimExpiredMail $job): bool => $job->claimId === $claim->id);
    }
    Queue::assertPushed(SendClaimExpiredMail::class, 2);
});

test('claims not yet due and claims already reviewed are untouched', function () {
    $notDue = PaymentClaim::factory()->create(['expires_at' => now()->addSecond()]);
    $approved = PaymentClaim::factory()->approved()->create(['expires_at' => now()->subDay()]);
    $rejected = PaymentClaim::factory()->rejected()->create(['expires_at' => now()->subDay()]);

    expect(app(ExpireClaims::class)->run())->toBe(0)
        ->and($notDue->fresh()->status)->toBe('submitted')
        ->and($approved->fresh()->status)->toBe('approved')
        ->and($approved->fresh()->closed_at)->toBeNull()
        ->and($rejected->fresh()->status)->toBe('rejected');
    Queue::assertNothingPushed();
});

test('a second run changes nothing and queues nothing', function () {
    PaymentClaim::factory()->create(['expires_at' => now()->subHour()]);

    app(ExpireClaims::class)->run();
    $closedAt = PaymentClaim::query()->sole()->closed_at;
    $this->travel(5)->minutes();

    expect(app(ExpireClaims::class)->run())->toBe(0)
        ->and(PaymentClaim::query()->sole()->closed_at->equalTo($closedAt))->toBeTrue();
    Queue::assertPushed(SendClaimExpiredMail::class, 1);
});

test('batches of 200 keep going until every due claim is expired', function () {
    PaymentClaim::factory()->count(250)->create(['expires_at' => now()->subHour()]);

    expect(app(ExpireClaims::class)->run())->toBe(250)
        ->and(PaymentClaim::query()->where('status', 'expired')->count())->toBe(250);
    Queue::assertPushed(SendClaimExpiredMail::class, 250);
});

test('the command runs the sweep', function () {
    PaymentClaim::factory()->create(['expires_at' => now()->subHour()]);

    expect(Artisan::call('billing:expire-claims'))->toBe(0)
        ->and(Artisan::output())->toContain('1 claim(s) expired.');
});
