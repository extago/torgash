<?php

namespace Torgash\Exim\Template;

use App\Core\Content\SafeTemplate;
use Torgash\Exim\Import\EmptyOperand;
use Torgash\Exim\Import\FormulaException;

/**
 * The language of an export profile's own template (PLAN §68) — a
 * marketplace feed (Yandex Market's YML, Avito's XML, Google's) written
 * around the shop's data. A subset of Liquid, the language Shopify lets its
 * merchants write in: it describes ONE ROW and can reach nothing but the
 * data it was handed.
 *
 *   {{ item.sku }}                                   a value, escaped for the format
 *   {{ item.price | price }}                         … through filters (Filters::ALL)
 *   {% if item.old_price > item.price %}…{% elsif … %}…{% else %}…{% endif %}
 *   {% unless item.available %}…{% endunless %}
 *   {% for p in item.pictures %}<picture>{{ p }}</picture>{% endfor %}
 *       with forloop.index / index0 / first / last / length, {% break %}, {% continue %},
 *       `limit: 5`, `offset: 1`, `reversed`, `{% else %}` for an empty list
 *   {% case item.condition %}{% when "new" %}…{% when "used", "worn" %}…{% else %}…{% endcase %}
 *   {% assign model = item.name | replace: item.brand, "" | trim %}
 *   {% capture text %}…{% endcapture %}
 *   {% comment %}…{% endcomment %}   {% raw %}…{% endraw %}
 *   {{- …  -}} / {%- … -%}                           the whitespace beside the tag goes
 *
 * A condition is an `Expression` — the same language a column's formula on
 * import is written in: `and`, `or`, `not`, `contains`, comparisons, `&`,
 * arithmetic, functions. Nothing, false, 0, "" and an empty list are false.
 *
 * Mustache's tags stay, for the templates written before: `{{#list}}…{{/list}}`
 * (once per element, `.` the element, its fields by name), `{{#x}}` / `{{^x}}`
 * as "when there is / is not", `{{! }}`.
 *
 * What makes it safe to hand to whoever edits a profile: nothing in it is
 * PHP, the tree is walked by this class; no function but the engine's own
 * filters and the expression's functions; the only data is the context
 * handed in; and a budget — steps and output per row, nesting depth, a
 * range's length — past which the template is told, in words and with the
 * line, that it did too much (a loop inside a loop over big lists).
 *
 * A tag alone on its line takes the line with it. Parsed once per export,
 * not per row: a feed of fifty thousand offers walks one tree fifty
 * thousand times, the rows streamed (`stream()`).
 */
class FeedTemplate
{
    /** Each filter and what it does, for the profile form. */
    public const FILTERS = Filters::ALL;

    public const MAX_SOURCE = 512_000;

    public const MAX_DEPTH = 32;

    /** Node visits one unit of work (one row) may take. */
    public const MAX_STEPS = 500_000;

    /** Bytes one unit of work may print. */
    public const MAX_OUTPUT = 8_000_000;

    /** How long a `(1..n)` range may be. */
    public const MAX_RANGE = 10_000;

    /** @var list<array> */
    protected array $tree;

    /**
     * `$strict` false: a tag that is not one (`{{ $profile }}`, an unknown
     * filter) stays in the output as typed — how a template saved before
     * this language was read. The form saves only strict ones.
     */
    public function __construct(public readonly string $source, bool $strict = true)
    {
        if (strlen($source) > self::MAX_SOURCE) {
            throw new TemplateException('The template is longer than :max characters.', ['max' => self::MAX_SOURCE]);
        }

        $this->tree = self::parse($source, $strict);
    }

    /** @return list<array> the top level */
    public function nodes(): array
    {
        return $this->tree;
    }

    public function render(array $context, string $escape = SafeTemplate::XML): string
    {
        return self::top($this->tree, new Scope([$context], $escape));
    }

    /** Kept for callers of the old shape: the nodes drawn against a stack of frames. */
    public static function renderNodes(array $nodes, array $stack, string $escape): string
    {
        return self::top($nodes, new Scope($stack, $escape));
    }

    /** Drawn where no loop is open: a break or continue here is a mistake, said with its line. */
    protected static function top(array $nodes, Scope $scope): string
    {
        try {
            return self::draw($nodes, $scope);
        } catch (LoopControl $e) {
            throw new TemplateException('Line :line: {% :tag %} outside a loop.', ['line' => $e->getCode(), 'tag' => $e->getMessage()]);
        }
    }

