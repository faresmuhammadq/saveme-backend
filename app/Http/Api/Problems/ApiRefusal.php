<?php

namespace App\Http\Api\Problems;

use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * A refusal raised by the HTTP runtime itself (transport, negotiation, client
 * version, validation shape) rather than by a module's domain rule.
 */
final class ApiRefusal extends Refusal {}
