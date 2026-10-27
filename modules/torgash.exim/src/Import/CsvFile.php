<?php

namespace Torgash\Exim\Import;

use Generator;
use RuntimeException;

/**
 * A CSV file as somebody actually saved it (PLAN §58). The importer used to
 * know one spelling — commas, UTF-8, our own headers — and a file from another engine
 * (`;`) arrived as one column per row with nothing in it.
 *
 * The delimiter and the encoding are WORKED OUT from the first lines and
 * shown, and the admin can overrule both: a guess is a default, never the
 * last word. Windows-1251 is the one legacy encoding that matters here —
 * Excel in a Russian Windows still saves "CSV" in it.
 *
 * Quotes follow RFC 4180 (a quote inside a cell is doubled) with no escape
 * character, which is what Excel and LibreOffice write; PHP's
 * default backslash escape broke a JSON cell (`\"` ended the cell early).
 */
final class CsvFile extends ImportFile
{
    /** What the admin can choose from, in the order the guess tries them. */
    public const DELIMITERS = [
        ';' => 'Semicolon (;)',
        ',' => 'Comma (,)',
        "\t" => 'Tab',
        '|' => 'Vertical bar (|)',
    ];

    public const ENCODINGS = ['UTF-8' => 'UTF-8', 'Windows-1251' => 'Windows-1251'];

    private function __construct(
        public readonly string $path,
        public readonly string $delimiter,
        public readonly string $encoding,
    ) {}

    /** The file read with these settings, or with what its first lines suggest. */
    public static function open(string $path, ?string $delimiter = null, ?string $encoding = null): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException(__('The import file is gone — upload it again.'));
        }

        $head = (string) file_get_contents($path, false, null, 0, 65536);

        $delimiter = isset(self::DELIMITERS[(string) $delimiter]) ? $delimiter : self::guessDelimiter($head);
        $encoding = isset(self::ENCODINGS[(string) $encoding]) ? $encoding : self::guessEncoding($head);

        return new self($path, $delimiter, $encoding);
    }

    /**
     * UTF-8 if the bytes are valid UTF-8 (a cut-off last character of the
     * sample aside), Windows-1251 otherwise — the only other one a shop in
     * this part of the world hands over.
     */
    public static function guessEncoding(string $head): string
    {
        // The sample may end in the middle of a character: forgive up to three
        // bytes of one, never more.
        for ($cut = 0; $cut <= 3 && $cut < strlen($head); $cut++) {
            if (mb_check_encoding($cut ? substr($head, 0, -$cut) : $head, 'UTF-8')) {
                return 'UTF-8';
            }
        }

        return 'Windows-1251';
    }

    /**
     * The candidate that splits the first lines into the same number of
     * cells, the most cells winning; quoted text does not count, so a comma
     * inside a description does not make a semicolon file look like commas.
     */
    public static function guessDelimiter(string $head): string
    {
        $lines = array_slice(self::logicalLines($head), 0, 6);

        if ($lines === []) {
            return ',';
        }

        // The last line of the sample may be cut short.
        if (count($lines) > 2) {
            array_pop($lines);
        }

        $best = ',';
        $bestScore = 0;

        foreach (array_keys(self::DELIMITERS) as $candidate) {
            $counts = array_map(fn (string $line) => self::countOutsideQuotes($line, $candidate), $lines);
            $header = $counts[0];

            if ($header === 0) {
                continue;
            }

            // Every line splitting like the header is what a real delimiter
            // does; a count that only the header has is a word in a title.
            $agreeing = count(array_filter($counts, fn (int $n) => $n === $header));
            $score = $header * 10 + $agreeing * 1000;

            if ($score > $bestScore) {
                [$best, $bestScore] = [$candidate, $score];
            }
        }

        return $best;
    }

    /** The first row: the column names, without a byte-order mark. */
    public function header(): array
    {
        foreach ($this->lines() as $line) {
            return array_map(fn ($cell) => trim((string) $cell), $line);
        }

        return [];
    }

    /**
     * Every row after the header, numbered from 1 (the line a spreadsheet
     * calls 2), as lists of cells in UTF-8. Blank lines are skipped.
     *
     * @return Generator<int, list<string>>
     */
    public function rows(): Generator
    {
        $n = 0;

        foreach ($this->lines() as $i => $line) {
            if ($i === 0) {
                continue;
            }

            if ($line === [null] || implode('', array_map('strval', $line)) === '') {
                continue;
            }

            yield ++$n => array_map('strval', $line);
        }
    }

    public function settings(): array
    {
        return ['delimiter' => $this->delimiter, 'encoding' => $this->encoding];
    }

    /** @return Generator<int, list<?string>> */
    private function lines(): Generator
    {
        $handle = fopen($this->path, 'rb');

        try {
            // A spreadsheet saved "as CSV UTF-8" starts with a byte-order mark;
            // left in, it becomes part of the first column's name.
            if (fread($handle, 3) !== "\xEF\xBB\xBF") {
                rewind($handle);
            }

            $i = 0;

            // Split in the file's own bytes: every delimiter and the quote are
            // ASCII, and Windows-1251 is one byte per letter, so a cell never
            // straddles a delimiter. Converted cell by cell afterwards.
            while (($line = fgetcsv($handle, null, $this->delimiter, '"', '')) !== false) {
                if ($this->encoding !== 'UTF-8') {
                    $line = array_map(fn ($cell) => $cell === null ? null : mb_convert_encoding($cell, 'UTF-8', $this->encoding), $line);
                }

                yield $i++ => $line;
            }
        } finally {
            fclose($handle);
        }
    }

    /** Lines of the sample, a newline inside quotes not ending one. */
    private static function logicalLines(string $head): array
    {
        $head = preg_replace('/^\x{FEFF}/u', '', $head) ?? $head;
        $lines = [];
        $current = '';
        $quoted = false;

        foreach (preg_split('/(\r\n|\n|\r)/', $head, -1, PREG_SPLIT_DELIM_CAPTURE) as $piece) {
            if (! $quoted && preg_match('/^(\r\n|\n|\r)$/', $piece)) {
                if (trim($current) !== '') {
                    $lines[] = $current;
                }
                $current = '';

                continue;
            }

            $current .= $piece;
            $quoted = substr_count($current, '"') % 2 === 1;
        }

        if (trim($current) !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    private static function countOutsideQuotes(string $line, string $delimiter): int
    {
        $count = 0;
        $quoted = false;
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];

            if ($char === '"') {
                $quoted = ! $quoted;
            } elseif (! $quoted && $char === $delimiter) {
                $count++;
            }
        }

        return $count;
    }
}
