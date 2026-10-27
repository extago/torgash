<?php

namespace Torgash\CommerceMl\Xml;

use DOMDocument;
use Generator;
use RuntimeException;
use XMLReader;

/**
 * A CommerceML file read one element at a time.
 *
 * `simplexml_load_file` on a real 1C export is how an import dies: a catalogue
 * of forty thousand products is a hundred megabytes of XML, and the DOM of it is
 * several times that. So products are pulled one `<Товар>` at a time and only
 * that one element is ever built into a tree.
 *
 * The classifier is read whole (`document()`) because it is small — the tree and
 * the property vocabulary, not the goods — and because it is asked cross-wise:
 * a group's parent, a property's variants.
 */
class Reader
{
    /**
     * Every element of that name, in document order, as a Node.
     *
     * `$skip` is the protocol's own slicing: 1C calls `mode=import` again while
     * the shop answers "progress", and each call picks up where the last one
     * stopped. Skipping re-parses the earlier elements but never builds them
     * into trees, which is the whole cost saved.
     *
     * @return Generator<int, Node>
     */
    public static function stream(string $path, string $tag, int $skip = 0, ?int $limit = null): Generator
    {
        $name = Tags::of($tag);
        $reader = new XMLReader();

        if (! $reader->open($path)) {
            throw new RuntimeException("Cannot read {$path}");
        }

        $document = new DOMDocument();
        $index = 0;
        $taken = 0;

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== $name) {
                    continue;
                }

                if ($index++ < $skip) {
                    // Past the slice already done: step over the subtree without
                    // building it. `next()` is what makes skipping cheap.
                    $reader->next();

                    continue;
                }

                $element = simplexml_import_dom($document->importNode($reader->expand(), true));

                if ($element !== null) {
                    yield $index - 1 => new Node($element);
                    $taken++;
                }

                if ($limit !== null && $taken >= $limit) {
                    return;
                }

                $reader->next();
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * How many such elements the file holds.
     *
     * Counted rather than remembered, because the answer is what tells 1C how
     * far along the import is, and a number carried in a session outlives the
     * file it was about.
     */
    public static function count(string $path, string $tag): int
    {
        $name = Tags::of($tag);
        $reader = new XMLReader();

        if (! $reader->open($path)) {
            return 0;
        }

        $count = 0;

        try {
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === $name) {
                    $count++;
                    $reader->next();
                }
            }
        } finally {
            $reader->close();
        }

        return $count;
    }

    /**
     * The whole document, for the small files.
     *
     * Errors are captured rather than warned about: a truncated upload is an
     * ordinary event in this protocol (1C sends files in pieces), and the
     * caller has to be able to answer "failure" with a sentence rather than
     * emit a PHP warning into a response 1C parses as the protocol.
     */
    public static function document(string $path): ?Node
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_file($path);

            return $xml === false ? null : new Node($xml);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
