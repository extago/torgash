<?php

namespace App\Core\Content;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * A frame's address: https, and from a host the shop allows.
 */
class FrameSource implements AttributeSanitizerInterface
{
    /** @param  list<string>  $hosts */
    public function __construct(protected array $hosts) {}

    public function getSupportedElements(): ?array
    {
        return ['iframe'];
    }

    public function getSupportedAttributes(): ?array
    {
        return ['src'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        $parts = parse_url(trim($value));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (($parts['scheme'] ?? '') !== 'https' || $host === '') {
            return null;
        }

        foreach ($this->hosts as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return $value;
            }
        }

        return null;
    }
}