    /**
     * The template around a STREAM of rows: what stands before the top-level
     * `{{#rows}}` (or `{% for x in rows %}`) once, its body per row as the
     * query yields it, the rest once — a feed of fifty thousand products never
     * becomes one array. Without such a block the template is drawn once,
     * without rows. Variables assigned in one row are still there in the
     * next, as Liquid has it; the budget starts afresh with every row.
     *
     * @param  iterable<array<string, mixed>>  $rows
     * @param  string  $item  the name a Mustache `{{#rows}}` gives each row
     * @return \Generator<string>
     */
    public function stream(array $context, iterable $rows, string $escape = SafeTemplate::XML, string $item = 'item'): \Generator
    {
        $at = null;
        foreach ($this->tree as $i => $node) {
            if (($node[0] === 'section' && $node[1] === 'rows' && ! $node[2])
                || ($node[0] === 'for' && $node[2] instanceof Expression && $node[2]->paths() === ['rows'])) {
                $at = $i;
                break;
            }
        }

        $scope = new Scope([$context], $escape);

        if ($at === null) {
            yield self::top($this->tree, $scope);

            return;
        }

        yield self::top(array_slice($this->tree, 0, $at), $scope);

        $block = $this->tree[$at];
        $n = 0;
        foreach ($rows as $row) {
            $n++;
            $scope->fresh();
            $frame = $block[0] === 'section'
                ? [$item => $row]
                : [$block[1] => $row, 'forloop' => self::forloop($n - 1, null)];
            $scope->push($frame);

            try {
                yield self::draw($block[3], $scope);
            } catch (LoopControl $e) {
                if ($e->getMessage() === 'break') {
                    $scope->pop();
                    break;
                }
            } finally {
                $scope->pop();
            }
        }

        $scope->fresh();
        yield self::top(array_slice($this->tree, $at + 1), $scope);
    }

