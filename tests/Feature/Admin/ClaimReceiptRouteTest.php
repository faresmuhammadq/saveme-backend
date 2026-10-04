<?php

use App\Modules\Billing\Application\Claims\ClaimReceipts;
use App\Modules\Billing\Infrastructure\Models\PaymentClaim;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/*
 * Operators view a receipt through a signed, short-lived link (spec 020,
 * FR-014, FR-014a, R-10).
 */

beforeEach(function () {
    $this->freezeTime();
    Storage::fake('local');
    $this->claim = PaymentClaim::factory()->withReceipt()->create();
    $this->png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAADElEQVQImWNgYGAAAAAEAAGjChXjAAAAAElFTkSuQmCC');
});

function receiptViews(string $claimId): array
{
    return DB::table('audit_entries')->where('event', 'admin_action')->where('subject_type', 'payment_claim')->where('subject_id', $claimId)->get()->all();
}

test('the link is signed, expires in 5 minutes and names the admin route', function () {
    $url = app(ClaimReceipts::class)->temporaryUrl($this->claim->id);

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    expect(parse_url($url, PHP_URL_PATH))->toBe("/admin/claims/{$this->claim->id}/receipt")
        ->and((int) $query['expires'])->toBe(now()->addMinutes(5)->getTimestamp())
        ->and($query['signature'])->not->toBeEmpty();
});

test('a signed-in operator gets the decrypted image with the protective headers, and the view is recorded', function () {
    ['operator' => $operator] = makeOperator();
    signInOperator($operator);

    $response = $this->get(app(ClaimReceipts::class)->temporaryUrl($this->claim->id))->assertOk();

    expect($response->getContent())->toBe($this->png)
        ->and($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'")
        ->and($response->headers->get('Content-Disposition'))->toBe('inline');

    $views = receiptViews($this->claim->id);
    expect($views)->toHaveCount(1)
        ->and($views[0]->actor_type)->toBe('operator')
        ->and($views[0]->actor_id)->toBe($operator->id)
        ->and(json_decode($views[0]->detail, true))->toBe(['action' => 'claim_receipt_viewed']);

    $this->get(app(ClaimReceipts::class)->temporaryUrl($this->claim->id))->assertOk();
    expect(receiptViews($this->claim->id))->toHaveCount(2);
});

test('an expired or tampered signature is 403 and nothing is recorded', function () {
    ['operator' => $operator] = makeOperator();
    signInOperator($operator);
    $url = app(ClaimReceipts::class)->temporaryUrl($this->claim->id);

    $this->travel(6)->minutes();
    $this->get($url)->assertForbidden();

    $this->travelBack();
    $this->get(str_replace('signature=', 'signature=0', $url))->assertForbidden();
    $this->get("/admin/claims/{$this->claim->id}/receipt")->assertForbidden();

    $other = PaymentClaim::factory()->withReceipt()->create();
    $this->get(str_replace($this->claim->id, $other->id, $url))->assertForbidden();

    expect(receiptViews($this->claim->id))->toBe([])->and(receiptViews($other->id))->toBe([]);
});

test('without an operator session the link leads to sign-in and shows nothing', function () {
    $this->get(app(ClaimReceipts::class)->temporaryUrl($this->claim->id))->assertRedirect(route('admin.sign-in'));

    expect(receiptViews($this->claim->id))->toBe([]);
});

test('an application bearer token does not open it', function () {
    person('ada@example.test');
    $credential = signInAs('ada@example.test')->json('data.credential');

    $this->withHeaders(['Authorization' => 'Bearer '.$credential])
        ->get(app(ClaimReceipts::class)->temporaryUrl($this->claim->id))
        ->assertRedirect(route('admin.sign-in'));
});

test('an erased claim, a claim without a receipt or an unknown claim is 404', function () {
    ['operator' => $operator] = makeOperator();
    signInOperator($operator);
    $plain = PaymentClaim::factory()->create();
    $erased = PaymentClaim::factory()->withReceipt()->closedDaysAgo(95)->create();
    PaymentClaim::query()->whereKey($erased->id)->update(['address_sealed' => null, 'address_lookup' => null, 'receipt_path' => null, 'receipt_media_type' => null, 'receipt_bytes' => null, 'erased_at' => now()]);

    expect(app(ClaimReceipts::class)->temporaryUrl($plain->id))->toBeNull()
        ->and(app(ClaimReceipts::class)->temporaryUrl($erased->id))->toBeNull();

    foreach ([$plain->id, $erased->id, (string) Str::uuid7()] as $id) {
        $this->get(URL::temporarySignedRoute('admin.claims.receipt', now()->addMinutes(5), ['claim' => $id]))->assertNotFound();
    }

    $this->get(URL::temporarySignedRoute('admin.claims.receipt', now()->addMinutes(5), ['claim' => 'not-a-uuid']))->assertNotFound();
    expect(receiptViews($plain->id))->toBe([]);
});
