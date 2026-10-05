<?php

use App\Modules\Identity\Domain\Deletion\Successor;
use App\Modules\Identity\Domain\Deletion\SuccessorCandidate;
use App\Modules\Identity\Domain\Deletion\SuccessorReason;
use App\Modules\Identity\Domain\Deletion\SuccessorRule;

/*
 * Who owns a shared wallet once its owner is gone (spec 022, FR-031): one
 * pure rule, shared by the preview and the purge.
 */

function candidate(string $id, string $role, string $joined, bool $pending = false): SuccessorCandidate
{
    return new SuccessorCandidate($id, $role, new DateTimeImmutable($joined), $pending, 'm-'.$id);
}

test('nobody else belongs: no successor, the wallet goes with the account', function () {
    expect(SuccessorRule::resolve([], null))->toBeNull()
        ->and(SuccessorRule::resolve([], 'x'))->toBeNull();
});

test('one other member becomes owner whatever their role', function () {
    $viewer = SuccessorRule::resolve([candidate('a', 'viewer', '2026-01-01')], null);

    expect($viewer)->toBeInstanceOf(Successor::class)
        ->and($viewer->userId)->toBe('a')
        ->and($viewer->reason)->toBe(SuccessorReason::OnlyMember);
});

test('one other member who is leaving is still the only member', function () {
    $only = SuccessorRule::resolve([candidate('a', 'editor', '2026-01-01', pending: true)], 'nobody');

    expect($only->reason)->toBe(SuccessorReason::OnlyMember);
});

test('the stored choice wins while the chosen person is still a member', function () {
    $candidates = [candidate('a', 'editor', '2026-01-01'), candidate('b', 'viewer', '2026-02-01')];

    $successor = SuccessorRule::resolve($candidates, 'b');

    expect($successor->userId)->toBe('b')->and($successor->reason)->toBe(SuccessorReason::Chosen);
});

test('a chosen person who has left falls back to the longest-standing editor', function () {
    $candidates = [candidate('a', 'editor', '2026-03-01'), candidate('b', 'editor', '2026-02-01'), candidate('c', 'viewer', '2026-01-01')];

    $successor = SuccessorRule::resolve($candidates, 'gone');

    expect($successor->userId)->toBe('b')->and($successor->reason)->toBe(SuccessorReason::OldestEditor);
});

test('joining ties are broken by user id', function () {
    $candidates = [candidate('b', 'editor', '2026-02-01'), candidate('a', 'editor', '2026-02-01'), candidate('c', 'viewer', '2026-01-01')];

    expect(SuccessorRule::resolve($candidates, null)->userId)->toBe('a');
});

test('with no editor the longest-standing viewer inherits', function () {
    $candidates = [candidate('a', 'viewer', '2026-03-01'), candidate('b', 'viewer', '2026-02-01')];

    $successor = SuccessorRule::resolve($candidates, null);

    expect($successor->userId)->toBe('b')->and($successor->reason)->toBe(SuccessorReason::OldestViewer);
});

test('an editor whose own account is leaving is skipped while another editor stays', function () {
    $candidates = [candidate('a', 'editor', '2026-01-01', pending: true), candidate('b', 'editor', '2026-02-01'), candidate('c', 'viewer', '2026-01-01')];

    expect(SuccessorRule::resolve($candidates, null)->userId)->toBe('b');
});

test('leaving editors are used when every editor is leaving', function () {
    $candidates = [candidate('a', 'editor', '2026-02-01', pending: true), candidate('b', 'editor', '2026-01-01', pending: true), candidate('c', 'viewer', '2026-01-01')];

    expect(SuccessorRule::resolve($candidates, null)->userId)->toBe('b');
});

test('a viewer who is staying is not preferred over an editor who is leaving, as editors come first', function () {
    $candidates = [candidate('a', 'editor', '2026-02-01', pending: true), candidate('c', 'viewer', '2026-01-01')];

    expect(SuccessorRule::resolve($candidates, null)->reason)->toBe(SuccessorReason::OldestEditor);
});
