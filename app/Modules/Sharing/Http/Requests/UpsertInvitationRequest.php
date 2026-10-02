<?php

namespace App\Modules\Sharing\Http\Requests;

use App\Modules\Sharing\Domain\InvitedRole;
use Illuminate\Validation\Rule;

/**
 * The create body (spec 016, FR-001): `role` and `invitee_email` are both
 * required — the address may be null, which makes a link invitation — and
 * `version` is omitted or 0.
 */
final class UpsertInvitationRequest extends SharingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['bail', 'required', 'string', Rule::in(array_map(fn (InvitedRole $role): string => $role->value, InvitedRole::cases()))],
            'invitee_email' => ['bail', 'present', 'nullable', 'string', 'email', 'min:3', 'max:254'],
            'version' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function role(): InvitedRole
    {
        return InvitedRole::from($this->string('role')->toString());
    }

    public function inviteeEmail(): ?string
    {
        return $this->input('invitee_email') === null ? null : $this->string('invitee_email')->toString();
    }

    /**
     * Omitted or 0 means create.
     */
    public function version(): int
    {
        return $this->integer('version');
    }
}
