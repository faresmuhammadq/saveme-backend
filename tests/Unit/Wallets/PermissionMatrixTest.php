<?php

use App\Modules\Billing\Domain\Feature;
use App\Modules\Wallets\Domain\Membership\Permission;
use App\Modules\Wallets\Domain\Membership\PermissionMatrix;
use App\Modules\Wallets\Domain\Membership\Role;

/*
 * The permission matrix of spec 010 section 6, row by row. The dataset below
 * IS that table (rows by their IDs): edit it together with spec section 6,
 * never as a restatement of the rule in prose. The matrix lives once in the
 * spec (P-08 rule 5); this test is what makes the code agree with it.
 */

const MATRIX_ROWS = [
    // ID,  owner, editor, viewer
    'W-01' => [true, true, true],
    'W-02' => [true, false, false],
    'W-03' => [true, false, false],
    'W-04' => [true, false, false],
    'W-05' => [true, false, false],
    'T-01' => [true, true, true],
    'T-02' => [true, true, false],
    'T-03' => [true, true, false],
    'R-01' => [true, true, true],
    'R-02' => [true, true, false],
    'S-01' => [true, true, true],
    'S-02' => [true, false, false],
    'S-03' => [true, false, false],
    'S-04' => [true, false, false],
    'S-05' => [false, true, true],
];

dataset('matrix cells', function () {
    foreach (MATRIX_ROWS as $rowId => $allowed) {
        foreach ([Role::Owner, Role::Editor, Role::Viewer] as $index => $role) {
            yield "{$rowId} as {$role->value}" => [$rowId, $role, $allowed[$index]];
        }
    }
});

test('each cell of the matrix', function (string $rowId, Role $role, bool $allowed) {
    $permission = Permission::from($rowId);

    expect(PermissionMatrix::allows($role, $permission))->toBe($allowed);
})->with('matrix cells');

test('the matrix has exactly the rows of the spec', function () {
    expect(array_map(fn (Permission $permission): string => $permission->rowId(), Permission::cases()))
        ->toBe(array_keys(MATRIX_ROWS));
});

test('only S-02 names a capability, and it is sharing', function () {
    foreach (Permission::cases() as $permission) {
        expect(PermissionMatrix::capabilityFor($permission))
            ->toBe($permission === Permission::S02SendOrRevokeInvitation ? Feature::SharingEnabled : null);
    }
});
