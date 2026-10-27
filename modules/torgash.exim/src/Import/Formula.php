<?php

namespace Torgash\Exim\Import;

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
 * Parsed by hand, never eval'd: the formula is typed in the admin, but it is
 * stored in a preset that outlives whoever typed it, and it runs inside the
 * import job. A number in a cell is read the way people write one ("1 299,50");
 * a sum over an EMPTY cell leaves the cell empty — a missing purchase price is
 * not a price of 0.
 */
class Formula
{
    public const MAX_LENGTH = 500;

    /** name => [min arguments, max arguments (null: any)] */
    public const FUNCTIONS = [
        'round' => [1, 2], 'roundup' => [1, 2], 'rounddown' => [1, 2], 'ending' => [2, 2],
        'min' => [1, null], 'max' => [1, null], 'abs' => [1, 1],
        'if' => [3, 3], 'empty' => [1, 1], 'contains' => [2, 2],
        'concat' => [1, null], 'replace' => [3, 3], 'lower' => [1, 1], 'upper' => [1, 1],
        'trim' => [1, 1], 'left' => [2, 2], 'right' => [2, 2], 'col' => [1, 1],
        // The names other importers use, for old habits.
        'sum' => [2, 2], 'sub' => [2, 2], 'mul' => [2, 2], 'div' => [2, 2],
    ];

    protected const BINARY = ['+' => 3, '-' => 3, '*' => 4, '/' => 4, '&' => 2, '=' => 1, '!=' => 1, '<' => 1, '>' => 1, '<=' => 1, '>=' => 1];

    /** @var list<array{0: string, 1: mixed}> */
    protected array $tokens = [];

    protected int $at = 0;

    protected function __construct(public readonly string $text, protected array $tree) {}

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

        $parser = new self($text, []);
        $parser->tokens = self::tokens($text);
        $tree = $parser->expression(0);

        if ($parser->at < count($parser->tokens)) {
            throw new FormulaException('Unexpected ":token" in the formula.', ['token' => $parser->tokens[$parser->at][1]]);
        }

