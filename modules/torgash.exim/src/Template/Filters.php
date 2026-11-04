<?php

namespace Torgash\Exim\Template;

use Torgash\Exim\Import\EmptyOperand;
use Torgash\Exim\Import\FormulaException;

/**
 * What `| filter` does to a value in a template (PLAN §68): `{{ item.price |
 * price }}`, `{{ item.name | replace: "Apple ", "" | truncate: 60 }}`,
 * `{% assign parts = item.set | split: "," %}`. Liquid's names where Liquid
 * has them, so a feed written for Shopify reads the same here; the feed's
 * own on top (price, money, cdata).
 *
 * A filter is a function the engine wrote, applied to a value it was given —
 * the only kind of "code" a template can run. An argument is any expression.
 */
final class Filters
{
    /** Each filter as it is written, and what it does — for the form and the docs. */
    public const ALL = [
        'price' => 'a price per unit as a number: 7199000 → 71990, 1250 → 12.50, and 0.012 when the shop keeps more places',
        'money' => 'minor units as an amount: 7199000 → 71990, 1250 → 12.50',
        'divide: N' => 'divided by N (grams to kilograms: divide: 1000); also divided_by',
        'multiply: N' => 'multiplied by N; also times',
        'plus: N' => 'N added',
        'minus: N' => 'N taken away',
        'modulo: N' => 'the remainder of dividing by N',
        'round: N' => 'rounded to N decimals',
        'ceil' => 'rounded up to a whole number',
        'floor' => 'rounded down to a whole number',
        'abs' => 'without its sign',
        'at_least: N' => 'N when the value is smaller',
        'at_most: N' => 'N when the value is larger',
        'number: N' => 'written with exactly N decimals',
        'strip_tags' => 'HTML tags removed; also strip_html',
        'squish' => 'runs of spaces and line breaks made one space',
        'strip_newlines' => 'line breaks removed',
        'truncate: N' => 'at most N characters',
        'truncatewords: N' => 'at most N words',
        'cdata' => 'wrapped in <![CDATA[ … ]]> as it is',
        'raw' => 'not escaped',
        'escape' => 'escaped for XML/HTML even in a plain file',
        'default: "text"' => 'this text when the value is empty',
        'lower' => 'lower case; also downcase',
        'upper' => 'upper case; also upcase',
        'capitalize' => 'the first letter capital, the rest lower',
        'trim' => 'spaces at both ends removed; also strip',
        'lstrip' => 'spaces at the start removed',
        'rstrip' => 'spaces at the end removed',
        'replace: "a", "b"' => 'every a replaced by b',
        'replace_first: "a", "b"' => 'the first a replaced by b',
        'remove: "a"' => 'every a removed',
        'append: "text"' => 'the text added after',
        'prepend: "text"' => 'the text put before',
        'slice: from, length' => 'a piece of the text from position from (0 is the first character; -3 the last three)',
        'split: ","' => 'the text cut into a list at every ","',
        'join: ", "' => 'a list joined into one line',
        'first' => 'the first element of a list',
        'last' => 'the last element of a list',
        'size' => 'how many elements a list has, or characters a text',
        'reverse' => 'the list the other way round',
        'sort' => 'the list sorted; sort: "field" by a field of each record',
        'uniq' => 'the list without repeats',
        'compact' => 'the list without empty elements',
        'map: "field"' => 'one field of every record, as a list',
        'where: "field", "value"' => 'only the records whose field has the value',
        'date: "d.m.Y"' => 'a date written in this form (PHP\'s letters: Y-m-d H:i)',
        'url_encode' => 'safe inside a web address',
    ];

    /** The names other systems use for the same thing. */
    protected const ALIASES = [
        'divided_by' => 'divide', 'times' => 'multiply', 'strip_html' => 'strip_tags',
        'downcase' => 'lower', 'upcase' => 'upper', 'strip' => 'trim',
    ];

    /** After these the value is printed as it is — escaped once, or not at all. */
    public const VERBATIM = ['raw', 'cdata', 'escape'];

    public static function known(string $name): bool
    {
        return isset(self::ALIASES[$name]) || in_array($name, self::names(), true);
    }

