<?php

namespace App\Modules\Sharing\Http\Requests;

use App\Http\Api\Requests\ApiRequest;
use App\Modules\Sharing\Domain\InvitationSecret;

/**
 * The body of preview, accept and decline: the token, 43 base64url
 * characters. The token travels only here, never in a path or query (P-15
 * rule 4).
 */
final class InvitationTokenRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['bail', 'required', 'string', 'regex:'.InvitationSecret::PATTERN],
        ];
    }

    public function token(): string
    {
        return $this->string('token')->toString();
    }

    public function callerId(): string
    {
        $user = $this->user() ?? throw new \RuntimeException('Route is missing auth:api.');

        return (string) $user->getAuthIdentifier();
    }
}
