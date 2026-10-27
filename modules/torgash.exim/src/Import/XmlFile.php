<?php

namespace Torgash\Exim\Import;

use DOMElement;
use DOMNode;
use Generator;
use RuntimeException;
use XMLReader;

/**
 * An XML file read as a table (PLAN §58): a supplier's YML feed (Yandex
 * Market's `<offer>`s), a marketplace export, any file of repeated records.
 *
 * One RECORD is one row. Which element is a record is worked out — the
 * element repeated most that has insides of its own and is not inside
 * another such (a YML `<offer>`, not the `<param>`s in it; a CommerceML
 * `<Товар>`, not its `<ЗначенияСвойства>`) — and the admin can choose
 * another (`xml_record`).
 *
 * A record is flattened into columns by the names in the file, so the mapping
 * screen shows what the supplier wrote:
 *   - `@id`               an attribute of the record;
 *   - `price`             a child with text; a repeated one (`picture`) is one
 *                         column, its values joined by ", ";
 *   - `param [Цвет]`      a child carrying a `name` attribute — YML's
 *                         characteristics; the bracket is what "a
 *                         characteristic named by the column" reads;
 *   - `price@currency`    an attribute of a child;
 *   - `dimensions/width`  a child inside a child.
 * When the file has a `<categories>` list (YML), a `category` column is added
 * after `categoryId`: the category's whole path, levels joined the way the
 * import splits them, so the shop's tree is rebuilt as the supplier has it.
 *
 * Read with XMLReader, a record at a time: a feed of a hundred thousand
 * offers never sits in memory whole. The encoding is the file's own
 * declaration (a YML in windows-1251 is common); the cells come out UTF-8.
 */
final class XmlFile extends ImportFile
{
    /** Records read to work out the columns are all of them; the record element from this many nodes. */
    protected const SNIFF_NODES = 50000;

    /** @var array{columns: list<string>, count: int}|null worked out once per file */
    private ?array $scan = null;

    /** @var array<string, string>|null category id => path */
    private ?array $categories = null;

    private function __construct(
        public readonly string $path,
        public readonly string $record,
        public readonly string $categoryDelimiter,
    ) {}

    public static function looksLikeXml(string $head): bool
    {
        $head = ltrim(preg_replace('/^\x{FEFF}/u', '', $head) ?? $head);

        return str_starts_with($head, '<?xml') || (bool) preg_match('/^<[A-Za-z_\x{80}-\x{10FFFF}][^>]*>/u', $head);
    }

    public static function open(string $path, ?string $record = null, string $categoryDelimiter = '///'): self
    {
        $candidates = self::candidates($path);

        if ($candidates === []) {
            throw new RuntimeException(__('Nothing repeats in this XML file — there are no records to import.'));
        }

        $record = $record !== null && isset($candidates[$record]) ? $record : array_key_first($candidates);

        return new self($path, $record, $categoryDelimiter !== '' ? $categoryDelimiter : '///');
    }

    /**
     * Elements that could be the records, the likeliest first: name => how
     * many. An element met more than once that has elements inside it; one
     * inside another such goes after those that are not (an `<offer>` before
     * the `<delivery-options>` in it).
     *
     * @return array<string, int>
     */
    public static function candidates(string $path): array
    {
        $reader = self::reader($path);
        $counts = [];
        $parents = [];
        $ancestors = [];
        $stack = [];
        $nodes = 0;

        try {
            while (@$reader->read()) {
                if ($reader->nodeType === XMLReader::END_ELEMENT) {
                    array_pop($stack);

                    continue;
                }

                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }

                $name = $reader->name;
                $counts[$name] = ($counts[$name] ?? 0) + 1;
                $ancestors[$name] ??= $stack;

                if ($stack !== []) {
                    $parents[end($stack)] = true;
                }

                if (! $reader->isEmptyElement) {
                    $stack[] = $name;
                }

                if (++$nodes >= self::SNIFF_NODES) {
                    break;
                }
            }
        } finally {
            $reader->close();
        }

        $repeated = array_filter($counts, fn (int $n, string $name) => $n > 1 && isset($parents[$name]), ARRAY_FILTER_USE_BOTH);
        $nested = fn (string $name) => array_intersect($ancestors[$name], array_keys($repeated)) === [] ? 0 : 1;

        uksort($repeated, fn ($a, $b) => [$nested($a), -$repeated[$a], $a] <=> [$nested($b), -$repeated[$b], $b]);

