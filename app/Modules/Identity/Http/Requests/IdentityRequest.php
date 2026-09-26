<?php

namespace App\Modules\Identity\Http\Requests;

use App\Http\Api\Requests\ApiRequest;

/**
 * Base for Identity's request bodies: unknown members refused (ApiRequest).
 */
abstract class IdentityRequest extends ApiRequest {}
