<?php

namespace App\Core\Content;

/**
 * A template an ADMIN types — a webhook body, a chat message, an export
 * feed's own shape — deliberately NOT Blade.
 *
 * Blade compiles to PHP. Handing it a string an admin typed into a form makes
 * that form a way to run code on the server, and no amount of escaping
 * afterwards takes that back. In core because two modules that do not know
 * each other (automations, exim) need exactly this and nothing more. This understands exactly three things and nothing
 * else:
 *
 *   {{ order.total }}                       one value, by dotted path
 *   {{#order.items}} … {{/order.items}}     repeat the block per item,
 *                                           addressed inside as {{ item.sku }}
 *   {{#item.options_text}} … {{/…}}         over a VALUE rather than a list:
 *                                           the block once when it has one
 *                                           (not empty, not 0), nothing when
 *                                           it has not — "Options: …" only
 *                                           where there are options
 *
 * A block inside a block is resolved with the row it is in, so a value's
 * block inside the lines' loop reads the line's own value.
 *
 * Values are escaped for a JSON string context by default, because that is
 * what a webhook body is: a customer called `O"Brien` must not end the string
 * and break the payload. A value used outside quotes (a number) is unaffected —
 * escaping only touches characters JSON forbids inside a string. A message a
 * chat renders as HTML (Telegram's `parse_mode=HTML`) asks for `html` instead:
 * the tags the admin typed stay tags, and a customer named `<b>` stays text.
 */
class SafeTemplate
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function render(string $template, array $context, string $escape = self::JSON): string
    {
        $rendered = self::loops($template, $context, $escape);

        return self::values($rendered, $context, 'item', $escape);
    }

    public const JSON = 'json';

    public const HTML = 'html';

    /** XML text and attributes want exactly what HTML wants. */
    public const XML = 'html';

    /** Verbatim — a CSV line, where quoting is the template's own business. */
    public const RAW = 'raw';

    /**
     * Expand every `{{#path}}…{{/path}}` block: over the list it names, a row
     * at a time — or, named after a value, once if the value is there and
     * not at all if it is empty. A block's body goes through this again with
     * the row bound, so a value's block inside the loop reads the row.
     *
     * Non-greedy on purpose: two blocks of the SAME name one inside the other
     * cannot be told apart, and a body that needs that is a body that should
     * be built by a module's own action, not typed into a textarea.
     *
     * @param  array<string, mixed>  $context
     */
    protected static function loops(string $template, array $context, string $escape = self::JSON): string
    {
        return (string) preg_replace_callback(
            '/\{\{#\s*([a-z0-9_.]+)\s*\}\}(.*?)\{\{\/\s*\1\s*\}\}/is',
            function (array $match) use ($context, $escape) {
                $rows = self::lookup($match[1], $context);

                if (! is_array($rows)) {
                    // A value: the block once, or nothing. 0 and "0" count as
                    // nothing — "Discount: 0" is a line nobody wanted.
                    return self::has($rows)
                        ? self::values(self::loops($match[2], $context, $escape), $context, null, $escape)
                        : '';
                }

                $out = [];
                foreach ($rows as $row) {
                    if (is_array($row)) {
                        $rowContext = ['item' => $row] + $context;
                        $out[] = self::values(self::loops($match[2], $rowContext, $escape), $rowContext, null, $escape);
                    }
                }

                // The separator is not ours to choose: a JSON array needs a
                // comma between elements and the admin writes it after the
                // block, so a trailing one is what would break the payload.
                // Joining with nothing keeps whatever they wrote inside.
                return implode('', $out);
            },
            $template,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  string|null  $strip  a root whose leftovers are blanked (an
     *     `{{ item.sku }}` outside any loop has no value and must not survive
     *     into the payload as literal braces)
     */
    protected static function values(string $template, array $context, ?string $strip = null, string $escape = self::JSON): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-z0-9_.]+)\s*\}\}/i',
            function (array $match) use ($context, $strip, $escape) {
                $value = self::lookup($match[1], $context);

                if (is_array($value)) {
                    return '';
                }

                if ($value === null) {
                    return $strip !== null && str_starts_with($match[1], $strip.'.') ? '' : $match[0];
                }

                if (is_bool($value)) {
                    return $value ? 'true' : 'false';
                }

                return match ($escape) {
                    self::HTML => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    self::RAW => (string) $value,
                    default => self::escape((string) $value),
                };
            },
            $template,
        );
    }

    /** Whether a value is there to show: not null, not false, not '', not 0. */
    protected static function has(mixed $value): bool
    {
        return ! ($value === null || $value === false || $value === '' || $value === 0 || $value === '0' || $value === 0.0);
    }

    /** @param  array<string, mixed>  $context */
    protected static function lookup(string $path, array $context): mixed
    {
        $value = $context;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /** JSON string escaping, without the quotes json_encode would add. */
    protected static function escape(string $value): string
    {
        return trim(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '""', '"');
    }
}
