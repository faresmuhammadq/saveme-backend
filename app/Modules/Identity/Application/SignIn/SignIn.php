<?php

namespace App\Modules\Identity\Application\SignIn;

use App\Modules\Billing\Application\Entitlements\EntitlementService;
use App\Modules\Identity\Application\Accounts;
use App\Modules\Identity\Application\Audit\AuditLog;
use App\Modules\Identity\Application\Audit\AuditRecord;
use App\Modules\Identity\Application\Audit\Details\SessionDetail;
use App\Modules\Identity\Application\Audit\Details\SessionRevokedDetail;
use App\Modules\Identity\Application\Deletion\CancelDeletion;
use App\Modules\Identity\Application\Dto\DeviceSessionView;
use App\Modules\Identity\Application\Dto\SignInResult;
use App\Modules\Identity\Application\Events;
use App\Modules\Identity\Application\Mail\DeletionCancelledBySignInMail;
use App\Modules\Identity\Domain\Account\AccountState;
use App\Modules\Identity\Domain\Account\UserRecord;
use App\Modules\Identity\Domain\Audit\AuditEvent;
use App\Modules\Identity\Domain\Audit\SubjectType;
use App\Modules\Identity\Domain\Events\DeviceSessionEnded;
use App\Modules\Identity\Domain\Events\UserSignedIn;
use App\Modules\Identity\Domain\Ports\AddressLookup;
use App\Modules\Identity\Domain\Ports\ProfileRepository;
use App\Modules\Identity\Domain\Ports\SessionIssuer;
use App\Modules\Identity\Domain\Ports\SessionRepository;
use App\Modules\Identity\Domain\Ports\UserRepository;
use App\Modules\Identity\Domain\Refusals\InvalidCredentials;
use App\Modules\Identity\Domain\Refusals\SessionNotFound;
use App\Modules\Identity\Domain\Refusals\TooManyAttempts;
use App\Modules\Identity\Domain\Sessions\DeviceDescription;
use App\Modules\Identity\Domain\Sessions\EndReason;
use App\Modules\Identity\Domain\Sessions\IssuedCredential;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Sign-in from a device (spec 007, US1, US5, US6).
 *
 * Order of checks: the origin limit, the account lock, the password
 * (CredentialCheck), then the session identifier. Idempotent per session id:
 * a retry rotates the same session's credential; a new id from a device that
 * already has an active session ends that one as `replaced`.
 *
 * A new session on an account in its deletion grace period cancels the
 * deletion (FR-054), in the same unit of work as issuing the session.
 */
final class SignIn
{
    public function __construct(
        private readonly AddressLookup $lookup,
        private readonly OriginLimiter $origins,
        private readonly CredentialCheck $credentials,
        private readonly UserRepository $users,
        private readonly SessionRepository $sessions,
        private readonly SessionIssuer $issuer,
        private readonly ProfileRepository $profiles,
        private readonly CancelDeletion $cancelDeletion,
        private readonly Accounts $accounts,
        private readonly EntitlementService $entitlements,
        private readonly AuditLog $audit,
    ) {}

    public function handle(string $email, string $password, DeviceDescription $device, string $origin): SignInResult
    {
        $blockedFor = $this->origins->secondsBlocked($origin);

        if ($blockedFor !== null) {
            throw new TooManyAttempts($blockedFor);
        }

        try {
            $user = $this->credentials->verify($this->lookup->for($email), $password);
        } catch (InvalidCredentials $failure) {
            $this->origins->hit($origin);

            throw $failure;
        }

        [$credential, $replaced, $deletionCancelled] = DB::transaction(
            fn (): array => $this->openSession($user, $password, $device),
        );

        event(new UserSignedIn(...Events::stamp(), userId: $user->id, sessionId: $device->sessionId, platform: $device->platform));

        if ($replaced !== null) {
            event(new DeviceSessionEnded(...Events::stamp(), userId: $user->id, sessionId: $replaced, reason: EndReason::Replaced->value));
        }

        if ($deletionCancelled) {
            Mail::to($this->accounts->address($user->id))
                ->locale($this->profiles->localeOf($user->id))
                ->queue((new DeletionCancelledBySignInMail($device->name, now()->toImmutable(), $this->profiles->timezoneOf($user->id)))->afterCommit());
        }

        $session = $this->sessions->find($device->sessionId) ?? throw new RuntimeException('Session vanished.');

        return new SignInResult(
            credential: $credential->plainText,
            session: new DeviceSessionView($session, current: true),
            account: $this->accounts->view($user->id),
            entitlements: $this->entitlements->summaryFor($user->id),
            deletionCancelled: $deletionCancelled,
        );
    }

    /**
     * @return array{IssuedCredential, ?string, bool} the credential, the id of a session it replaced, whether a deletion was cancelled
     */
    private function openSession(UserRecord $user, string $password, DeviceDescription $device): array
    {
        $existing = $this->sessions->find($device->sessionId);
        $replaced = null;

        if ($existing !== null) {
            if ($existing->userId !== $user->id || $existing->state->isTerminal()) {
                throw new SessionNotFound;
            }

            $credential = $this->issuer->rotate($existing->id);
        } else {
            $prior = $this->sessions->activeForDevice($user->id, $device->deviceId);

            if ($prior !== null && $this->sessions->end($prior->id, EndReason::Replaced)) {
                $replaced = $prior->id;
                $this->audit->record(AuditRecord::byUser(AuditEvent::SessionRevoked, $user->id, SubjectType::Session, $prior->id, new SessionRevokedDetail(EndReason::Replaced)));
            }

            $credential = $this->issuer->issue($user->id, $device);
        }

        if (Hash::needsRehash($user->passwordHash)) {
            $this->users->updatePasswordHash($user->id, Hash::make($password));
        }

        $this->audit->record(AuditRecord::byUser(AuditEvent::SignInSucceeded, $user->id, SubjectType::User, $user->id));
        $this->audit->record(AuditRecord::byUser(AuditEvent::SessionIssued, $user->id, SubjectType::Session, $device->sessionId, new SessionDetail($device->platform)));

        // Only a new session cancels; retrying a sign-in that already
        // happened never does it twice (FR-054, edge cases).
        $deletionCancelled = $existing === null
            && $user->state === AccountState::PendingDeletion
            && $this->cancelDeletion->cancel($user->id, 'sign_in', $device->sessionId);

        return [$credential, $replaced, $deletionCancelled];
    }
}
