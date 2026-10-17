<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised by the weather/geocoding services whenever a request cannot be
 * completed. It always carries a translation key (for example
 * "errors.provider_unavailable") that the controller can hand to the Blade
 * view. Internal details (HTTP bodies, exception messages) are kept out of
 * the key so they are never shown to end users.
 */
class WeatherException extends RuntimeException
{
    public function __construct(
        public readonly string $errorKey,
        ?string $internalMessage = null,
    ) {
        parent::__construct($internalMessage ?? $errorKey);
    }

    /**
     * Map a provider HTTP status code to a safe translation key.
     * We deliberately do not retry authentication or rate-limit errors.
     */
    public static function fromStatus(int $status): self
    {
        return match (true) {
            $status === 401, $status === 403 => new self('errors.invalid_api_key', "Provider responded with HTTP {$status}"),
            $status === 429 => new self('errors.rate_limited', 'Provider responded with HTTP 429'),
            $status >= 500 => new self('errors.provider_unavailable', "Provider responded with HTTP {$status}"),
            default => new self('errors.provider_error', "Provider responded with HTTP {$status}"),
        };
    }

    /**
     * A response that arrived but was not shaped the way the API documents
     * (missing fields, unexpected JSON, and so on).
     */
    public static function malformed(string $detail = 'Unexpected provider response'): self
    {
        return new self('errors.malformed_response', $detail);
    }
}
