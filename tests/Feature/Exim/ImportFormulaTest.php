<?php

namespace Tests\Feature\Exim;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Torgash\Exim\Import\Formula;
use Torgash\Exim\Import\FormulaException;

/**
 * A column's formula (a "modifier" elsewhere): read by hand, never eval'd, and
 * it reads the numbers people write.
 */
class ImportFormulaTest extends TestCase
{
    public static function answers(): array
    {
        return [
            'markup' => ['$value * 1.25', '1 000', '1250'],
            'ends in 90' => ['ending($value * 1.2, 90)', '1 028,50', '1290'],
            'already ends in 90' => ['ending($value, 90)', '1290', '1290'],
            'kopecks' => ['ending($value, 0.99)', '12.3', '12.99'],
            'nested if, ; as a separator' => ['if($value > 10000; $value * 1.1; $value * 1.3)', '20000', '22000'],
            'joins text' => ['"SUP-" & $value', 'A1', 'SUP-A1'],
            'replace' => ['replace($value, "Apple ", "")', 'Apple iPhone', 'iPhone'],
            'another column' => ['col("закупка") * 1.5', 'x', '1500'],
            'to hundreds' => ['round($value, -2)', '1249', '1200'],
            'up to tens' => ['roundup($value, -1)', '1231', '1240'],
            'a currency sign is not the number' => ['mul($value, 1.2)', '990 ₽', '1188'],
            'precedence' => ['2 + 3 * 4 - -1', '', '15'],
            'brackets' => ['(2+3)*4', '', '20'],
            'compares words without case' => ['if($value = "да", "Yes", "No")', 'Да', 'Yes'],
            'text functions nest' => ['upper(left($value, 3))', 'abcdef', 'ABC'],
            'decimals kept, zeros dropped' => ['$value / 4', '10', '2.5'],
            'an empty cell stays empty' => ['$value * 2', '', ''],
            'if takes only its own side' => ['if(empty($value), "none", $value * 2)', '', 'none'],
        ];
    }

    #[DataProvider('answers')]
    public function test_works_out(string $formula, string $cell, string $expected): void
    {
        $this->assertSame($expected, Formula::parse($formula)->apply($cell, ['Закупка' => '1 000']));
    }

    public static function mistakes(): array
    {
        return [
            'unknown function' => ['foo($value)', 'There is no function'],
            'ends early' => ['$value *', 'ends too early'],
            'unknown variable' => ['$price * 2', 'is not known'],
            'arguments' => ['round()', 'Wrong number of arguments'],
            'unclosed text' => ['"abc', 'not closed'],
            'unclosed bracket' => ['round($value', 'is missing'],
            'no code runs' => ['system("dir")', 'There is no function'],
        ];
    }

    #[DataProvider('mistakes')]
    public function test_a_mistake_is_named(string $formula, string $words): void
    {
        try {
            Formula::parse($formula);
            $this->fail('Parsed: '.$formula);
        } catch (FormulaException $e) {
            $this->assertStringContainsString($words, $e->getMessage());
        }
    }

    public function test_a_row_that_cannot_be_worked_out_says_why(): void
    {
        $this->expectException(FormulaException::class);
        $this->expectExceptionMessage('is not a number');

        Formula::parse('$value * 2')->apply('по запросу');
    }
}
