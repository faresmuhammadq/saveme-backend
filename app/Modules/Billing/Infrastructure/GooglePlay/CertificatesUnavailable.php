<?php

namespace App\Modules\Billing\Infrastructure\GooglePlay;

use RuntimeException;

/**
 * Google's signing certificates cannot be obtained and none are cached: the
 * push is answered 503 so Pub/Sub retries (spec 024, FR-031).
 */
final class CertificatesUnavailable extends RuntimeException {}
