<?php

namespace Torgash\Exim\Template;

use App\Core\Content\SafeTemplate;

/**
 * The language of an export profile's own template — a marketplace feed
 * (Yandex Market's YML, Google's XML) written around the shop's data. A
 * subset of Mustache, because a feed needs exactly what Mustache has and a
 * shop owner's Smarty feed translates into it line by line:
 *
 *   {{ item.sku }}                       a value, escaped for the format
 *   {{ item.price | money }}             … through filters (FILTERS)
 *   {{#item.pictures}}<picture>{{ . }}</picture>{{/item.pictures}}
 *                                        a list: once per element
 *   {{#item.old_price}}<oldprice>…</oldprice>{{/item.old_price}}
 *                                        anything else: once if it is there
 *   {{^item.available}}…{{/item.available}}   once if it is NOT
 *   {{! a comment }}
 *
 * Inside a section the element is the first place a name is looked up
 * (`{{ name }}` of a characteristic), then the levels around it — so the
 * row stays `item.…` at any depth. A line holding nothing but a section tag
 * or a comment leaves no blank line behind.
 *
 * Nothing runs as code — no expressions, no function calls; a comparison
 * the feed needs ("an old price only when it is higher") is a value the
 * owning module works out (`item.old_price`). Parsed once per export, not
 * per row: a feed of fifty thousand offers renders one tree fifty thousand
 * times.
 */
class FeedTemplate
{
    /** Each filter and what it does, for the profile form. */
    public const FILTERS = [
        'money' => 'minor units as an amount: 7199000 → 71990, 1250 → 12.50',
        'divide:N' => 'divided by N (grams to kilograms: divide:1000)',
        'multiply:N' => 'multiplied by N',
        'round:N' => 'rounded to N decimals',
        'strip_tags' => 'HTML tags removed',
        'squish' => 'runs of spaces and line breaks made one space',
        'truncate:N' => 'at most N characters',
        'cdata' => 'wrapped in <![CDATA[ … ]]> as it is',
        'raw' => 'not escaped',
        'default:"text"' => 'this text when the value is empty',
        'lower' => 'lower case',
        'upper' => 'upper case',
        'join:", "' => 'a list joined into one line',
    ];

    /** @var list<array> */
    protected array $tree;

    /**
     * `$strict` false: a value tag that is not one (`{{ $profile }}`, an
     * unknown filter) stays in the output as typed — how a template saved
     * before this language was read. The form saves only strict ones.
     */
    public function __construct(public readonly string $source, bool $strict = true)
    {
        $this->tree = self::parse($source, $strict);
    }

    /** @return list<array> the top level: ['text', s] | ['value', path, filters] | ['section', path, inverted, children] */
    public function nodes(): array
    {
        return $this->tree;
    }

    /**
     * @param  list<array>  $nodes
     * @param  list<mixed>  $stack  outermost first
     */
    public static function renderNodes(array $nodes, array $stack, string $escape): string
    {
        $out = '';

        foreach ($nodes as $node) {
            if ($node[0] === 'text') {
                $out .= $node[1];
            } elseif ($node[0] === 'value') {
                $out .= self::value(self::lookup($node[1], $stack), $node[2], $escape);
            } else {
                [, $path, $inverted, $children] = $node;
                $value = self::lookup($path, $stack);

                if ($inverted) {
                    $out .= self::truthy($value) ? '' : self::renderNodes($children, $stack, $escape);
                } elseif (is_array($value) && array_is_list($value)) {
                    foreach ($value as $element) {
                        $out .= self::renderNodes($children, [...$stack, $element], $escape);
                    }
                } elseif (self::truthy($value)) {
                    $out .= self::renderNodes($children, is_array($value) ? [...$stack, $value] : $stack, $escape);
                }
            }
        }

        return $out;
    }

    public function render(array $context, string $escape = SafeTemplate::XML): string
    {
        return self::renderNodes($this->tree, [$context], $escape);
    }

