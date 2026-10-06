<?php

namespace Tests\Fakes;

use App\Modules\Notifications\Domain\Delivery\GatewayOutcome;
use App\Modules\Notifications\Domain\Delivery\PushDestination;
use App\Modules\Notifications\Domain\Delivery\PushGateway;
use App\Modules\Notifications\Domain\Messages\PushMessage;

/**
 * An in-memory PushGateway for tests (spec 023, R-1): records what was sent
 * and to which token, and answers each token from a script (default:
 * accepted). Lives under tests/, never in app/, so production cannot bind it.
 */
final class FakePushGateway implements PushGateway
{
    /** @var list<array{message: PushMessage, token: string, registration_id: string}> */
    public array $sent = [];

    /** @var array<string, list<GatewayOutcome>> */
    private array $scripts = [];

    public bool $delivers = true;

    public function delivers(): bool
    {
        return $this->delivers;
    }

    /**
     * Answers the token's next sends in order; afterwards, accepted.
     */
    public function script(string $token, GatewayOutcome ...$outcomes): self
    {
        $this->scripts[$token] = array_values($outcomes);

        return $this;
    }

    public function send(PushMessage $message, PushDestination $destination): GatewayOutcome
    {
        $this->sent[] = ['message' => $message, 'token' => $destination->token, 'registration_id' => $destination->registrationId];

        $script = $this->scripts[$destination->token] ?? [];
        $outcome = array_shift($script);
        $this->scripts[$destination->token] = $script;

        return $outcome ?? GatewayOutcome::accepted();
    }

    /**
     * @return list<PushMessage>
     */
    public function messages(): array
    {
        return array_map(fn (array $entry): PushMessage => $entry['message'], $this->sent);
    }

    /**
     * @return list<PushMessage>
     */
    public function messagesTo(string $token): array
    {
        return array_values(array_map(
            fn (array $entry): PushMessage => $entry['message'],
            array_filter($this->sent, fn (array $entry): bool => $entry['token'] === $token),
        ));
    }

    public function count(): int
    {
        return count($this->sent);
    }
}
