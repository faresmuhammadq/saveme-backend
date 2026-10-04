<?php

use App\Modules\Billing\Application\Jobs\SendClaimApprovedMail;
use App\Modules\Billing\Application\Jobs\SendClaimExpiredMail;
use App\Modules\Billing\Application\Jobs\SendClaimRejectedMail;
use App\Modules\Billing\Application\Mail\ClaimApprovedMail;
use App\Modules\Billing\Application\Mail\ClaimExpiredMail;
use App\Modules\Billing\Application\Mail\ClaimRejectedMail;
use App\Modules\Billing\Infrastructure\Models\PaymentClaim;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Mail;

/*
 * The claim mails (spec 020, FR-016 to FR-018, R-13; P-14).
 */

beforeEach(function () {
    $this->freezeTime();
    Mail::fake();
});

function runClaimJob(object $job): void
{
    app()->call([$job, 'handle']);
}

test('every billing string exists in both languages', function () {
    $en = require base_path('lang/en/billing.php');
    $ar = require base_path('lang/ar/billing.php');

    expect(array_keys(Arr::dot($ar)))->toBe(array_keys(Arr::dot($en)));
});

test('the approval mail carries the grouped code, the plan, how to enter it, the address rule, the last day and the contact', function () {
    config(['billing.instapay.support_contact' => 'help@saveme.test', 'app.name' => 'Save Me']);
    $claim = PaymentClaim::factory()->forAddress('mona@example.test')->create();

    runClaimJob(new SendClaimApprovedMail($claim->id, '7KQ2-MX4D-9TRB-H3WE', '2026-11-04'));

    Mail::assertSent(ClaimApprovedMail::class, 1);
    Mail::assertSent(ClaimApprovedMail::class, function (ClaimApprovedMail $mail): bool {
        $html = $mail->render();

        return $mail->hasTo('mona@example.test')
            && $mail->locale === 'en'
            && str_contains($html, '7KQ2-MX4D-9TRB-H3WE')
            && str_contains($html, 'Plus · Yearly')
            && str_contains($html, 'Enter code')
            && str_contains($html, 'Sign in, or create an account, with this email address')
            && str_contains($html, '2026-11-04')
            && str_contains($html, 'help@saveme.test')
            && str_contains($html, 'Save Me')
            && str_contains($html, 'dir="ltr"');
    });
});

test('the approval mail follows the language the claim was submitted in', function () {
    $claim = PaymentClaim::factory()->inArabic()->forAddress('mona@example.test')->create();

    runClaimJob(new SendClaimApprovedMail($claim->id, '7KQ2-MX4D-9TRB-H3WE', '2026-11-04'));

    Mail::assertSent(ClaimApprovedMail::class, function (ClaimApprovedMail $mail): bool {
        $html = $mail->render();

        return $mail->locale === 'ar'
            && str_contains($html, 'dir="rtl"')
            && str_contains($html, 'بلس · سنوي')
            && str_contains($html, 'إدخال رمز')
            && str_contains($html, '7KQ2-MX4D-9TRB-H3WE')
            && str_contains($html, '2026-11-04');
    });
});

test('the rejection mail states the localised reason and never the internal note', function (string $locale, string $reason, string $expected) {
    $claim = PaymentClaim::factory()->forAddress('mona@example.test')->rejected($reason, 'SECRET-NOTE call him on 0100')->create(['locale' => $locale]);

    runClaimJob(new SendClaimRejectedMail($claim->id));

    Mail::assertSent(ClaimRejectedMail::class, function (ClaimRejectedMail $mail) use ($claim, $locale, $expected): bool {
        $html = $mail->render();

        return $mail->hasTo('mona@example.test')
            && $mail->locale === $locale
            && str_contains($html, $expected)
            && str_contains($html, $claim->id)
            && ! str_contains($html, 'SECRET-NOTE')
            && ! str_contains($html, '0100');
    });
})->with([
    ['en', 'reference_not_found', 'We could not find a payment with the reference you gave.'],
    ['en', 'amount_mismatch', 'The amount we found does not match the price of the plan.'],
    ['ar', 'duplicate', 'سبق أن قُدّمت مطالبة بهذه الدفعة.'],
    ['ar', 'other', 'لم نتمكن من تأكيد الدفعة.'],
]);

test('the expiry notice names the reference by its last four characters only', function () {
    $claim = PaymentClaim::factory()->forAddress('mona@example.test')->reference('ABCDEFGH1234')->expired()->create();

    runClaimJob(new SendClaimExpiredMail($claim->id));

    Mail::assertSent(ClaimExpiredMail::class, function (ClaimExpiredMail $mail) use ($claim): bool {
        $html = $mail->render();

        return $mail->hasTo('mona@example.test')
            && str_contains($html, '1234')
            && ! str_contains($html, 'ABCDEFGH')
            && str_contains($html, $claim->id);
    });
});

test('a claim whose address was erased sends nothing', function (string $job) {
    $claim = PaymentClaim::factory()->rejected('other')->create();
    PaymentClaim::query()->whereKey($claim->id)->update(['address_sealed' => null, 'address_lookup' => null, 'erased_at' => now()]);

    runClaimJob($job === 'approved' ? new SendClaimApprovedMail($claim->id, '7KQ2-MX4D-9TRB-H3WE', '2026-11-04') : new $job($claim->id));

    Mail::assertNothingSent();
})->with(['approved', SendClaimRejectedMail::class, SendClaimExpiredMail::class]);

test('a claim that no longer exists sends nothing', function () {
    runClaimJob(new SendClaimRejectedMail('0192f7a0-0000-7000-8000-00000000ffff'));

    Mail::assertNothingSent();
});
