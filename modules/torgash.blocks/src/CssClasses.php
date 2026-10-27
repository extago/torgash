<?php

namespace Torgash\Blocks;

/**
 * The CSS classes a shop gives a block or its band, for its own stylesheet
 * to find them by. Only plain class names pass — letters, digits, `-`, `_`,
 * not starting with a digit — so the field can never carry a quote, an
 * attribute or markup onto the page. Tailwind's utilities are NOT what this
 * is for: the stylesheet is built from the templates, and a utility typed
 * here exists only if some template happens to use it already.
 */
class CssClasses
{
    public const MAX = 190;

    /** What was typed, reduced to valid names in order, duplicates dropped; null when none is left. */
    public static function accept(mixed $typed): ?string
    {
        $names = [];

        foreach (preg_split('/[\s,]+/', (string) $typed, -1, PREG_SPLIT_NO_EMPTY) as $name) {
            if (preg_match('/^-?[A-Za-z_][A-Za-z0-9_-]*$/', $name) && ! in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        $joined = implode(' ', $names);

        return $joined === '' ? null : mb_substr($joined, 0, self::MAX);
    }

    /** Names typed that were not taken — for the form to say so rather than drop them silently. */
    public static function rejected(mixed $typed): array
    {
        return array_values(array_filter(
            preg_split('/[\s,]+/', (string) $typed, -1, PREG_SPLIT_NO_EMPTY),
            fn (string $name) => ! preg_match('/^-?[A-Za-z_][A-Za-z0-9_-]*$/', $name),
        ));
    }

    /**
     * $html with $classes added to its outermost element: into its `class`
     * attribute when it has one, as a new one when not. Output that does not
     * start with an ordinary element (text, a <style>, a <script>) is wrapped
     * in a <div> instead — a class on a <style> styles nothing.
     */
    public static function onRoot(string $html, ?string $classes): string
    {
        $classes = self::accept($classes);

        if ($classes === null || trim($html) === '') {
            return $html;
        }

        // Attributes read quote by quote: Alpine's `x-show="count > 1"` holds a `>`.
        if (! preg_match('/^(\s*(?:<!--.*?-->\s*)*)<([a-z][a-z0-9-]*)\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/is', $html, $m)
            || in_array(strtolower($m[2]), ['style', 'script', 'template', 'link', 'meta'], true)) {
            return '<div class="'.$classes.'">'.$html.'</div>';
        }

        [$whole, $lead, $tag, $attributes] = $m;

        // `\s` before it: never `:class` or `x-bind:class`, which are Alpine's.
        if (preg_match('/\sclass\s*=\s*(["\'])(.*?)\1/is', $attributes, $c)) {
            $attributes = str_replace($c[0], ' class='.$c[1].trim($c[2].' '.$classes).$c[1], $attributes);
        } else {
            $attributes .= ' class="'.$classes.'"';
        }

        return $lead.'<'.$tag.$attributes.'>'.substr($html, strlen($whole));
    }
}
