<?php

namespace Torgash\Exim\Renderers;

interface RendererInterface
{
    /**
     * @param  iterable<array<string,mixed>>  $rows  Already-resolved assoc rows (field key => value).
     * @param  list<array{key:string,label:string}>  $fieldDefs
     * @return \Generator<string> Yields output in pieces so the caller can stream, not buffer.
     */
    public function render(iterable $rows, array $fieldDefs): \Generator;

    public function contentType(): string;
}