    public static function canonical(string $name): string
    {
        return self::ALIASES[$name] ?? $name;
    }

    /** @return list<string> */
    public static function names(): array
    {
        static $names = null;

        return $names ??= array_map(fn (string $key) => explode(':', $key)[0], array_keys(self::ALL));
    }

    /**
     * @param  list<mixed>  $args  worked out already
     *
     * @throws FormulaException when the value or an argument is not what the filter needs
     */
    public static function apply(string $name, mixed $value, array $args): mixed
    {
        $name = self::canonical($name);
        $arg = $args[0] ?? null;
        $text = is_array($value) ? Expression::text($value) : (is_bool($value) ? ($value ? 'true' : 'false') : (string) ($value ?? ''));
        $blank = $value === null || $value === '' || $value === [];

        try {
            return match ($name) {
                'money' => $blank ? '' : self::amount(Expression::number($value) / 100, 2),
                // Price units (PLAN §65): exact, with the places the value needs.
                'price' => $blank || ! is_numeric($value) ? '' : self::priceText((int) $value),
                // Arithmetic hands on a NUMBER, so the next filter adds to
                // 0.171 and not to "0.171"; printed, a number shows up to four
                // decimals (Expression::text) — `number: N` for exactly N.
                'divide' => $blank ? $value : (Expression::number($arg) == 0 ? throw new FormulaException('Division by zero.') : Expression::number($value) / Expression::number($arg)),
                'multiply' => $blank ? $value : Expression::number($value) * Expression::number($arg),
                'plus' => $blank ? $value : Expression::number($value) + Expression::number($arg),
                'minus' => $blank ? $value : Expression::number($value) - Expression::number($arg),
                'modulo' => $blank ? $value : (Expression::number($arg) == 0 ? throw new FormulaException('Division by zero.') : fmod(Expression::number($value), Expression::number($arg))),
                'round' => $blank ? $value : round(Expression::number($value), (int) Expression::number($arg ?? 0)),
                'ceil' => $blank ? $value : (int) ceil(Expression::number($value)),
                'floor' => $blank ? $value : (int) floor(Expression::number($value)),
                'abs' => $blank ? $value : abs(Expression::number($value)),
                'at_least' => $blank ? $value : max(Expression::number($value), Expression::number($arg)),
                'at_most' => $blank ? $value : min(Expression::number($value), Expression::number($arg)),
                'number' => $blank ? '' : number_format(Expression::number($value), max(0, (int) Expression::number($arg ?? 2)), '.', ''),
                'strip_tags' => html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/div|\/li|\/h\d)[^>]*>/i', '$0 ', $text) ?? $text), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'squish' => trim(preg_replace('/\s+/u', ' ', $text) ?? $text),
                'strip_newlines' => str_replace(["\r\n", "\r", "\n"], '', $text),
                'truncate' => mb_strlen($text) > (int) Expression::number($arg ?? 50) ? rtrim(mb_substr($text, 0, max(0, (int) Expression::number($arg ?? 50)))) : $text,
                'truncatewords' => self::truncateWords($text, (int) Expression::number($arg ?? 15)),
                'lower' => mb_strtolower($text),
                'upper' => mb_strtoupper($text),
                'capitalize' => Expression::capitalize($text),
                'trim' => trim($text),
                'lstrip' => ltrim($text),
                'rstrip' => rtrim($text),
                'default' => Expression::truthy($value) ? $value : ($arg ?? ''),
                'replace' => str_replace(Expression::text($arg), Expression::text($args[1] ?? ''), $text),
                'replace_first' => ($p = Expression::text($arg)) === '' ? $text : (($at = mb_strpos($text, $p)) === false ? $text : mb_substr($text, 0, $at).Expression::text($args[1] ?? '').mb_substr($text, $at + mb_strlen($p))),
                'remove' => str_replace(Expression::text($arg), '', $text),
                'append' => $text.Expression::text($arg),
                'prepend' => Expression::text($arg).$text,
                'slice' => is_array($value)
                    ? array_values(array_slice($value, (int) Expression::number($arg ?? 0), isset($args[1]) ? (int) Expression::number($args[1]) : 1))
                    : mb_substr($text, (int) Expression::number($arg ?? 0), isset($args[1]) ? (int) Expression::number($args[1]) : 1),
                'split' => Expression::split($text, Expression::text($arg ?? ' ')),
                'join' => is_array($value) ? implode(Expression::text($arg ?? ', '), array_map([Expression::class, 'text'], array_filter($value, fn ($v) => ! is_array($v)))) : $value,
                'first' => is_array($value) ? ($value === [] ? null : reset($value)) : mb_substr($text, 0, 1),
                'last' => is_array($value) ? ($value === [] ? null : end($value)) : mb_substr($text, -1),
                'size' => is_array($value) ? count($value) : mb_strlen($text),
                'reverse' => is_array($value) ? array_reverse(array_values($value)) : implode('', array_reverse(preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [])),
                'sort' => self::sort($value, $arg === null ? null : Expression::text($arg)),
                'uniq' => is_array($value) ? array_values(array_unique(array_filter($value, fn ($v) => ! is_array($v)), SORT_REGULAR)) : $value,
                'compact' => is_array($value) ? array_values(array_filter($value, fn ($v) => $v !== null && $v !== '' && $v !== [])) : $value,
                'map' => is_array($value) ? array_values(array_map(fn ($record) => is_array($record) ? ($record[Expression::text($arg)] ?? null) : null, $value)) : [],
                'where' => is_array($value) ? array_values(array_filter($value, fn ($record) => is_array($record) && (isset($args[1])
                    ? mb_strtolower(Expression::text($record[Expression::text($arg)] ?? null)) === mb_strtolower(Expression::text($args[1]))
                    : Expression::truthy($record[Expression::text($arg)] ?? null)))) : [],
                'date' => self::date($text, Expression::text($arg ?? 'Y-m-d')),
                'url_encode' => rawurlencode($text),
                'raw' => $value,
                'escape' => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8'),
                'cdata' => '<![CDATA['.str_replace(']]>', ']]]]><![CDATA[>', $text).']]>',
                default => throw new FormulaException('There is no filter ":name".', ['name' => $name]),
            };
        } catch (EmptyOperand) {
            // An empty value through a number filter stays empty.
            return '';
        }
    }

    protected static function truncateWords(string $text, int $words): string
    {
        $parts = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return count($parts) <= max(0, $words) ? $text : implode(' ', array_slice($parts, 0, max(0, $words)));
    }

    protected static function sort(mixed $value, ?string $field): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $list = array_values($value);
        $key = fn ($element) => $field === null ? $element : (is_array($element) ? ($element[$field] ?? null) : null);
        usort($list, function ($a, $b) use ($key) {
            $ka = $key($a);
            $kb = $key($b);
            $na = Expression::maybeNumber($ka);
            $nb = Expression::maybeNumber($kb);

            return $na !== null && $nb !== null ? $na <=> $nb : strcmp(mb_strtolower(Expression::text($ka)), mb_strtolower(Expression::text($kb)));
        });

        return $list;
    }

    protected static function date(string $text, string $format): string
    {
        if (trim($text) === '') {
            return '';
        }
        $time = strtolower(trim($text)) === 'now' ? time() : (is_numeric($text) ? (int) $text : strtotime($text));

        return $time === false ? $text : date($format, $time);
    }

    /** Price units as a feed writes a price: "71990", "12.50", "0.012" — a dot, no thousands. */
    protected static function priceText(int $units): string
    {
        $per = price_scale()->perMajor();

        return $units % $per === 0 ? (string) intdiv($units, $per) : price_scale()->trimmed($units);
    }

    /** 71990.0 → "71990", 12.5 → "12.50" at two decimals, 0.171 → "0.171". */
    public static function amount(float $amount, int $decimals): string
    {
        if (abs($amount - round($amount)) < 1e-9) {
            return (string) (int) round($amount);
        }

        $text = number_format($amount, $decimals, '.', '');

        // Money keeps its two decimals; a weight loses the zeros nobody writes.
        return $decimals === 2 ? $text : rtrim(rtrim($text, '0'), '.');
    }
}
