<?php

declare(strict_types=1);

namespace Cronheart\WP\Api;

/**
 * Why `Plugin::boot()` fell back to {@see Client::disabled()} for this
 * request. Kept in memory only; it is re-derived on every request.
 */
enum PingsDisabledReason
{
    case CurlMissing;
    case ClientUnavailable;
    case EndpointRejected;
}