        return $repeated;
    }

    public function settings(): array
    {
        return ['xml_record' => $this->record];
    }

    /** Every column any record has, in the order they first appear. */
    public function header(): array
    {
        return $this->scan()['columns'];
    }

    public function count(): int
    {
        return $this->scan()['count'];
    }

    /** @return Generator<int, list<string>> */
    public function rows(): Generator
    {
        $columns = array_flip($this->header());
        $n = 0;

        foreach ($this->records() as $cells) {
            $row = array_fill(0, count($columns), '');

            foreach ($cells as $column => $value) {
                if (isset($columns[$column])) {
                    $row[$columns[$column]] = $value;
                }
            }

            yield ++$n => $row;
        }
    }

    /** @return array{columns: list<string>, count: int} */
    private function scan(): array
    {
        if ($this->scan !== null) {
            return $this->scan;
        }

        $columns = [];
        $count = 0;

        foreach ($this->records() as $cells) {
            $count++;

            foreach (array_keys($cells) as $column) {
                $columns[$column] = true;
            }
        }

        return $this->scan = ['columns' => array_map('strval', array_keys($columns)), 'count' => $count];
    }

    /**
     * Each record as column => value.
     *
     * @return Generator<int, array<string, string>>
     */
    private function records(): Generator
    {
        $categories = $this->categories();
        $reader = self::reader($this->path);

        try {
            while (@$reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== $this->record) {
                    continue;
                }

                $node = $reader->expand();

                if (! $node instanceof DOMElement) {
                    continue;
                }

                $cells = [];
                $this->flatten($node, '', $cells, true);

                if ($categories !== [] && isset($cells['categoryId'])) {
                    $cells = $this->withCategory($cells, $categories);
                }

                yield array_map(fn (array $values) => implode(', ', $values), $cells);
            }
        } finally {
            $reader->close();
        }
    }

    /** @param array<string, list<string>> $cells */
    private function flatten(DOMElement $element, string $prefix, array &$cells, bool $isRecord = false): void
    {
        foreach ($element->attributes ?? [] as $attribute) {
            $cells[($isRecord ? '' : rtrim($prefix, '/')).'@'.$attribute->nodeName][] = trim($attribute->nodeValue ?? '');
        }

        foreach ($element->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $name = $prefix.$child->nodeName;
            $hasElements = self::hasElementChildren($child);

            if (! $hasElements && $child->hasAttribute('name')) {
                // YML's <param name="Цвет" unit="см">: the name picks the column.
                $column = $name.' ['.trim($child->getAttribute('name')).']';
                $value = self::text($child);
                $unit = trim($child->getAttribute('unit'));
                $cells[$column][] = $unit !== '' && $value !== '' ? $value.' '.$unit : $value;

                continue;
            }

            if ($hasElements) {
                $this->flatten($child, $name.'/', $cells);

                continue;
            }

            // An empty element that only carries attributes (`<option cost="0"/>`)
            // says it all in them.
            if (self::text($child) !== '' || ! $child->hasAttributes()) {
                $cells[$name][] = self::text($child);
            }

            foreach ($child->attributes ?? [] as $attribute) {
                $cells[$name.'@'.$attribute->nodeName][] = trim($attribute->nodeValue ?? '');
            }
        }
    }

    /**
     * `category` beside `categoryId`, keeping the record's column order.
     *
     * @param  array<string, list<string>>  $cells
     * @param  array<string, string>  $categories
     */
    private function withCategory(array $cells, array $categories): array
    {
        $out = [];

        foreach ($cells as $column => $values) {
            $out[$column] = $values;

            if ($column === 'categoryId') {
                $out['category'] = array_values(array_filter(array_map(fn ($id) => $categories[trim($id)] ?? '', $values)));
            }
        }

        return $out;
    }

    /**
     * A YML `<categories>` list as id => path ("Phones///Smartphones"); empty
     * when the file has none.
     *
     * @return array<string, string>
     */
    private function categories(): array
    {
        if ($this->categories !== null) {
            return $this->categories;
        }

        $names = [];
        $parents = [];
        $reader = self::reader($this->path);

        try {
            while (@$reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }

                // The list comes before the records; past the first record, there is none.
                if ($reader->name === $this->record) {
                    break;
                }

                if ($reader->name === 'category' && ($id = $reader->getAttribute('id')) !== null) {
                    $parents[$id] = $reader->getAttribute('parentId');
                    $names[$id] = trim($reader->readString());
                }
            }
        } finally {
            $reader->close();
        }

        $paths = [];
        foreach (array_keys($names) as $id) {
            $path = [];
            $seen = [];

            // Up to the root; a loop in a broken file ends at the second visit.
            for ($at = (string) $id; $at !== '' && isset($names[$at]) && ! isset($seen[$at]); $at = (string) ($parents[$at] ?? '')) {
                $seen[$at] = true;
                array_unshift($path, str_replace($this->categoryDelimiter, ' ', $names[$at]));
            }

            $paths[(string) $id] = implode($this->categoryDelimiter, $path);
        }

        return $this->categories = $paths;
    }

    private static function hasElementChildren(DOMNode $node): bool
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                return true;
            }
        }

        return false;
    }

    private static function text(DOMNode $node): string
    {
        return trim((string) $node->textContent);
    }

    private static function reader(string $path): XMLReader
    {
        $reader = XMLReader::open($path, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);

        if (! $reader) {
            throw new RuntimeException(__('This XML file cannot be read.'));
        }

        return $reader;
    }
}
