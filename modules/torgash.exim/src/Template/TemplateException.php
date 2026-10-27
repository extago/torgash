<?php

namespace Torgash\Exim\Template;

use RuntimeException;

/**
 * A profile template that cannot be read: an English sentence with
 * `:placeholders`, put in the admin's language by `describe()`.
 */
class TemplateException extends RuntimeException
{
    public function __construct(string $message, public readonly array $params = [])
    {
        parent::__construct(strtr($message, array_combine(array_map(fn ($k) => ':'.$k, array_keys($params)), array_map('strval', $params)) ?: []));
        $this->english = $message;
    }

    public string $english;

    public function describe(): string
    {
        return __($this->english, $this->params);
    }
}
