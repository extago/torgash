<?php

namespace Torgash\Cdek\Api;

use RuntimeException;

/**
 * A CDEK API call that came back with errors, or did not come back.
 * `$codes` holds CDEK's own error codes (`v2_recipient_location_not_recognized`)
 * when it sent them; `$transient` says whether asking again could help.
 */
class ApiException extends RuntimeException
{
    /** @param list<string> $codes */
    public function __construct(string $message, public readonly array $codes = [], public readonly bool $transient = false)
    {
        parent::__construct($message);
    }
}
