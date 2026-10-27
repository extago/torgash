<?php

namespace Torgash\Exim\Import;

use RuntimeException;

/**
 * A formula that cannot be read, or cannot be worked out for one row. The
 * message is an untranslated English sentence with `:placeholders` —
 * `describe()` puts it in the admin's language.
 */
class FormulaException extends RuntimeException
{
    public function __construct(string $message, public readonly array $params = [])
    {
        parent::__construct($message);
    }

    public function describe(): string
    {
        return __($this->getMessage(), $this->params);
    }
}
