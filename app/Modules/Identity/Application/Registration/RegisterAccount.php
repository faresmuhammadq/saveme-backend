<?php

namespace App\Modules\Identity\Application\Registration;

use App\Modules\Billing\Application\SubscriptionService;
use App\Modules\Identity\Application\Events;
use App\Modules\Identity\Application\Jobs\SendRegistrationMail;
use App\Modules\Identity\Domain\Events\UserRegistered;
use App\Modules\Identity\Domain\Ports\AddressLookup;
use App\Modules\Identity\Domain\Ports\AttributeCipher;
use App\Modules\Identity\Domain\Ports\ProfileRepository;
use App\Modules\Identity\Domain\Ports\UserRepository;
use App\Modules\Identity\Domain\Refusals\EmailAlreadyRegistered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Registration (spec 007, FR-002–FR-004). A new account's user, profile and
 * default subscription are created in one unit of work, and a welcome mail
 * with a verification link is queued.
 *
 * An address that already has an account is refused with 409
 * EMAIL_ALREADY_REGISTERED and nothing is sent (owner decision 2026-09-27):
 * registration is the one surface that discloses an existing account.
 */
final class RegisterAccount
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly ProfileRepository $profiles,
        private readonly AttributeCipher $cipher,
        private readonly AddressLookup $lookup,
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * @throws EmailAlreadyRegistered
     */
    public function handle(string $email, string $password, ?string $displayName, ?string $timezone, string $locale): void
    {
        $email = trim($email);
        $lookup = $this->lookup->for($email);

        if ($this->users->findByLookup($lookup) !== null) {
            throw new EmailAlreadyRegistered;
        }

        $passwordHash = Hash::make($password);

        try {
            $userId = DB::transaction(function () use ($email, $lookup, $passwordHash, $displayName, $timezone, $locale): string {
                $userId = (string) Str::uuid7();
                $this->cipher->newDataKeyFor($userId);
                $this->users->create($userId, $this->cipher->seal($userId, $email), $lookup, $passwordHash);
                $this->profiles->create(
                    $userId,
                    $locale,
                    $timezone ?? (string) config('identity.default_timezone'),
                    $displayName === null ? null : $this->cipher->seal($userId, trim($displayName)),
                );
                $this->subscriptions->enrolInDefaultPlan($userId);

                event(new UserRegistered(...Events::stamp(), userId: $userId, locale: $locale));

                return $userId;
            });
        } catch (UniqueConstraintViolationException) {
            // Two registrations raced for one address; the other one won.
            throw new EmailAlreadyRegistered;
        }

        SendRegistrationMail::dispatch($userId, $locale)->afterCommit();
    }
}