    /**
     * Every path the template reads, inside expressions, sections, filter
     * arguments and loops alike — what an export reads to know which columns
     * the template uses.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        $paths = [];
        $expr = function (?Expression $e) use (&$paths) {
            if ($e !== null) {
                array_push($paths, ...$e->paths());
            }
        };
        $filters = function (array $filters) use ($expr) {
            foreach ($filters as [, $args]) {
                foreach ($args as $arg) {
                    $expr($arg);
                }
            }
        };
        $walk = function (array $nodes) use (&$walk, &$paths, $expr, $filters) {
            foreach ($nodes as $node) {
                switch ($node[0]) {
                    case 'value': $expr($node[1]); $filters($node[2]); break;
                    case 'section': $paths[] = $node[1]; $walk($node[3]); break;
                    case 'if':
                        foreach ($node[1] as [$condition, , $children]) {
                            $expr($condition);
                            $walk($children);
                        }
                        break;
                    case 'for':
                        if ($node[2] instanceof Expression) {
                            $expr($node[2]);
                        } else {
                            $expr($node[2][1]);
                            $expr($node[2][2]);
                        }
                        $expr($node[5]['limit']);
                        $expr($node[5]['offset']);
                        $walk($node[3]);
                        $walk($node[4]);
                        break;
                    case 'assign': $expr($node[2]); $filters($node[3]); break;
                    case 'capture': $walk($node[2]); break;
                    case 'case':
                        $expr($node[1]);
                        foreach ($node[2] as [$values, $children]) {
                            foreach ($values ?? [] as $value) {
                                $expr($value);
                            }
                            $walk($children);
                        }
                        break;
                }
            }
        };
        $walk($this->tree);

        return array_values(array_unique($paths));
    }

    // ------------------------------------------------------------ reading

    /**
     * @return list<array>
     *
     * @throws TemplateException naming the line of a tag left open, closed twice or misspelt
     */
    protected static function parse(string $source, bool $strict = true): array
    {
        preg_match_all('/\{\{(-?)(\s*[#^\/!]?)(.*?)(-?)\}\}|\{%(-?)\s*(.*?)\s*(-?)%\}/s', $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $root = [];
        // Each open block: [kind, name, &children, line, extra]
        $stack = [['root', null, &$root, 0, []]];
        $at = 0;
        $length = strlen($source);

        foreach ($matches as $match) {
            [$whole, $offset] = $match[0];
            if ($offset < $at) {
                continue; // inside a raw or comment block already taken
            }

            $isTag = isset($match[6]) && $match[6][1] !== -1;
            $trimBefore = ($isTag ? $match[5][0] : $match[1][0]) === '-';
            $trimAfter = ($isTag ? $match[7][0] : $match[4][0]) === '-';
            $sigil = $isTag ? '%' : trim($match[2][0]);
            $body = trim($isTag ? $match[6][0] : $match[3][0]);
            $end = $offset + strlen($whole);
            $line = substr_count($source, "\n", 0, $offset) + 1;
            $before = substr($source, $at, $offset - $at);

            // A block tag alone on its line takes the line with it.
            if ($sigil !== '') {
                $lineStart = strrpos(substr($source, 0, $offset), "\n");
                $lineStart = $lineStart === false ? 0 : $lineStart + 1;
                $lineEnd = strpos($source, "\n", $end);
                $lineEnd = $lineEnd === false ? $length : $lineEnd;
                $lead = substr($source, $lineStart, $offset - $lineStart);
                $tail = substr($source, $end, $lineEnd - $end);

                if ($lineStart >= $at && trim($lead) === '' && trim($tail) === '') {
                    $before = substr($before, 0, strlen($before) - strlen($lead));
                    $end = min($length, $lineEnd + 1);
                }
            }
            if ($trimBefore) {
                $before = rtrim($before);
            }
            if ($trimAfter) {
                preg_match('/\G\s*/', $source, $space, 0, $end);
                $end += strlen($space[0]);
            }

            $current = &$stack[count($stack) - 1][2];
            if ($before !== '') {
                $current[] = ['text', $before];
            }
            $at = $end;

            try {
                if ($sigil === '%') {
                    $at = self::tag($body, $line, $source, $at, $stack);
                } elseif ($sigil === '!') {
                    // a comment
                } elseif ($sigil === '#' || $sigil === '^') {
                    $path = self::path($body, $line);
                    $current[] = ['section', $path, $sigil === '^', [], $line];
                    $stack[] = ['section', $path, &$current[array_key_last($current)][3], $line, []];
                } elseif ($sigil === '/') {
                    $path = self::path($body, $line);
                    [$kind, $open, , $openLine] = $stack[count($stack) - 1];
                    if ($kind === 'root') {
                        throw new TemplateException('Line :line: {{/:path}} closes nothing.', ['line' => $line, 'path' => $path]);
                    }
                    if ($kind !== 'section' || $open !== $path) {
                        throw new TemplateException('Line :line: {{/:path}} closes {{#:open}} from line :opened.', ['line' => $line, 'path' => (string) $path, 'open' => (string) $open, 'opened' => $openLine]);
                    }
                    array_pop($stack);
                } else {
                    [$expression, $filters] = self::valueTag($body, $line);
                    $current[] = ['value', $expression, $filters, $line];
                }
            } catch (TemplateException $e) {
                if ($strict || $sigil === '#' || $sigil === '^' || $sigil === '/') {
                    throw $e;
                }
                // Leniently: a value or tag that is not one stays as typed.
                $current[] = ['text', $whole];
            }

            if (count($stack) > self::MAX_DEPTH) {
                throw new TemplateException('Line :line: tags are nested more than :max deep.', ['line' => $line, 'max' => self::MAX_DEPTH]);
            }
        }

        if ($at < $length) {
            $stack[count($stack) - 1][2][] = ['text', substr($source, $at)];
        }

        if (count($stack) > 1) {
            [$kind, $open, , $openLine] = $stack[count($stack) - 1];
            throw $kind === 'section'
                ? new TemplateException('Line :line: {{#:path}} is never closed.', ['line' => $openLine, 'path' => (string) $open])
                : new TemplateException('Line :line: {% :tag %} is never closed.', ['line' => $openLine, 'tag' => (string) $open]);
        }

        return $root;
    }

    /**
     * One `{% … %}` tag: opens, continues or closes a block on the stack.
     * Returns where the source continues (a raw/comment block skips ahead).
     *
     * @param  list<array>  $stack
     */
    protected static function tag(string $body, int $line, string $source, int $at, array &$stack): int
    {
        preg_match('/^([a-z_]+)\s*(.*)$/si', $body, $m);
        $name = strtolower($m[1] ?? '');
        $rest = trim($m[2] ?? '');
        $top = &$stack[count($stack) - 1];
        $current = &$top[2];

        $closes = function (string ...$kinds) use (&$stack, $name, $line): void {
            [$kind, , , $openLine] = $stack[count($stack) - 1];
            if (! in_array($kind, $kinds, true)) {
                throw $kind === 'root'
                    ? new TemplateException('Line :line: {% :tag %} closes nothing.', ['line' => $line, 'tag' => $name])
                    : new TemplateException('Line :line: {% :tag %} does not close {% :open %} from line :opened.', ['line' => $line, 'tag' => $name, 'open' => (string) $stack[count($stack) - 1][1], 'opened' => $openLine]);
            }
        };

        switch ($name) {
            case 'if':
            case 'unless':
                $condition = self::expression($rest, $line, $name);
                $current[] = ['if', [[$condition, $name === 'unless', []]], $line];
                $stack[] = ['if', $name, &$current[array_key_last($current)][1][0][2], $line, []];

                return $at;

            case 'elsif':
            case 'elseif':
                $closes('if');
                if ($top[1] === 'unless') {
                    throw new TemplateException('Line :line: {% elsif %} inside {% unless %} — write {% if %} with {% elsif %}.', ['line' => $line]);
                }
                $node = &self::owner($stack);
                $node[1][] = [self::expression($rest, $line, $name), false, []];
                $top[2] = &$node[1][array_key_last($node[1])][2];

                return $at;

            case 'else':
                $closes('if', 'for', 'case');
                $node = &self::owner($stack);
                if ($top[0] === 'if') {
                    $node[1][] = [null, false, []];
                    $top[2] = &$node[1][array_key_last($node[1])][2];
                } elseif ($top[0] === 'for') {
                    $top[2] = &$node[4];
                } else {
                    $node[2][] = [null, []];
                    $top[2] = &$node[2][array_key_last($node[2])][1];
                }
                $top[4]['else'] = true;

                return $at;

            case 'endif':
            case 'endunless':
                $closes('if');
                if (($name === 'endif') !== ($top[1] === 'if')) {
                    throw new TemplateException('Line :line: {% :tag %} does not close {% :open %} from line :opened.', ['line' => $line, 'tag' => $name, 'open' => $top[1], 'opened' => $top[3]]);
                }
                array_pop($stack);

                return $at;

            case 'for':
                if (! preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s+in\s+(.+)$/s', $rest, $f)) {
                    throw new TemplateException('Line :line: {% for %} is written {% for name in list %}.', ['line' => $line]);
                }
                [$list, $options] = self::forOptions($f[2], $line);
                $current[] = ['for', $f[1], $list, [], [], $options, $line];
                $stack[] = ['for', 'for', &$current[array_key_last($current)][3], $line, []];

                return $at;

            case 'endfor':
                $closes('for');
                array_pop($stack);

                return $at;

            case 'break':
            case 'continue':
                $current[] = [$name, $line];

                return $at;

            case 'case':
                $current[] = ['case', self::expression($rest, $line, $name), [], $line];
                $stack[] = ['case', 'case', &$current[array_key_last($current)][2], $line, ['when' => false]];
                // Until the first {% when %} nothing may be printed: its children go nowhere.
                $void = [];
                $stack[count($stack) - 1][2] = &$void;

                return $at;

            case 'when':
                $closes('case');
                $node = &self::owner($stack);
                $values = [];
                foreach (preg_split('/\s+or\s+|,/i', $rest) ?: [] as $piece) {
                    if (trim($piece) !== '') {
                        $values[] = self::expression(trim($piece), $line, $name);
                    }
                }
                if ($values === []) {
                    throw new TemplateException('Line :line: {% when %} names no value.', ['line' => $line]);
                }
                $node[2][] = [$values, []];
                $top[2] = &$node[2][array_key_last($node[2])][1];

                return $at;

            case 'endcase':
                $closes('case');
                array_pop($stack);

                return $at;

            case 'assign':
                if (! preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.+)$/s', $rest, $a)) {
                    throw new TemplateException('Line :line: {% assign %} is written {% assign name = value %}.', ['line' => $line]);
                }
                [$expression, $filters] = self::valueTag($a[2], $line);
                $current[] = ['assign', $a[1], $expression, $filters, $line];

                return $at;

            case 'capture':
                if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $rest)) {
                    throw new TemplateException('Line :line: {% capture %} wants a name.', ['line' => $line]);
                }
                $current[] = ['capture', $rest, [], $line];
                $stack[] = ['capture', 'capture', &$current[array_key_last($current)][2], $line, []];

