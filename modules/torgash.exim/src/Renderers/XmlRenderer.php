<?php

namespace Torgash\Exim\Renderers;

class XmlRenderer implements RendererInterface
{
    public function render(iterable $rows, array $fieldDefs): \Generator
    {
        $writer = new \XMLWriter;
        $writer->openMemory();
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElement('items');
        yield $writer->outputMemory(true);

        foreach ($rows as $row) {
            $writer->startElement('item');
            foreach ($fieldDefs as $f) {
                $tag = $this->tagName($f['key']);
                $value = $row[$f['key']] ?? '';
                $writer->writeElement($tag, is_bool($value) ? ($value ? '1' : '0') : (string) $value);
            }
            $writer->endElement();
            yield $writer->outputMemory(true);
        }

        $writer->endElement();
        $writer->endDocument();
        yield $writer->outputMemory(true);
    }

    protected function tagName(string $key): string
    {
        $tag = preg_replace('/[^a-zA-Z0-9_]/', '_', $key);

        return preg_match('/^[a-zA-Z_]/', $tag) ? $tag : '_'.$tag;
    }

    public function contentType(): string
    {
        return 'application/xml; charset=UTF-8';
    }
}