    /**
     * Every dotted path the template names, sections included — what an
     * export reads to know which columns the template uses.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        $paths = [];
        $walk = function (array $nodes) use (&$walk, &$paths) {
            foreach ($nodes as $node) {
                if ($node[0] === 'value' || $node[0] === 'section') {
                    $paths[] = $node[1];
                }
                if ($node[0] === 'section') {
                    $walk($node[3]);
                }
            }
        };
        $walk($this->tree);

        return array_values(array_unique($paths));
    }

    /** @throws TemplateException naming the line of a tag left open, closed twice or misspelt */
    protected static function parse(string $source, bool $strict = true): array
    {
        preg_match_all('/\{\{(\s*[#^\/!]?)(.*?)\}\}/s', $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $root = [];
        $stack = [[null, &$root, 0]];
        $at = 0;
        $length = strlen($source);

        foreach ($matches as $match) {
            [$whole, $offset] = $match[0];
            $sigil = trim($match[1][0]);
            $body = trim($match[2][0]);
            $end = $offset + strlen($whole);
            $line = substr_count($source, "\n", 0, $offset) + 1;

            $before = substr($source, $at, $offset - $at);

            // A tag alone on its line takes the line with it.
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

            $current = &$stack[count($stack) - 1][1];
            if ($before !== '') {
                $current[] = ['text', $before];
            }
            $at = $end;

            if ($sigil === '!') {
                continue;
            }

            if ($sigil === '#' || $sigil === '^') {
                $path = self::path($body, $line);
                $node = ['section', $path, $sigil === '^', []];
                $current[] = $node;
                $stack[] = [$path, &$current[array_key_last($current)][3], $line];

                continue;
            }

            if ($sigil === '/') {
                $path = self::path($body, $line);
                if (count($stack) === 1) {
                    throw new TemplateException('Line :line: {{/:path}} closes nothing.', ['line' => $line, 'path' => $path]);
                }
                [$open, , $openLine] = $stack[count($stack) - 1];
                if ($open !== $path) {
                    throw new TemplateException('Line :line: {{/:path}} closes {{#:open}} from line :opened.', ['line' => $line, 'path' => $path, 'open' => $open, 'opened' => $openLine]);
                }
                array_pop($stack);

                continue;
            }

            try {
                $filters = self::filters($body, $line);
                $current[] = ['value', self::path(array_shift($filters)[0], $line), $filters];
            } catch (TemplateException $e) {
                if ($strict) {
                    throw $e;
                }
                $current[] = ['text', $whole];
            }
        }

        if ($at < $length) {
            $stack[count($stack) - 1][1][] = ['text', substr($source, $at)];
        }

        if (count($stack) > 1) {
            [$open, , $openLine] = $stack[count($stack) - 1];
            throw new TemplateException('Line :line: {{#:path}} is never closed.', ['line' => $openLine, 'path' => $open]);
        }

        return $root;
    }

    protected static function path(string $text, int $line): string
    {
        $text = trim($text);

        if ($text !== '.' && ! preg_match('/^[A-Za-z0-9_\-]+(\.[A-Za-z0-9_\-]+)*$/', $text)) {
            throw new TemplateException('Line :line: ":text" is not a name.', ['line' => $line, 'text' => $text]);
        }

        return $text;
    }

    /** "item.price | money | default:\"0\"" → [[item.price], [money], [default, 0]] */
    protected static function filters(string $body, int $line): array
    {
        $parts = [];
        foreach (preg_split('/\|(?=(?:[^"]*"[^"]*")*[^"]*$)/', $body) as $i => $part) {
            $part = trim($part);
            if ($i === 0) {
                $parts[] = [$part];

                continue;
            }
            [$name, $argument] = array_pad(explode(':', $part, 2), 2, null);
            $name = strtolower(trim($name));
            if (! in_array($name, array_map(fn ($f) => explode(':', $f)[0], array_keys(self::FILTERS)), true)) {
                throw new TemplateException('Line :line: there is no filter ":name".', ['line' => $line, 'name' => $name]);
            }
            $argument = $argument === null ? null : trim($argument);
            if ($argument !== null && strlen($argument) >= 2 && $argument[0] === '"' && str_ends_with($argument, '"')) {
                $argument = stripcslashes(substr($argument, 1, -1));
            }
            $parts[] = [$name, $argument];
        }

        return $parts;
    }

    /** @param  list<mixed>  $stack */
    protected static function lookup(string $path, array $stack): mixed
    {
        if ($path === '.') {
            return end($stack);
        }

        $segments = explode('.', $path);

        // The first name is looked for from the innermost level out.
        for ($i = count($stack) - 1; $i >= 0; $i--) {
            if (is_array($stack[$i]) && array_key_exists($segments[0], $stack[$i])) {
                $value = $stack[$i];
                foreach ($segments as $segment) {
                    if (! is_array($value) || ! array_key_exists($segment, $value)) {
                        return null;
                    }
                    $value = $value[$segment];
                }

                return $value;
            }
        }

        return null;
    }

    protected static function truthy(mixed $value): bool
    {
        return ! ($value === null || $value === false || $value === '' || $value === [] || $value === 0 || $value === 0.0 || $value === '0');
    }

    protected static function value(mixed $value, array $filters, string $escape): string
    {
        $verbatim = false;

        foreach ($filters as [$name, $argument]) {
            $number = is_numeric($argument) ? (float) $argument : null;
            $text = is_array($value) ? '' : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);

            $value = match ($name) {
                'money' => $value === null || $value === '' ? '' : self::amount(((float) $value) / 100, 2),
                'divide' => $value === null || $value === '' || ! $number ? $value : self::amount((float) $value / $number, 3),
                'multiply' => $value === null || $value === '' ? $value : self::amount((float) $value * (float) $number, 3),
                'round' => $value === null || $value === '' ? $value : self::amount(round((float) $value, (int) $number), max(0, (int) $number)),
                'strip_tags' => html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/div|\/li|\/h\d)[^>]*>/i', '$0 ', $text) ?? $text), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'squish' => trim(preg_replace('/\s+/u', ' ', $text) ?? $text),
                'truncate' => mb_strlen($text) > (int) $number ? rtrim(mb_substr($text, 0, max(0, (int) $number))) : $text,
                'lower' => mb_strtolower($text),
                'upper' => mb_strtoupper($text),
                'default' => self::truthy($value) ? $value : (string) $argument,
                'join' => is_array($value) ? implode((string) ($argument ?? ', '), array_filter($value, 'is_scalar')) : $value,
                'raw' => $value,
                'cdata' => '<![CDATA['.str_replace(']]>', ']]]]><![CDATA[>', $text).']]>',
            };

            if (in_array($name, ['raw', 'cdata'], true)) {
                $verbatim = true;
            }
        }

        if (is_array($value) || $value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($verbatim || $escape === SafeTemplate::RAW) {
            return (string) $value;
        }

        return $escape === SafeTemplate::HTML
            ? htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8')
            : trim(json_encode((string) $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '""', '"');
    }

    /** 71990.0 → "71990", 12.5 → "12.50" at two decimals, 0.171 → "0.171". */
    protected static function amount(float $amount, int $decimals): string
    {
        if (abs($amount - round($amount)) < 1e-9) {
            return (string) (int) round($amount);
        }

        $text = number_format($amount, $decimals, '.', '');

        // Money keeps its two decimals; a weight loses the zeros nobody writes.
        return $decimals === 2 ? $text : rtrim(rtrim($text, '0'), '.');
    }
}
