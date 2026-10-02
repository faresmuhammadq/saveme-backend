<?php

namespace App\Modules\Sharing\Http\Requests;

use App\Http\Api\Requests\ApiRequest;
use App\Modules\Sharing\Http\PathIds;

/**
 * Base for Sharing's write bodies. Every identifier in the path is checked
 * before the body (INVALID_ID precedes VALIDATION_FAILED, spec 016 section 6).
 */
abstract class SharingRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->walletId();

        if ($this->route('iid') !== null) {
            $this->invitationId();
        }

        if ($this->route('uid') !== null) {
            $this->memberUserId();
        }
    }

    public function walletId(): string
    {
        return PathIds::wallet($this->route('id'));
    }

    public function invitationId(): string
    {
        return PathIds::invitation($this->route('iid'));
    }

    public function memberUserId(): string
    {
        return PathIds::user($this->route('uid'));
    }

    public function callerId(): string
    {
        $user = $this->user() ?? throw new \RuntimeException('Route is missing auth:api.');

        return (string) $user->getAuthIdentifier();
    }
}
