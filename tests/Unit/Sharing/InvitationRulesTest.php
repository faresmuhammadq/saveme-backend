<?php

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Sharing\Domain\InvitationChannel;
use App\Modules\Sharing\Domain\InvitationRecord;
use App\Modules\Sharing\Domain\InvitationRules;
use App\Modules\Sharing\Domain\InvitationSecret;
use App\Modules\Sharing\Domain\InvitationStatus;
use App\Modules\Sharing\Domain\InvitedRole;

/*
 * The invitation's own rules (spec 016, FR-005, FR-008): the status machine,
 * the address erased on close, immutability, the channel and the token's shape.
 */

function pendingEmailInvitation(InvitedRole $role = InvitedRole::Editor, ?string $lookup = 'lookup-1'): InvitationRecord
{
    $now = new DateTimeImmutable('2026-10-02T09:00:00Z');

    return new InvitationRecord(
        id: '0192f7b0-0000-7000-8000-0000000000a1',
        walletId: '0192f79e-0000-7000-8000-000000000001',
        invitedBy: '0192f790-0000-7000-8000-00000000000a',
        role: $role,
        channel: $lookup === null ? InvitationChannel::Link : InvitationChannel::Email,
        addressSealed: $lookup === null ? null : 'sealed',
        addressLookup: $lookup,
        tokenHash: str_repeat('a', 64),
        status: InvitationStatus::Pending,
        expiresAt: $now->modify('+7 days'),
        responderUserId: null,
        version: 1,
        changeSeq: 41,
        createdAt: $now,
        updatedAt: $now,
        deletedAt: null,
    );
}

test('a pending invitation can become each closed status and nothing leaves a closed one', function () {
    $closed = [InvitationStatus::Accepted, InvitationStatus::Declined, InvitationStatus::Revoked, InvitationStatus::Expired];

    foreach ($closed as $to) {
        expect(InvitationStatus::Pending->canBecome($to))->toBeTrue();

        foreach (InvitationStatus::cases() as $next) {
            expect($to->canBecome($next))->toBeFalse();
        }
    }

    expect(InvitationStatus::Pending->canBecome(InvitationStatus::Pending))->toBeFalse();
});

test('closing erases the address and lookup value, sets the tombstone and moves the version', function () {
    $at = new DateTimeImmutable('2026-10-03T10:00:00Z');

    $closed = InvitationRules::closed(pendingEmailInvitation(), InvitationStatus::Accepted, $at, 'responder-1');

    expect($closed->status)->toBe(InvitationStatus::Accepted)
        ->and($closed->addressSealed)->toBeNull()
        ->and($closed->addressLookup)->toBeNull()
        ->and($closed->deletedAt)->toEqual($at)
        ->and($closed->isTombstone())->toBeTrue()
        ->and($closed->version)->toBe(2)
        ->and($closed->responderUserId)->toBe('responder-1');
});

test('a closed invitation cannot be closed again', function () {
    $closed = InvitationRules::closed(pendingEmailInvitation(), InvitationStatus::Declined, new DateTimeImmutable);

    expect(fn () => InvitationRules::closed($closed, InvitationStatus::Revoked, new DateTimeImmutable))->toThrow(LogicException::class);
});

test('an identical body passes whatever version it carries', function () {
    expect(InvitationRules::immutableViolations(pendingEmailInvitation(), InvitedRole::Editor, 'lookup-1'))->toBe([]);
});

test('a changed role or address is IMMUTABLE_FIELD at each pointer, collected', function () {
    $stored = pendingEmailInvitation();

    $role = InvitationRules::immutableViolations($stored, InvitedRole::Viewer, 'lookup-1');
    $address = InvitationRules::immutableViolations($stored, InvitedRole::Editor, 'other');
    $both = InvitationRules::immutableViolations($stored, InvitedRole::Viewer, null);

    expect(array_map(fn ($error): string => $error->pointer, $role))->toBe(['/role'])
        ->and(array_map(fn ($error): string => $error->pointer, $address))->toBe(['/invitee_email'])
        ->and(array_map(fn ($error): string => $error->pointer, $both))->toBe(['/role', '/invitee_email'])
        ->and($both[0]->code)->toBe(ErrorCode::ImmutableField);
});

test('the channel is derived from the address being null', function () {
    expect(InvitationRules::channelFor('a@example.test'))->toBe(InvitationChannel::Email)
        ->and(InvitationRules::channelFor(null))->toBe(InvitationChannel::Link);
});

test('an invitation is live only while pending and before its expiry instant', function () {
    $invitation = pendingEmailInvitation();

    expect($invitation->isLiveAt(new DateTimeImmutable('2026-10-09T08:59:59Z')))->toBeTrue()
        ->and($invitation->isLiveAt(new DateTimeImmutable('2026-10-09T09:00:00Z')))->toBeFalse()
        ->and(InvitationRules::closed($invitation, InvitationStatus::Revoked, new DateTimeImmutable('2026-10-03T00:00:00Z'))->isLiveAt(new DateTimeImmutable('2026-10-03T00:00:01Z')))->toBeFalse();
});

test('a token is 43 base64url characters, differs every time and is kept only as its hash', function () {
    $first = InvitationSecret::generate();
    $second = InvitationSecret::generate();

    expect($first->value)->toMatch(InvitationSecret::PATTERN)
        ->and($first->value)->not->toBe($second->value)
        ->and($first->hash())->toBe(hash('sha256', $first->value))
        ->and($first->hash())->not->toContain($first->value)
        ->and(InvitationSecret::fromToken($first->value)?->hash())->toBe($first->hash())
        ->and(InvitationSecret::fromToken('short'))->toBeNull()
        ->and(InvitationSecret::fromToken(str_repeat('+', 43)))->toBeNull();
});