                return $at;

            case 'endcapture':
                $closes('capture');
                array_pop($stack);

                return $at;

            case 'raw':
            case 'comment':
                $close = preg_match('/\{%-?\s*end'.$name.'\s*-?%\}/', $source, $c, PREG_OFFSET_CAPTURE, $at);
                if (! $close) {
                    throw new TemplateException('Line :line: {% :tag %} is never closed.', ['line' => $line, 'tag' => $name]);
                }
                if ($name === 'raw') {
                    $current[] = ['text', substr($source, $at, $c[0][1] - $at)];
                }
                $end = $c[0][1] + strlen($c[0][0]);
                // The closing tag alone on its line takes the line too.
                $lineEnd = strpos($source, "\n", $end);
                if (trim(substr($source, $end, ($lineEnd === false ? strlen($source) : $lineEnd) - $end)) === '') {
                    $end = $lineEnd === false ? strlen($source) : $lineEnd + 1;
                }

                return $end;

            case 'endraw':
            case 'endcomment':
                throw new TemplateException('Line :line: {% :tag %} closes nothing.', ['line' => $line, 'tag' => $name]);

            default:
                throw new TemplateException('Line :line: there is no tag ":tag".', ['line' => $line, 'tag' => $name === '' ? $body : $name]);
        }
    }

    /** The node the innermost open block belongs to (its if/for/case array in the parent's children). */
    protected static function &owner(array &$stack): array
    {
        $parent = &$stack[count($stack) - 2][2];

        return $parent[array_key_last($parent)];
    }

    /** @return array{0: Expression, 1: array{limit: ?Expression, offset: ?Expression, reversed: bool}} */
    protected static function forOptions(string $text, int $line): array
    {
        $options = ['limit' => null, 'offset' => null, 'reversed' => false];
        $text = trim($text);

        while (preg_match('/\s(reversed|limit\s*:\s*(\S+)|offset\s*:\s*(\S+))\s*$/i', $text, $o)) {
            $word = strtolower($o[1]);
            if ($word === 'reversed') {
                $options['reversed'] = true;
            } elseif (str_starts_with($word, 'limit')) {
                $options['limit'] = self::expression($o[2], $line, 'for');
            } else {
                $options['offset'] = self::expression($o[3], $line, 'for');
            }
            $text = trim(substr($text, 0, -strlen($o[0])));
        }

        // (1..5), (1..item.count)
        if (preg_match('/^\((.+?)\.\.(.+)\)$/', $text, $r)) {
            return [['range', self::expression($r[1], $line, 'for'), self::expression($r[2], $line, 'for')], $options];
        }

        return [self::expression($text, $line, 'for'), $options];
    }

    protected static function expression(string $text, int $line, string $tag): Expression
    {
        try {
            return Expression::parse($text);
        } catch (FormulaException $e) {
            throw new TemplateException('Line :line, {% :tag %}: :problem', ['line' => $line, 'tag' => $tag, 'problem' => $e->describe()]);
        }
    }

    protected static function path(string $text, int $line): string
    {
        $text = trim($text);

        if ($text !== '.' && ! preg_match('/^[A-Za-z0-9_\-]+(\.[A-Za-z0-9_\-]+)*$/', $text)) {
            throw new TemplateException('Line :line: ":text" is not a name.', ['line' => $line, 'text' => $text]);
        }

        return $text;
    }

    /**
     * "item.price | replace: "a", "b" | default: shop.name" → the expression
     * and its filters, each [name, [argument expressions]].
     *
     * @return array{0: Expression, 1: list<array{0: string, 1: list<Expression>}>}
     */
    protected static function valueTag(string $body, int $line): array
    {
        $parts = preg_split('/\|(?=(?:[^"\']*(?:"[^"]*"|\'[^\']*\'))*[^"\']*$)/', $body) ?: [$body];
        $head = trim(array_shift($parts));

        if ($head === '') {
            throw new TemplateException('Line :line: ":text" is not a name.', ['line' => $line, 'text' => $body]);
        }

        try {
            $expression = Expression::parse($head);
        } catch (FormulaException $e) {
            throw new TemplateException('Line :line: ":text" is not a name.', ['line' => $line, 'text' => $head]);
        }

        $filters = [];
        foreach ($parts as $part) {
            $part = trim($part);
            [$name, $argument] = array_pad(explode(':', $part, 2), 2, null);
            $name = strtolower(trim($name));
            if (! Filters::known($name)) {
                throw new TemplateException('Line :line: there is no filter ":name".', ['line' => $line, 'name' => $name]);
            }
            $args = [];
            if ($argument !== null && trim($argument) !== '') {
                foreach (preg_split('/,(?=(?:[^"\']*(?:"[^"]*"|\'[^\']*\'))*[^"\']*$)/', $argument) ?: [] as $arg) {
                    try {
                        $args[] = Expression::parse(trim($arg));
                    } catch (FormulaException $e) {
                        throw new TemplateException('Line :line, | :name: :problem', ['line' => $line, 'name' => $name, 'problem' => $e->describe()]);
                    }
                }
            }
            $filters[] = [$name, $args];
        }

        return [$expression, $filters];
    }

    // ------------------------------------------------------------ drawing

    /** @param  list<array>  $nodes */
    protected static function draw(array $nodes, Scope $scope): string
    {
        $out = '';

        foreach ($nodes as $node) {
            if (++$scope->steps > self::MAX_STEPS) {
                throw new TemplateException('Line :line: the template did too much work on one row (more than :max steps) — a loop inside a loop over big lists?', ['line' => $node[array_key_last($node)] ?? 0, 'max' => self::MAX_STEPS]);
            }

            switch ($node[0]) {
                case 'text':
                    $out .= $node[1];
                    break;

                case 'value':
                    $out .= self::print(self::evaluate($node[1], $scope, $node[3]), $node[2], $scope, $node[3]);
                    break;

                case 'section':
                    $value = $scope->lookup($node[1]);
                    if ($node[2]) {
                        $out .= Expression::truthy($value) ? '' : self::draw($node[3], $scope);
                    } elseif (is_array($value) && array_is_list($value)) {
                        foreach ($value as $element) {
                            $scope->push($element);
                            try {
                                $out .= self::draw($node[3], $scope);
                            } finally {
                                $scope->pop();
                            }
                        }
                    } elseif (Expression::truthy($value)) {
                        if (is_array($value)) {
                            $scope->push($value);
                        }
                        try {
                            $out .= self::draw($node[3], $scope);
                        } finally {
                            if (is_array($value)) {
                                $scope->pop();
                            }
                        }
                    }
                    break;

                case 'if':
                    foreach ($node[1] as [$condition, $negate, $children]) {
                        $taken = $condition === null || (Expression::truthy(self::evaluate($condition, $scope, $node[2])) xor $negate);
                        if ($taken) {
                            $out .= self::draw($children, $scope);
                            break;
                        }
                    }
                    break;

                case 'for':
                    $out .= self::loop($node, $scope);
                    break;

                case 'break':
                case 'continue':
                    throw new LoopControl($node[0], $node[1]);

                case 'case':
                    $subject = self::evaluate($node[1], $scope, $node[3]);
                    foreach ($node[2] as [$values, $children]) {
                        $hit = $values === null;
                        foreach ($values ?? [] as $value) {
                            if (self::same($subject, self::evaluate($value, $scope, $node[3]))) {
                                $hit = true;
                                break;
                            }
                        }
                        if ($hit) {
                            $out .= self::draw($children, $scope);
                            break;
                        }
                    }
                    break;

                case 'assign':
                    $value = self::evaluate($node[2], $scope, $node[4]);
                    foreach ($node[3] as [$name, $args]) {
                        $value = self::filter($name, $value, $args, $scope, $node[4]);
                    }
                    $scope->vars[$node[1]] = $value;
                    break;

                case 'capture':
                    $scope->vars[$node[1]] = self::draw($node[2], $scope);
                    break;
            }

            if (strlen($out) + $scope->written > self::MAX_OUTPUT) {
                throw new TemplateException('Line :line: the template printed more than :max bytes for one row.', ['line' => $node[array_key_last($node)] ?? 0, 'max' => self::MAX_OUTPUT]);
            }
        }

        $scope->written += strlen($out);

        return $out;
    }

    protected static function loop(array $node, Scope $scope): string
    {
        [, $name, $list, $children, $otherwise, $options, $line] = $node;

        if (is_array($list) && $list[0] === 'range') {
            $from = (int) Expression::number(self::evaluate($list[1], $scope, $line));
            $to = (int) Expression::number(self::evaluate($list[2], $scope, $line));
            if (abs($to - $from) + 1 > self::MAX_RANGE) {
                throw new TemplateException('Line :line: a range may count up to :max.', ['line' => $line, 'max' => self::MAX_RANGE]);
            }
            $items = $from <= $to ? range($from, $to) : [];
        } else {
            $value = self::evaluate($list, $scope, $line);
            $items = is_array($value) ? array_values($value) : (Expression::truthy($value) ? [$value] : []);
        }

        if ($options['reversed']) {
            $items = array_reverse($items);
        }
        if ($options['offset'] !== null) {
            $items = array_slice($items, max(0, (int) Expression::number(self::evaluate($options['offset'], $scope, $line))));
        }
        if ($options['limit'] !== null) {
            $items = array_slice($items, 0, max(0, (int) Expression::number(self::evaluate($options['limit'], $scope, $line))));
        }

        if ($items === []) {
            return self::draw($otherwise, $scope);
        }

        $out = '';
        $count = count($items);
        foreach ($items as $i => $element) {
            $scope->push([$name => $element, 'forloop' => self::forloop($i, $count)]);
            try {
                $out .= self::draw($children, $scope);
            } catch (LoopControl $e) {
                if ($e->getMessage() === 'break') {
                    break;
                }
            } finally {
                $scope->pop();
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    protected static function forloop(int $i, ?int $count): array
    {
        return [
            'index' => $i + 1, 'index0' => $i, 'first' => $i === 0,
            'last' => $count !== null && $i === $count - 1, 'length' => $count,
            'rindex' => $count === null ? null : $count - $i, 'rindex0' => $count === null ? null : $count - $i - 1,
        ];
    }

    protected static function evaluate(Expression $expression, Scope $scope, int $line): mixed
    {
        try {
            return $expression->evaluate(fn (string $path) => $scope->lookup($path));
        } catch (EmptyOperand) {
            return null;
        } catch (FormulaException $e) {
            throw new TemplateException('Line :line: :problem', ['line' => $line, 'problem' => $e->describe()]);
        }
    }

    protected static function filter(string $name, mixed $value, array $args, Scope $scope, int $line): mixed
    {
        try {
            return Filters::apply($name, $value, array_map(fn (Expression $arg) => self::evaluate($arg, $scope, $line), $args));
        } catch (FormulaException $e) {
            throw new TemplateException('Line :line, | :name: :problem', ['line' => $line, 'name' => $name, 'problem' => $e->describe()]);
        }
    }

    protected static function same(mixed $a, mixed $b): bool
    {
        $na = Expression::maybeNumber($a);
        $nb = Expression::maybeNumber($b);

        return $na !== null && $nb !== null ? $na == $nb : mb_strtolower(Expression::text($a)) === mb_strtolower(Expression::text($b));
    }

    /** @param  list<array{0: string, 1: list<Expression>}>  $filters */
    protected static function print(mixed $value, array $filters, Scope $scope, int $line): string
    {
        $verbatim = false;

        foreach ($filters as [$name, $args]) {
            $value = self::filter($name, $value, $args, $scope, $line);
            if (in_array(Filters::canonical($name), Filters::VERBATIM, true)) {
                $verbatim = true;
            }
        }

        if ($value === null) {
            return '';
        }
        if (is_array($value)) {
            $value = Expression::text($value);
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_float($value) || is_int($value)) {
            $value = Expression::text($value);
        }
        if ($verbatim || $scope->escape === SafeTemplate::RAW) {
            return (string) $value;
        }

        return $scope->escape === SafeTemplate::HTML
            ? htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8')
            : substr(json_encode((string) $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '""', 1, -1);
    }
}
