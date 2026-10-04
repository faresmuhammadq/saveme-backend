<?php

use App\Modules\Billing\Application\Claims\ClaimQuery;
use App\Modules\Billing\Application\Claims\ClaimService;
use App\Modules\Billing\Application\Claims\SubmitClaim;
use App\Modules\Billing\Domain\Claims\ReceiptImage;
use App\Modules\Billing\Domain\Ports\ClaimSealer;
use App\Modules\Billing\Domain\Refusals\ClaimInvalid;
use App\Modules\Billing\Infrastructure\Models\PaymentClaim;
use App\Modules\Identity\Application\Billing\ClaimAddresses;
use App\Modules\Identity\Domain\Ports\UserRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;

/*
 * Submitting a claim (spec 020, FR-003 to FR-013).
 */

beforeEach(function () {
    $this->freezeTime();
    Storage::fake('local');
    $this->service = fn (): ClaimService => app(ClaimService::class);
});

function claimCommand(array $overrides = []): SubmitClaim
{
    $values = [
        'address' => ' Mona@Example.TEST ',
        'planId' => planIdByCode('plus_yearly'),
        'amountMinor' => 129000,
        'currency' => 'EGP',
        'reference' => '4815 1623-4211',
        'locale' => 'en',
        'receipt' => null,
        ...$overrides,
    ];

    return new SubmitClaim(...$values);
}

test('a claim is stored submitted for 7 days, with the reference normalised and the language kept', function () {
    $submitted = ($this->service)()->submit(claimCommand(['locale' => 'ar']));

    $claim = PaymentClaim::query()->sole();
    expect($submitted->id)->toBe($claim->id)
        ->and($submitted->status)->toBe('submitted')
        ->and($submitted->planName)->toBe('بلس · سنوي')
        ->and($claim->status)->toBe('submitted')
        ->and($claim->reference)->toBe('481516234211')
        ->and($claim->locale)->toBe('ar')
        ->and($claim->amount_minor)->toBe(129000)
        ->and($claim->currency)->toBe('EGP')
        ->and($claim->expires_at->equalTo(now()->addDays(7)))->toBeTrue()
        ->and($claim->closed_at)->toBeNull()
        ->and($claim->receipt_path)->toBeNull();
});

test('the address is sealed to the normalised address for this claim, with its lookup value beside it', function () {
    ($this->service)()->submit(claimCommand());

    $claim = PaymentClaim::query()->sole();
    $expected = app(ClaimAddresses::class)->normaliseAndLookup('mona@example.test');

    expect($claim->address_sealed)->toStartWith('v1.b1.')->not->toContain('mona')
        ->and(app(ClaimSealer::class)->open($claim->address_sealed, $claim->id, 'address'))->toBe('mona@example.test')
        ->and($claim->address_lookup)->toBe($expected['lookup']);
});

test('a receipt is stored sealed on the private disk and described on the claim', function () {
    $image = ReceiptImage::from('image/png', receiptBase64('png'));

    ($this->service)()->submit(claimCommand(['receipt' => $image]));

    $claim = PaymentClaim::query()->sole();
    expect($claim->receipt_path)->toBe("billing/claims/{$claim->id}/receipt")
        ->and($claim->receipt_media_type)->toBe('image/png')
        ->and($claim->receipt_bytes)->toBe($image->size());

    $stored = Storage::disk('local')->get($claim->receipt_path);
    expect($stored)->not->toBe($image->bytes)
        ->and(str_contains($stored, "\x89PNG"))->toBeFalse()
        ->and(app(ClaimSealer::class)->open($stored, $claim->id, 'receipt'))->toBe($image->bytes);
});

test('a failed insert leaves no file behind', function () {
    // The database refuses what the service would pass on: a language outside ar and en.
    $image = ReceiptImage::from('image/png', receiptBase64('png'));

    expect(fn () => ($this->service)()->submit(claimCommand(['receipt' => $image, 'locale' => 'fr'])))->toThrow(QueryException::class);

    expect(PaymentClaim::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('submitting reads no account', function () {
    $users = Mockery::mock(UserRepository::class);
    $users->shouldNotReceive('findByLookup', 'find', 'lookupOf', 'sealedEmail');
    app()->instance(UserRepository::class, $users);

    ($this->service)()->submit(claimCommand());

    expect(PaymentClaim::query()->count())->toBe(1);
});

test('the same reference on two claims is accepted and each lists the other as a duplicate', function () {
    $first = ($this->service)()->submit(claimCommand(['address' => 'a@example.test']));
    $second = ($this->service)()->submit(claimCommand(['address' => 'b@example.test', 'reference' => '481516234211']));
    $other = ($this->service)()->submit(claimCommand(['reference' => 'ZZZZ99998888']));

    $query = app(ClaimQuery::class);

    expect(PaymentClaim::query()->count())->toBe(3)
        ->and(array_map(fn ($c) => $c->id, $query->duplicatesOf($first->id)))->toBe([$second->id])
        ->and(array_map(fn ($c) => $c->id, $query->duplicatesOf($second->id)))->toBe([$first->id])
        ->and($query->duplicatesOf($other->id))->toBe([]);
});

test('a claim the service cannot accept is refused and nothing is stored', function (array $overrides, string $pointer) {
    try {
        ($this->service)()->submit(claimCommand($overrides));
        $this->fail('Expected a refusal.');
    } catch (ClaimInvalid $e) {
        expect(array_map(fn ($f) => $f->pointer, $e->fieldErrors))->toContain($pointer);
    }

    expect(PaymentClaim::query()->count())->toBe(0);
})->with([
    'wrong amount' => [['amountMinor' => 129001], '/amount_minor'],
    'wrong currency' => [['currency' => 'USD'], '/currency'],
    'an unknown plan' => [['planId' => '0192f7a0-0000-7000-8000-00000000ffff'], '/plan_id'],
    'not a plan id' => [['planId' => 'plus'], '/plan_id'],
    'a short reference' => [['reference' => 'AB12'], '/reference'],
    'not an address' => [['address' => 'nobody'], '/email'],
]);
