<?php

namespace Torgash\Exim\Import;

use Torgash\Exim\Template\Expression;

/**
 * A column's formula — a "modifier" in other importers (PLAN §58): the cell is
 * changed on the way in, so a supplier's purchase price arrives as the shop's
 * price, "ABC-" goes in front of their codes, "Да" becomes "Yes".
 *
 *     $value * 1.25
 *     ending($value * 1.2, 90)              1 234 → 1 290
 *     if($value > 10000, $value * 1.1, $value * 1.3)
 *     "SUP-" & $value
 *     replace($value, "Apple ", "")
 *     col("Закупка") * 1.5                  another column of the same row
 *
 * The usual modifier is ONE call with flat arguments (`mul($value, 1.2)`), so a
 * markup that ends in 90 could not be written at all; this is an expression:
 * `+ - * /`, `&` joins text, `= != < > <= >=` compare, functions nest, and
 * sum/sub/mul/div are kept too, for habits formed elsewhere.
 *
 * The language is the exchange's one `Template\Expression` (the same the
 * templates' `{% if %}` reads, PLAN §68) with two words of its own: `$value`,
 * the cell, and `col("Name")`, another cell of the row. Parsed by hand,
 * never eval'd: the formula is typed in the admin, but it is stored in a
 * preset that outlives whoever typed it, and it runs inside the import job.
 * A number in a cell is read the way people write one ("1 299,50"); a sum
 * over an EMPTY cell leaves the cell empty — a missing purchase price is not
 * a price of 0.
 */
class Formula
{
    public const MAX_LENGTH = 500;

    /** name => [min arguments, max arguments (null: any)] — the language's, plus `col`. */
    public const FUNCTIONS = Expression::FUNCTIONS + ['col' => [1, 1]];

    protected function __construct(public readonly string $text, protected Expression $expression) {}

    /** @throws FormulaException when it is not a formula — the message says where */
    public static function parse(string $text): self
    {
        $text = trim($text);

        if ($text === '') {
            throw new FormulaException('The formula is empty.');
        }
        if (mb_strlen($text) > self::MAX_LENGTH) {
            throw new FormulaException('A formula is at most :max characters.', ['max' => self::MAX_LENGTH]);
        }

        return new self($text, Expression::parse($text, ['value' => true, 'paths' => false, 'functions' => ['col' => [1, 1]]]));
    }

    /**
     * The cell as the formula makes it. `$columns` is the row by column name,
     * for `col()`.
     *
     * @param  array<string, string>  $columns
     *
     * @throws FormulaException when this row cannot be worked out (not a number, no such column)
     */
    public function apply(string $value, array $columns = []): string
    {
        try {
            return Expression::text($this->expression->evaluate(
                fn (string $path) => $value,
                fn (string $name, array $args) => self::column(Expression::text($args[0]), $columns),
            ));
        } catch (EmptyOperand) {
            return '';
        }
    }

    protected static function column(string $name, array $columns): string
    {
        $wanted = Columns::normal($name);

        foreach ($columns as $column => $cell) {
            if (Columns::normal((string) $column) === $wanted) {
                return (string) $cell;
            }
        }

        throw new FormulaException('The file has no column ":name".', ['name' => $name]);
    }
}
