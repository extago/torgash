<?php

namespace Torgash\Exim\Renderers;

/**
 * Streamed by hand — encodes one row object at a time rather than
 * json_encode()-ing the whole collection, so memory stays flat over a
 * lazyById() cursor of any size.
 */
class JsonRenderer implements RendererInterface
{
    public function render(iterable $rows, array $fieldDefs): \Generator
    {
        yield '[';

        $first = true;
        foreach ($rows as $row) {
            $out = [];
            foreach ($fieldDefs as $f) {
                $out[$f['key']] = $row[$f['key']] ?? null;
            }

            yield ($first ? '' : ',').json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $first = false;
        }

        yield ']';
    }

    public function contentType(): string
    {
        return 'application/json; charset=UTF-8';
    }
}
