<?php

namespace Torgash\Exim\Template;

use Torgash\Exim\Import\EmptyOperand;
use Torgash\Exim\Import\FormulaException;

/**
 * The ONE expression language of the exchange (PLAN §68): a column's formula
 * on import (`Import\Formula`) and whatever stands inside a template's
 * `{% if %}`, `{% assign %}`, `{{ }}` and filter arguments (`FeedTemplate`).
 * One grammar, one list of functions, one page of documentation.
 *
 *     item.price * 1.2 > 1000 and item.brand == "Apple"
 *     item.name contains "Pro" or contains(item.name, "Max")
 *     if(empty(item.sku), "—", item.sku)
 *     "Купить " & item.name
 *     len(item.params) > 3
 *
 * Operators, loosest first: `or`; `and`; `not`; `= == != <> < > <= >=` and
 * `contains`; `&` joins text; `+ -`; `* /`. Values: numbers, "text" or
 * 'text', true / false, nil, empty, a PATH (`item.price`, `item.params.0.name`,
 * `forloop.index`, `item.name[shop.locale]`), and the functions in FUNCTIONS.
 * Nothing else exists: no method, no object, no file, no PHP — the text is
 * parsed by hand into a tree and walked, never eval'd. A path is looked up
 * through the resolver the caller hands in, so the language knows only what
 * it is given.
 *
 * Numbers are read the way people write them ("1 299,50"); a comparison of
 * two numbers is numeric, of anything else a case-insensitive text one; a sum
 * over an EMPTY value is empty (EmptyOperand), not 0 — a missing purchase
 * price is not a price of 0.
 */
final class Expression
{
    /** name => [min arguments, max arguments (null: any)] */
    public const FUNCTIONS = [
        'round' => [1, 2], 'roundup' => [1, 2], 'rounddown' => [1, 2], 'ending' => [2, 2],
        'min' => [1, null], 'max' => [1, null], 'abs' => [1, 1],
        'if' => [3, 3], 'empty' => [1, 1], 'contains' => [2, 2], 'default' => [2, 2],
        'concat' => [1, null], 'replace' => [3, 3], 'lower' => [1, 1], 'upper' => [1, 1], 'capitalize' => [1, 1],
        'trim' => [1, 1], 'left' => [2, 2], 'right' => [2, 2], 'len' => [1, 1],
        'starts_with' => [2, 2], 'ends_with' => [2, 2],
        'split' => [2, 2], 'join' => [1, 2], 'first' => [1, 1], 'last' => [1, 1],
        // The names other importers use, for old habits.
        'sum' => [2, 2], 'sub' => [2, 2], 'mul' => [2, 2], 'div' => [2, 2],
    ];

    /** Binding power of each binary operator; `not` sits between `and` and the comparisons. */
    protected const BINARY = [
        'or' => 0, 'and' => 1,
        '=' => 3, '!=' => 3, '<' => 3, '>' => 3, '<=' => 3, '>=' => 3, 'contains' => 3,
        '&' => 4, '+' => 5, '-' => 5, '*' => 6, '/' => 6,
    ];

    protected const NOT = 2;

    /** @var list<array{0: string, 1: mixed}> */
    protected array $tokens = [];

    protected int $at = 0;

    /** @var array{value: bool, paths: bool, functions: array<string, array{0: int, 1: ?int}>} */
    protected array $options;

    protected function __construct(public readonly string $text, protected array $tree, array $options)
    {
        $this->options = $options;
    }

    /**
     * @param  array{value?: bool, paths?: bool, functions?: array<string, array{0: int, 1: ?int}>}  $options
     *                                                                                               `value`: `$value` is a word (a formula's cell); `paths`: a bare name is a path
     *                                                                                               (a template's `item.price`); `functions`: the caller's own, with arities (`col`).
     *
     * @throws FormulaException when it is not an expression — the message says where
     */
    public static function parse(string $text, array $options = []): self
    {
        $options += ['value' => false, 'paths' => true, 'functions' => []];
        $text = trim($text);

        if ($text === '') {
            throw new FormulaException('The formula is empty.');
        }

        $parser = new self($text, [], $options);
        $parser->tokens = self::tokens($text, $options);
        $tree = $parser->expression(0);

        if ($parser->at < count($parser->tokens)) {
            throw new FormulaException('Unexpected ":token" in the formula.', ['token' => self::word($parser->tokens[$parser->at])]);
        }

        return new self($text, $tree, $options);
    }

