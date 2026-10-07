<?php

namespace App\Modules\Billing\Infrastructure\GooglePlay;

use App\Modules\Billing\Domain\GooglePlay\GatewayOutcome;
use App\Modules\Billing\Domain\GooglePlay\PlayBillingGateway;
use App\Modules\Billing\Domain\GooglePlay\PlayProductKind;
use App\Modules\Billing\Domain\GooglePlay\PlayPurchaseRecord;
use App\Modules\Billing\Domain\GooglePlay\PlayState;
use App\Modules\Billing\Domain\GooglePlay\VoidedPage;
use App\Modules\Billing\Domain\GooglePlay\VoidedPurchase;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * A scripted Google Play for local work and tests (spec 024, R-1, FR-061):
 * answers each token from a script, records acknowledgements. Refused at
 * boot outside `local` and `testing`. A token nobody scripted is not found.
 *
 * The script is set in code (`scriptSubscription()`, `scriptProduct()`,
 * `scriptVoided()`, `failNext()`) or, for local development, read from the
 * JSON file named by `billing.google_play.fake_script`: a map of token to
 * `{kind, product_id, state, expires_in_days, auto_renew, acknowledged,
 * obfuscated_account_id, test, linked_purchase_token}`.
 */
final class FakePlayBillingGateway implements PlayBillingGateway
{
    /** @var list<array{kind: string, product_id: string, token: string}> */
    public array $acknowledged = [];

    /** @var array<string, GatewayOutcome> */
    private array $subscriptions = [];

    /** @var array<string, GatewayOutcome> */
    private array $products = [];

    /** @var list<VoidedPurchase> */
    private array $voided = [];

    private int $failures = 0;

    private int $acknowledgementFailures = 0;

    public int $reads = 0;

    public function scriptSubscription(string $token, PlayPurchaseRecord|GatewayOutcome $answer): void
    {
        $this->subscriptions[$token] = $answer instanceof GatewayOutcome ? $answer : GatewayOutcome::found($answer);
    }

    public function scriptProduct(string $token, PlayPurchaseRecord|GatewayOutcome $answer): void
    {
        $this->products[$token] = $answer instanceof GatewayOutcome ? $answer : GatewayOutcome::found($answer);
    }

    /**
     * @param  list<VoidedPurchase>  $voided
     */
    public function scriptVoided(array $voided): void
    {
        $this->voided = $voided;
    }

    /**
     * The next $times reads and voided listings are `Unavailable`.
     */
    public function failNext(int $times = 1): void
    {
        $this->failures = $times;
    }

    /**
     * The next $times acknowledgements are `Unavailable`.
     */
    public function failNextAcknowledgements(int $times = 1): void
    {
        $this->acknowledgementFailures = $times;
    }

    public function readSubscription(#[\SensitiveParameter] string $token): GatewayOutcome
    {
        $this->reads++;

        if ($this->failures > 0) {
            $this->failures--;

            return GatewayOutcome::unavailable(30);
        }

        return $this->subscriptions[$token] ?? $this->fromFile($token, PlayProductKind::Subscription) ?? GatewayOutcome::notFound();
    }

    public function readProduct(string $productId, #[\SensitiveParameter] string $token): GatewayOutcome
    {
        $this->reads++;

        if ($this->failures > 0) {
            $this->failures--;

            return GatewayOutcome::unavailable(30);
        }

        return $this->products[$token] ?? $this->fromFile($token, PlayProductKind::OneTime) ?? GatewayOutcome::notFound();
    }

    public function acknowledge(PlayProductKind $kind, string $productId, #[\SensitiveParameter] string $token): GatewayOutcome
    {
        if ($this->acknowledgementFailures > 0) {
            $this->acknowledgementFailures--;

            return GatewayOutcome::unavailable(30);
        }

        $this->acknowledged[] = ['kind' => $kind->value, 'product_id' => $productId, 'token' => $token];

        $scripted = $kind === PlayProductKind::Subscription ? ($this->subscriptions[$token] ?? null) : ($this->products[$token] ?? null);

        if ($scripted?->record !== null) {
            $answer = GatewayOutcome::found($scripted->record->withAcknowledged(true));
            $kind === PlayProductKind::Subscription ? $this->subscriptions[$token] = $answer : $this->products[$token] = $answer;
        }

        return GatewayOutcome::acknowledged();
    }

    public function voidedSince(DateTimeImmutable $since, ?string $pageToken): VoidedPage
    {
        if ($this->failures > 0) {
            $this->failures--;

            return VoidedPage::unavailable();
        }

        return new VoidedPage($this->voided, null);
    }

    /**
     * Whether the script holds the token and records it as acknowledged.
     */
    public function wasAcknowledged(string $token): bool
    {
        foreach ($this->acknowledged as $call) {
            if ($call['token'] === $token) {
                return true;
            }
        }

        return false;
    }

    private function fromFile(string $token, PlayProductKind $kind): ?GatewayOutcome
    {
        $path = config('billing.google_play.fake_script');

        if (! is_string($path) || $path === '' || ! is_file($path)) {
            return null;
        }

        $script = json_decode((string) file_get_contents($path), true);
        $entry = is_array($script) ? ($script[$token] ?? null) : null;

        if (! is_array($entry) || ($entry['kind'] ?? $kind->value) !== $kind->value) {
            return null;
        }

        try {
            $state = PlayState::from((string) ($entry['state'] ?? 'active'));
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $expires = $kind === PlayProductKind::OneTime ? null : $now->modify(((int) ($entry['expires_in_days'] ?? 30)).' days');

            return GatewayOutcome::found(new PlayPurchaseRecord(
                productId: (string) ($entry['product_id'] ?? 'plus_monthly'),
                kind: $kind,
                state: $state,
                expiresAt: $expires,
                graceUntil: $state === PlayState::Grace ? $expires : null,
                purchasedAt: $now,
                autoRenew: (bool) ($entry['auto_renew'] ?? true),
                acknowledged: (bool) ($entry['acknowledged'] ?? false),
                obfuscatedRef: isset($entry['obfuscated_account_id']) ? (string) $entry['obfuscated_account_id'] : null,
                linkedToken: isset($entry['linked_purchase_token']) ? (string) $entry['linked_purchase_token'] : null,
                isTest: (bool) ($entry['test'] ?? true),
                basePlanId: null,
            ));
        } catch (Throwable) {
            return null;
        }
    }
}
