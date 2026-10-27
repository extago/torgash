<?php

namespace Torgash\Exim\Import;

use Generator;
use RuntimeException;

/**
 * A file the importer reads as a table — a header and rows of cells — however
 * it was written: a CSV (`CsvFile`) or an XML/YML feed (`XmlFile`). The
 * mapping screen, the presets, the formulas and the job see only this, so
 * a supplier's YML is matched to fields exactly like a spreadsheet is.
 */
abstract class ImportFile
{
    /** What the upload form takes. */
    public const EXTENSIONS = ['csv', 'txt', 'tsv', 'xml', 'yml'];

    /**
     * The file opened by what it IS, not by its name: a feed saved as .txt is
     * still XML. `$options` are the draft's — the CSV's delimiter and
     * encoding, the XML's record element and category separator.
     */
    public static function read(string $path, array $options = []): CsvFile|XmlFile
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException(__('The import file is gone — upload it again.'));
        }

        return XmlFile::looksLikeXml((string) file_get_contents($path, false, null, 0, 4096))
            ? XmlFile::open($path, $options['xml_record'] ?? null, (string) ($options['category_delimiter'] ?? '///'))
            : CsvFile::open($path, $options['delimiter'] ?? null, $options['encoding'] ?? null);
    }

    /** @return list<string> */
    abstract public function header(): array;

    /** @return Generator<int, list<string>> */
    abstract public function rows(): Generator;

    /** @return list<list<string>> the first `$count` rows */
    public function sample(int $count = 5): array
    {
        $rows = [];

        foreach ($this->rows() as $row) {
            $rows[] = $row;

            if (count($rows) >= $count) {
                break;
            }
        }

        return $rows;
    }

    public function count(): int
    {
        $n = 0;

        foreach ($this->rows() as $_) {
            $n++;
        }

        return $n;
    }

    /** How the reading settings are kept in the draft: [option => value]. */
    abstract public function settings(): array;
}