        return new self($text, $tree);
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
            return self::text($this->evaluate($this->tree, $value, $columns));
        } catch (EmptyOperand) {
            return '';
        }
    }

    /** @return list<array{0: string, 1: mixed}> [kind, value] */
    protected static function tokens(string $text): array
    {
        $tokens = [];
        $length = strlen($text);
        $i = 0;

        while ($i < $length) {
            $char = $text[$i];

            if (ctype_space($char)) {
                $i++;
            } elseif (ctype_digit($char) || ($char === '.' && ctype_digit($text[$i + 1] ?? ''))) {
                preg_match('/\G\d*(?:\.\d+)?/', $text, $m, 0, $i);
                $tokens[] = ['number', (float) $m[0]];
                $i += strlen($m[0]);
            } elseif ($char === '"' || $char === "'") {
                $string = '';
                $i++;
                while (true) {
                    if ($i >= $length) {
                        throw new FormulaException('A text in quotes is not closed.');
                    }
                    if ($text[$i] === '\\' && $i + 1 < $length) {
                        $string .= $text[$i + 1];
                        $i += 2;
                    } elseif ($text[$i] === $char) {
                        $i++;
                        break;
                    } else {
                        $string .= $text[$i++];
                    }
                }
                $tokens[] = ['string', $string];
            } elseif ($char === '$') {
                preg_match('/\G\$[A-Za-z_]*/', $text, $m, 0, $i);
                if (strtolower($m[0]) !== '$value') {
                    throw new FormulaException('":name" is not known — the cell is $value, another column is col("Its name").', ['name' => $m[0]]);
                }
                $tokens[] = ['value', null];
                $i += strlen($m[0]);
            } elseif (ctype_alpha($char) || $char === '_') {
                preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $text, $m, 0, $i);
                $tokens[] = ['name', strtolower($m[0])];
                $i += strlen($m[0]);
            } elseif (in_array($two = substr($text, $i, 2), ['!=', '<>', '<=', '>=', '=='], true)) {
                $tokens[] = ['op', match ($two) { '<>' => '!=', '==' => '=', default => $two }];
                $i += 2;
            } elseif (str_contains('+-*/&=<>', $char)) {
                $tokens[] = ['op', $char];
                $i++;
            } elseif (str_contains('(),;', $char)) {
                // `;` separates arguments too: a spreadsheet's habit in Russian.
                $tokens[] = ['punct', $char === ';' ? ',' : $char];
                $i++;
            } else {
                throw new FormulaException('Unexpected ":token" in the formula.', ['token' => mb_substr(substr($text, $i), 0, 1)]);
            }
        }

        return $tokens;
    }

    /** Precedence climbing: comparisons, then &, then + -, then * /. */
    protected function expression(int $minimum): array
    {
        $left = $this->unary();

        while (($token = $this->tokens[$this->at] ?? null) && $token[0] === 'op' && self::BINARY[$token[1]] >= $minimum) {
            $this->at++;
            $right = $this->expression(self::BINARY[$token[1]] + 1);
            $left = ['op', $token[1], $left, $right];
        }

        return $left;
    }

    protected function unary(): array
    {
        $token = $this->tokens[$this->at] ?? null;

        if ($token === null) {
            throw new FormulaException('The formula ends too early.');
        }

        if ($token === ['op', '-']) {
            $this->at++;

            return ['neg', $this->unary()];
        }

        $this->at++;

        return match ($token[0]) {
            'number', 'string' => ['literal', $token[1]],
            'value' => ['value'],
            'name' => $this->call($token[1]),
            'punct' => $token[1] === '(' ? $this->group() : throw new FormulaException('Unexpected ":token" in the formula.', ['token' => $token[1]]),
            default => throw new FormulaException('Unexpected ":token" in the formula.', ['token' => $token[1]]),
        };
    }

    protected function group(): array
    {
        $inner = $this->expression(0);
        $this->expect(')');

        return $inner;
    }

    protected function call(string $name): array
    {
        if (! isset(self::FUNCTIONS[$name])) {
            throw new FormulaException('There is no function ":name".', ['name' => $name]);
        }

        $this->expect('(');
        $arguments = [];

        if (($this->tokens[$this->at] ?? null) !== ['punct', ')']) {
            do {
                $arguments[] = $this->expression(0);
            } while ($this->accept(','));
        }
        $this->expect(')');

        [$min, $max] = self::FUNCTIONS[$name];
        if (count($arguments) < $min || ($max !== null && count($arguments) > $max)) {
            throw new FormulaException('Wrong number of arguments for :name() — it takes :count.', [
                'name' => $name,
                'count' => $max === null ? "{$min}+" : ($min === $max ? (string) $min : "{$min}–{$max}"),
            ]);
        }

        return ['call', $name, $arguments];
    }

    protected function accept(string $punct): bool
    {
        if (($this->tokens[$this->at] ?? null) === ['punct', $punct]) {
            $this->at++;

            return true;
        }

        return false;
    }

    protected function expect(string $punct): void
    {
        if (! $this->accept($punct)) {
            throw new FormulaException('":char" is missing in the formula.', ['char' => $punct]);
        }
    }

    protected function evaluate(array $node, string $value, array $columns): mixed
    {
        return match ($node[0]) {
            'literal' => $node[1],
            'value' => $value,
            'neg' => -self::number($this->evaluate($node[1], $value, $columns)),
            'op' => $this->operate($node[1], $this->evaluate($node[2], $value, $columns), $this->evaluate($node[3], $value, $columns)),
            'call' => $this->invoke($node[1], $node[2], $value, $columns),
        };
    }

    protected function operate(string $op, mixed $a, mixed $b): mixed
    {
        if ($op === '&') {
            return self::text($a).self::text($b);
        }

        if (in_array($op, ['=', '!=', '<', '>', '<=', '>='], true)) {
            $na = self::maybeNumber($a);
            $nb = self::maybeNumber($b);
            $order = $na !== null && $nb !== null ? $na <=> $nb : strcmp(mb_strtolower(self::text($a)), mb_strtolower(self::text($b)));

            return match ($op) {
                '=' => $order === 0, '!=' => $order !== 0, '<' => $order < 0,
                '>' => $order > 0, '<=' => $order <= 0, '>=' => $order >= 0,
            };
        }

        $a = self::number($a);
        $b = self::number($b);

        return match ($op) {
            '+' => $a + $b,
            '-' => $a - $b,
            '*' => $a * $b,
            '/' => $b == 0 ? throw new FormulaException('Division by zero.') : $a / $b,
        };
    }

    protected function invoke(string $name, array $arguments, string $value, array $columns): mixed
    {
        // `if` works out only the side it takes: the other may be a sum over an empty cell.
        if ($name === 'if') {
            return $this->evaluate($arguments[self::truthy($this->evaluate($arguments[0], $value, $columns)) ? 1 : 2], $value, $columns);
        }

        $args = array_map(fn (array $node) => $this->evaluate($node, $value, $columns), $arguments);

        return match ($name) {
            'round' => round(self::number($args[0]), (int) self::number($args[1] ?? 0)),
            'roundup' => self::step(self::number($args[0]), (int) self::number($args[1] ?? 0), 'ceil'),
            'rounddown' => self::step(self::number($args[0]), (int) self::number($args[1] ?? 0), 'floor'),
            'ending' => self::ending(self::number($args[0]), self::number($args[1])),
            'min' => min(array_map([self::class, 'number'], $args)),
            'max' => max(array_map([self::class, 'number'], $args)),
            'abs' => abs(self::number($args[0])),
            'empty' => trim(self::text($args[0])) === '',
            'contains' => self::text($args[1]) !== '' && mb_stripos(self::text($args[0]), self::text($args[1])) !== false,
            'concat' => implode('', array_map([self::class, 'text'], $args)),
            'replace' => str_replace(self::text($args[1]), self::text($args[2]), self::text($args[0])),
            'lower' => mb_strtolower(self::text($args[0])),
            'upper' => mb_strtoupper(self::text($args[0])),
            'trim' => trim(self::text($args[0])),
            'left' => mb_substr(self::text($args[0]), 0, max(0, (int) self::number($args[1]))),
            'right' => ($n = max(0, (int) self::number($args[1]))) === 0 ? '' : mb_substr(self::text($args[0]), -$n),
            'col' => self::column(self::text($args[0]), $columns),
            'sum' => self::number($args[0]) + self::number($args[1]),
            'sub' => self::number($args[0]) - self::number($args[1]),
            'mul' => self::number($args[0]) * self::number($args[1]),
            'div' => $this->operate('/', $args[0], $args[1]),
        };
    }

    /** The nearest price at or above `$amount` that ends in `$end`: ending(1234, 90) = 1290, ending(1234, 9) = 1239. */
    protected static function ending(float $amount, float $end): float
    {
        $step = $end >= 1 ? 10 ** strlen((string) (int) $end) : 1;
        $price = floor(round($amount / $step, 9)) * $step + $end;

        return round($price, 9) < round($amount, 9) ? $price + $step : $price;
    }

    /** Up or down to `$digits` places; a negative count rounds to tens, hundreds. */
    protected static function step(float $amount, int $digits, callable $direction): float
    {
        $scale = 10 ** $digits;

        return $direction(round($amount * $scale, 9)) / $scale;
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

    protected static function number(mixed $value): float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }
        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }
        if (trim((string) $value) === '') {
            throw new EmptyOperand;
        }

        return self::maybeNumber($value)
            ?? throw new FormulaException('":value" is not a number.', ['value' => mb_substr((string) $value, 0, 40)]);
    }

    /** "1 299,50", "1,299.50", "990 ₽" — a person's way of writing a number; null for words. */
    protected static function maybeNumber(mixed $value): ?float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }
        if (is_bool($value)) {
            return null;
        }

        $text = preg_replace('/[\s\x{00A0}\x{202F}]/u', '', trim((string) $value)) ?? '';
        // A currency sign beside it is not part of the number.
        $text = preg_replace('/^[^\d\-.,]+|[^\d.,]+$/u', '', $text) ?? $text;

        if ($text === '' || ! preg_match('/^-?[\d.,]+$/', $text)) {
            return null;
        }

        // The LAST separator is the decimal one; any before it group thousands.
        $text = preg_match('/^(.*)[.,](\d{1,2})$/', $text, $m)
            ? str_replace([',', '.'], '', $m[1]).'.'.$m[2]
            : str_replace([',', '.'], '', $text);

        return is_numeric($text) ? (float) $text : null;
    }

    protected static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_float($value) || is_int($value)) {
            return $value != 0;
        }

        return ! in_array(trim((string) $value), ['', '0'], true);
    }

    /** What the cell becomes: a number without a trailing ".00", yes/no as 1/0. */
    protected static function text(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_float($value) || is_int($value)) {
            $value = round((float) $value, 4);

            return abs($value - round($value)) < 1e-9
                ? (string) (int) round($value)
                : rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
        }

        return (string) $value;
    }
}
