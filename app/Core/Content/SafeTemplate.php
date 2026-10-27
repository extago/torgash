<?php

namespace App\Core\Content;

/**
 * A template an ADMIN types — a webhook body, a chat message, an export
 * feed's own shape — deliberately NOT Blade.
 *
 * Blade compiles to PHP. Handing it a string an admin typed into a form makes
 * that form a way to run code on the server, and no amount of escaping
 * afterwards takes that back. In core because two modules that do not know
 * each other (automations, exim) need exactly this and nothing more. This understands exactly two things and nothing
 * else:
 *
 *   {{ order.total }}                       one value, by dotted path
 *   {{#order.items}} … {{/order.items}}     repeat the block per item,
 *                                           addressed inside as {{ item.sku }}
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
     * Expand every `{{#path}}…{{/path}}` block over the list it names.
     *
     * Non-greedy and non-nested on purpose: a body that needs a loop inside a
     * loop is a body that should be built by a module's own action, not typed
     * into a textarea.
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
                    return '';
                }

                $out = [];
                foreach ($rows as $row) {
                    if (is_array($row)) {
                        $out[] = self::values($match[2], ['item' => $row] + $context, null, $escape);
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
