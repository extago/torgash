<?php

namespace Torgash\NovaPoshta\Api;

use RuntimeException;

/**
 * A Nova Poshta API call that came back with errors, or did not come back.
 * `$codes` holds Nova Poshta's own error codes when it sent them.
 */
class ApiException extends RuntimeException
{
    /** @param list<string> $codes */
    public function __construct(string $message, public readonly array $codes = [])
    {
        parent::__construct($message);
    }
}
