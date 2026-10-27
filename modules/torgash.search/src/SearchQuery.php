<?php

namespace Torgash\Search;

/**
 * What was typed, and what it reduces to.
 *
 * `words` are runs of letters and digits only — everything a tsquery could
 * read as an operator (`& | ! : ( ) *`) is gone before any SQL sees it, so a
 * query is always data. "ST-2001" becomes `st` and `2001`, both of which are
 * in the document of that SKU.
 */
final class SearchQuery
{
    /** @var list<string> */
    public readonly array $words;

    public readonly string $normalized;

    public function __construct(public readonly string $raw)
    {
        $this->normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $raw)));

        preg_match_all('/[\p{L}\p{N}]+/u', $this->normalized, $m);
        $this->words = array_values(array_slice(array_unique($m[0]), 0, 8));
    }

    public function isEmpty(): bool
    {
        return $this->words === [];
    }

    /** The same query as typed on another keyboard layout — see Layout. */
    public function retyped(string $normalized): self
    {
        return new self($normalized);
    }
}