    /**
     * The expression's value. `$resolve` answers a path (`item.price`, `.`,
     * `$value`) with whatever it stands for; `$call` the caller's own
     * functions (`col`) — handed the evaluated arguments.
     *
     * @param  callable(string): mixed  $resolve
     * @param  callable(string, list<mixed>): mixed|null  $call
     *
     * @throws FormulaException when it cannot be worked out (not a number, division by zero)
     * @throws EmptyOperand when arithmetic met an empty value
     */
    public function evaluate(callable $resolve, ?callable $call = null): mixed
    {
        return $this->node($this->tree, $resolve, $call ?? fn () => null);
    }

    /**
     * Every path the expression reads (`item.price`, `shop.name`) — what an
     * export has to fetch for it. Indexed paths give their base.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        $paths = [];
        $walk = function (array $node) use (&$walk, &$paths) {
            switch ($node[0]) {
                case 'path': $paths[] = $node[1]; break;
                case 'index': $walk($node[1]); $walk($node[2]); break;
                case 'neg': case 'not': $walk($node[1]); break;
                case 'op': $walk($node[2]); $walk($node[3]); break;
                case 'call': foreach ($node[2] as $argument) { $walk($argument); } break;
            }
        };
        $walk($this->tree);

        return array_values(array_unique($paths));
    }

    // ------------------------------------------------------------ reading

    /** @return list<array{0: string, 1: mixed}> [kind, value] */
    protected static function tokens(string $text, array $options): array
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
                if (! $options['value'] || strtolower($m[0]) !== '$value') {
                    throw new FormulaException('":name" is not known — the cell is $value, another column is col("Its name").', ['name' => $m[0]]);
                }
                $tokens[] = ['value', null];
                $i += strlen($m[0]);
            } elseif ($char === '.' && ! ctype_alnum($text[$i + 1] ?? '')) {
                // Mustache's "the element itself".
                $tokens[] = ['path', '.'];
                $i++;
            } elseif (ctype_alpha($char) || $char === '_') {
                // A path: words joined by dots, a dash INSIDE a word (a
                // characteristic's code) — so a subtraction wants spaces.
                preg_match('/\G[A-Za-z_][A-Za-z0-9_]*(?:-[A-Za-z0-9_]+)*(?:\.[A-Za-z0-9_]+(?:-[A-Za-z0-9_]+)*)*/', $text, $m, 0, $i);
                $word = $m[0];
                $lower = strtolower($word);
                $i += strlen($word);
                $calls = (bool) preg_match('/\G\s*\(/', $text, $n, 0, $i);

                // and / or / not are words of the language even before a
                // bracket — `not (a > b)`; contains and empty are functions
                // when called, operator and literal otherwise.
                if (in_array($lower, ['and', 'or'], true) || (! $calls && $lower === 'contains')) {
                    $tokens[] = ['op', $lower];
                } elseif ($lower === 'not') {
                    $tokens[] = ['not', null];
                } elseif (! $calls && in_array($lower, ['true', 'false'], true)) {
                    $tokens[] = ['literal', $lower === 'true'];
                } elseif (! $calls && in_array($lower, ['nil', 'null'], true)) {
                    $tokens[] = ['literal', null];
                } elseif (! $calls && $lower === 'empty') {
                    $tokens[] = ['literal', ''];
                } elseif ($calls) {
                    $tokens[] = ['name', $lower];
                } elseif ($options['paths']) {
                    $tokens[] = ['path', $word];
                } else {
                    throw new FormulaException('There is no function ":name".', ['name' => $word]);
                }
            } elseif (in_array($two = substr($text, $i, 2), ['!=', '<>', '<=', '>=', '=='], true)) {
                $tokens[] = ['op', match ($two) { '<>' => '!=', '==' => '=', default => $two }];
                $i += 2;
            } elseif (str_contains('+-*/&=<>', $char)) {
                $tokens[] = ['op', $char];
                $i++;
            } elseif (str_contains('(),;[]', $char)) {
                // `;` separates arguments too: a spreadsheet's habit in Russian.
                $tokens[] = ['punct', $char === ';' ? ',' : $char];
                $i++;
            } else {
                throw new FormulaException('Unexpected ":token" in the formula.', ['token' => mb_substr(substr($text, $i), 0, 1)]);
            }
        }

        return $tokens;
    }

    protected static function word(array $token): string
    {
        return match ($token[0]) {
            'value' => '$value',
            'not' => 'not',
            'literal' => is_bool($token[1]) ? ($token[1] ? 'true' : 'false') : ($token[1] === null ? 'nil' : 'empty'),
            default => (string) $token[1],
        };
    }

    /** Precedence climbing: or, and, not, comparisons, &, + -, * /. */
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

        if ($token[0] === 'not') {
            $this->at++;

            return ['not', $this->expression(self::NOT + 1)];
        }

        $this->at++;

        $node = match ($token[0]) {
            'number', 'string', 'literal' => ['literal', $token[1]],
            'value' => ['path', '$value'],
            'path' => ['path', $token[1]],
            'name' => $this->call($token[1]),
            'punct' => $token[1] === '(' ? $this->group() : throw new FormulaException('Unexpected ":token" in the formula.', ['token' => $token[1]]),
            default => throw new FormulaException('Unexpected ":token" in the formula.', ['token' => self::word($token)]),
        };

        // item.name[shop.locale], item.params[0]
        while ($this->accept('[')) {
            $key = $this->expression(0);
            $this->expect(']');
            $node = ['index', $node, $key];
        }

        return $node;
    }

    protected function group(): array
    {
        $inner = $this->expression(0);
        $this->expect(')');

        return $inner;
    }

    protected function call(string $name): array
    {
        $arity = self::FUNCTIONS[$name] ?? $this->options['functions'][$name] ?? null;

        if ($arity === null) {
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

        [$min, $max] = $arity;
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

    // ------------------------------------------------------------ working out

    protected function node(array $node, callable $resolve, callable $call): mixed
    {
        return match ($node[0]) {
            'literal' => $node[1],
            'path' => $resolve($node[1]),
            'index' => self::at($this->node($node[1], $resolve, $call), $this->node($node[2], $resolve, $call)),
            'neg' => -self::number($this->node($node[1], $resolve, $call)),
            'not' => ! self::truthy($this->node($node[1], $resolve, $call)),
            'op' => $this->operate($node, $resolve, $call),
            'call' => $this->invoke($node[1], $node[2], $resolve, $call),
        };
    }

    protected static function at(mixed $list, mixed $key): mixed
    {
        if (! is_array($list)) {
            return null;
        }
        $key = is_bool($key) ? (int) $key : $key;
        if (is_float($key)) {
            $key = (int) $key;
        }

        return is_int($key) || is_string($key) ? ($list[$key] ?? null) : null;
    }

    protected function operate(array $node, callable $resolve, callable $call): mixed
    {
        $op = $node[1];

        // and / or work out only what they must: the right side may be a
        // sum over an empty value.
        if ($op === 'and') {
            return self::truthy($this->node($node[2], $resolve, $call)) && self::truthy($this->node($node[3], $resolve, $call));
        }
        if ($op === 'or') {
            return self::truthy($this->node($node[2], $resolve, $call)) || self::truthy($this->node($node[3], $resolve, $call));
        }

        $a = $this->node($node[2], $resolve, $call);
        $b = $this->node($node[3], $resolve, $call);

        if ($op === '&') {
            return self::text($a).self::text($b);
        }

        if ($op === 'contains') {
            return self::contains($a, $b);
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

    protected function invoke(string $name, array $arguments, callable $resolve, callable $call): mixed
    {
        // `if` and `default` work out only the side they take.
        if ($name === 'if') {
            return $this->node($arguments[self::truthy($this->node($arguments[0], $resolve, $call)) ? 1 : 2], $resolve, $call);
        }
        if ($name === 'default') {
            $first = $this->node($arguments[0], $resolve, $call);

            return self::truthy($first) ? $first : $this->node($arguments[1], $resolve, $call);
        }

        $args = array_map(fn (array $node) => $this->node($node, $resolve, $call), $arguments);

        if (! isset(self::FUNCTIONS[$name])) {
            return $call($name, $args);
        }

        return match ($name) {
            'round' => round(self::number($args[0]), (int) self::number($args[1] ?? 0)),
            'roundup' => self::step(self::number($args[0]), (int) self::number($args[1] ?? 0), 'ceil'),
            'rounddown' => self::step(self::number($args[0]), (int) self::number($args[1] ?? 0), 'floor'),
            'ending' => self::ending(self::number($args[0]), self::number($args[1])),
            'min' => min(array_map([self::class, 'number'], $args)),
            'max' => max(array_map([self::class, 'number'], $args)),
            'abs' => abs(self::number($args[0])),
            'empty' => ! self::truthy($args[0]),
            'contains' => self::contains($args[0], $args[1]),
            'concat' => implode('', array_map([self::class, 'text'], $args)),
            'replace' => str_replace(self::text($args[1]), self::text($args[2]), self::text($args[0])),
            'lower' => mb_strtolower(self::text($args[0])),
            'upper' => mb_strtoupper(self::text($args[0])),
            'capitalize' => self::capitalize(self::text($args[0])),
            'trim' => trim(self::text($args[0])),
            'left' => mb_substr(self::text($args[0]), 0, max(0, (int) self::number($args[1]))),
            'right' => ($n = max(0, (int) self::number($args[1]))) === 0 ? '' : mb_substr(self::text($args[0]), -$n),
            'len' => is_array($args[0]) ? count($args[0]) : mb_strlen(self::text($args[0])),
            'starts_with' => self::text($args[1]) !== '' && str_starts_with(mb_strtolower(self::text($args[0])), mb_strtolower(self::text($args[1]))),
            'ends_with' => self::text($args[1]) !== '' && str_ends_with(mb_strtolower(self::text($args[0])), mb_strtolower(self::text($args[1]))),
            'split' => self::split(self::text($args[0]), self::text($args[1])),
            'join' => is_array($args[0]) ? implode(self::text($args[1] ?? ', '), array_map([self::class, 'text'], $args[0])) : self::text($args[0]),
            'first' => is_array($args[0]) ? ($args[0] === [] ? null : reset($args[0])) : mb_substr(self::text($args[0]), 0, 1),
            'last' => is_array($args[0]) ? ($args[0] === [] ? null : end($args[0])) : mb_substr(self::text($args[0]), -1),
            'sum' => self::number($args[0]) + self::number($args[1]),
            'sub' => self::number($args[0]) - self::number($args[1]),
            'mul' => self::number($args[0]) * self::number($args[1]),
            'div' => self::number($args[1]) == 0 ? throw new FormulaException('Division by zero.') : self::number($args[0]) / self::number($args[1]),
        };
    }

    /** A list holds the value (as text, any case); a text holds the words. */
    public static function contains(mixed $haystack, mixed $needle): bool
    {
        if (is_array($haystack)) {
            $wanted = mb_strtolower(self::text($needle));
            foreach ($haystack as $element) {
                if (mb_strtolower(self::text($element)) === $wanted) {
                    return true;
                }
            }

            return false;
        }

        return self::text($needle) !== '' && mb_stripos(self::text($haystack), self::text($needle)) !== false;
    }

    /** @return list<string> */
    public static function split(string $text, string $separator): array
    {
        if ($text === '') {
            return [];
        }

        return $separator === '' ? preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) : explode($separator, $text);
    }

    public static function capitalize(string $text): string
    {
        return $text === '' ? '' : mb_strtoupper(mb_substr($text, 0, 1)).mb_strtolower(mb_substr($text, 1));
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

    public static function number(mixed $value): float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }
        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }
        if ($value === null || is_array($value) || trim((string) $value) === '') {
            throw new EmptyOperand;
        }

        return self::maybeNumber($value)
            ?? throw new FormulaException('":value" is not a number.', ['value' => mb_substr((string) $value, 0, 40)]);
    }

    /** "1 299,50", "1,299.50", "990 ₽" — a person's way of writing a number; null for words. */
    public static function maybeNumber(mixed $value): ?float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }
        if (is_bool($value) || is_array($value) || $value === null) {
            return null;
        }

        $text = preg_replace('/[\s\x{00A0}\x{202F}]/u', '', trim((string) $value)) ?? '';
        // A currency sign beside it is not part of the number.
        $text = preg_replace('/^[^\d\-.,]+|[^\d.,]+$/u', '', $text) ?? $text;

        if ($text === '' || ! preg_match('/^-?[\d.,]+$/', $text)) {
            return null;
        }

        // One dot and nothing else is a decimal point however many digits
        // follow ("0.171" — a number the engine wrote itself). Otherwise the
        // LAST separator is the decimal one when one or two digits follow
        // it ("1 299,50", "1,299.50"); any before it group thousands.
        if (preg_match('/^-?\d+\.\d+$/', $text)) {
            return (float) $text;
        }
        $text = preg_match('/^(.*)[.,](\d{1,2})$/', $text, $m)
            ? str_replace([',', '.'], '', $m[1]).'.'.$m[2]
            : str_replace([',', '.'], '', $text);

        return is_numeric($text) ? (float) $text : null;
    }

    /** Nothing, false, 0, "", "0" and an empty list are false — the way a template's `{{#x}}` reads them too. */
    public static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_float($value) || is_int($value)) {
            return $value != 0;
        }
        if (is_array($value)) {
            return $value !== [];
        }

        return ! in_array(trim((string) $value), ['', '0'], true);
    }

    /** As text: a number without a trailing ".00", yes/no as 1/0, a list joined with ", ". */
    public static function text(mixed $value): string
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
        if (is_array($value)) {
            return implode(', ', array_map([self::class, 'text'], array_filter($value, fn ($v) => ! is_array($v))));
        }

        return (string) $value;
    }
}
