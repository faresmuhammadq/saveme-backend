<?php

namespace App\Modules\Admin\Application\Enrolment;

use App\Modules\Admin\Application\Dto\EnrolmentSetup;
use App\Modules\Admin\Domain\Operators\Refusals\PendingSignInExpired;
use App\Modules\Admin\Domain\Ports\OperatorRepository;
use App\Modules\Admin\Domain\SecondFactor\Base32;
use Illuminate\Support\Facades\DB;

/**
 * Shows the setup key and link for a new authenticator (FR-021). The secret
 * is generated on first use, sealed as the operator's pending secret and
 * reused on reload for 15 minutes, so reloading the page does not change the
 * key the person already typed into their app.
 */
final class BeginEnrolment
{
    public const SECRET_BYTES = 20;

    public function __construct(private readonly OperatorRepository $operators) {}

    /**
     * @throws PendingSignInExpired
     */
    public function handle(string $operatorId): EnrolmentSetup
    {
        return DB::transaction(function () use ($operatorId): EnrolmentSetup {
            $operator = $this->operators->lockForUpdate($operatorId);

            if ($operator === null || ! $operator->canSignIn()) {
                throw new PendingSignInExpired;
            }

            $fresh = $operator->pendingSecret !== null
                && $operator->pendingSecretCreatedAt !== null
                && $operator->pendingSecretCreatedAt > now()->subMinutes((int) config('admin.auth.pending_minutes.enrolment'));

            $secret = $fresh ? $operator->pendingSecret : random_bytes(self::SECRET_BYTES);

            if (! $fresh) {
                $this->operators->storePendingSecret($operatorId, $secret, now()->toDateTimeImmutable());
            }

            $key = Base32::encode($secret);
            $issuer = (string) config('admin.auth.issuer');

            return new EnrolmentSetup(
                keyGrouped: implode(' ', str_split($key, 4)),
                link: 'otpauth://totp/'.rawurlencode($issuer).':'.rawurlencode($operator->email)
                    .'?secret='.$key.'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits=6&period=30',
                label: $issuer.': '.$operator->email,
            );
        });
    }
}
