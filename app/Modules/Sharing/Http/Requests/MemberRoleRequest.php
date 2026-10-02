<?php

namespace App\Modules\Sharing\Http\Requests;

use App\Modules\Wallets\Domain\Membership\Role;
use Illuminate\Validation\Rule;

/**
 * A role change (spec 016, FR-016): the new role and the membership's
 * last-seen version. `owner` passes the shape check and is refused later as
 * IMMUTABLE_FIELD, because ownership moves only by transfer.
 */
final class MemberRoleRequest extends SharingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['bail', 'required', 'string', Rule::in(array_map(fn (Role $role): string => $role->value, Role::cases()))],
            'version' => ['required', 'integer', 'min:1'],
        ];
    }

    public function role(): Role
    {
        return Role::from($this->string('role')->toString());
    }

    public function version(): int
    {
        return $this->integer('version');
    }
}
