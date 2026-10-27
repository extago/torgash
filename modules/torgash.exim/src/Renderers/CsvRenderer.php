<?php

namespace Torgash\Exim\Renderers;

class CsvRenderer implements RendererInterface
{
    /**
     * What a spreadsheet reads as the start of a formula. A customer's name
     * typed as `=HYPERLINK(...)` would otherwise run in the manager's Excel
     * the moment the order export is opened.
     */
    public const FORMULA_STARTS = ['=', '+', '-', '@', "	", ""];

    /**
     * A cell a spreadsheet would run, written as text: an apostrophe in front,
     * which Excel and LibreOffice both hide. A number stays a number — `-5`
     * is a quantity, not a formula. The import takes the apostrophe off again
     * (`ImportRunner::unguard()`), so a file goes round unchanged.
     */
    public static function guard(string $value): string
    {
        if ($value === '' || is_numeric($value) || ! in_array($value[0], self::FORMULA_STARTS, true)) {
            return $value;
        }

        return "'".$value;
    }

    public function render(iterable $rows, array $fieldDefs): \Generator
    {
        yield $this->line(array_column($fieldDefs, 'label'));

        foreach ($rows as $row) {
            yield $this->line(array_map(fn ($f) => $row[$f['key']] ?? '', $fieldDefs));
        }
    }

    protected function line(array $values): string
    {
        $stream = fopen('php://temp', 'w+');
        // RFC 4180: a quote is doubled and there is no escape character, which is
        // what a spreadsheet writes and what Import\CsvFile reads back. PHP's
        // backslash escape left a JSON cell's quotes undoubled.
        fputcsv($stream, array_map(fn ($v) => is_bool($v) ? ($v ? '1' : '0') : self::guard((string) $v), $values), ',', '"', '');
        rewind($stream);
        $line = stream_get_contents($stream);
        fclose($stream);

        return $line;
    }

    public function contentType(): string
    {
        return 'text/csv; charset=UTF-8';
    }
}
