<?php

namespace Torgash\Banners;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * The few tags a banner's words may carry (PLAN §51): a bold word, a
 * gradient word (`<span class="…">`), a struck-through separator
 * ("17 Pro <s>|</s> 17 Pro Max"), a line break. Everything else is taken
 * apart — an unknown tag leaves its text, a script or a style leaves nothing
 * — so a field is never a way to put markup or code on the storefront.
 *
 * Run when the banner is SAVED (`Banner::saving`), by whatever saves it: the
 * form, an import, a test. The page then prints what was stored.
 */
class RichLine
{
    /** Tags kept, with the attributes each may keep. */
    public const TAGS = [
        'b' => [], 'strong' => [], 'i' => [], 'em' => [], 's' => [],
        'br' => [], 'sup' => [], 'sub' => [], 'span' => ['class', 'style'],
    ];

    /** Elements dropped with everything inside them. */
    protected const DROP = ['script', 'style', 'template', 'iframe', 'object', 'embed', 'svg', 'math', 'noscript', 'textarea', 'select'];

    public static function clean(?string $html): string
    {
        $html = trim((string) $html);

        if ($html === '') {
            return '';
        }

        // Plain words — the usual case — need no parser: without a "<" there
        // is no tag, and they stay exactly as typed for the next edit.
        if (! str_contains($html, '<')) {
            return $html;
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><div id="rich-line">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('rich-line');

        if (! $root) {
            return e(strip_tags($html));
        }

        self::walk($root);

        $out = '';

        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $document->saveHTML($child);
        }

        return trim($out);
    }

    protected static function walk(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE) {
                $node->removeChild($child);

                continue;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::DROP, true)) {
                $node->removeChild($child);

                continue;
            }

            self::walk($child);

            if (! array_key_exists($tag, self::TAGS)) {
                // An unknown tag gives up its place, never its words.
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }

                $node->removeChild($child);

                continue;
            }

            foreach (iterator_to_array($child->attributes) as $attribute) {
                $name = strtolower($attribute->name);

                if (! in_array($name, self::TAGS[$tag], true)) {
                    $child->removeAttribute($attribute->name);
                } elseif ($name === 'style') {
                    $style = self::style($attribute->value);
                    $style === '' ? $child->removeAttribute($attribute->name) : $child->setAttribute('style', $style);
                } elseif ($name === 'class') {
                    $class = trim(preg_replace('/[^A-Za-z0-9_:\/\[\]#.%-]+/', ' ', $attribute->value));
                    $class === '' ? $child->removeAttribute($attribute->name) : $child->setAttribute('class', preg_replace('/\s+/', ' ', $class));
                }
            }
        }
    }

    /**
     * A colour, a weight, a gradient — but nothing that fetches or runs: a
     * declaration naming `url(`, `expression(`, `javascript:` or an `@`-rule
     * is dropped whole.
     */
    protected static function style(string $style): string
    {
        $kept = [];

        foreach (explode(';', $style) as $declaration) {
            $declaration = trim($declaration);

            if ($declaration === '' || ! str_contains($declaration, ':')) {
                continue;
            }

            if (preg_match('/url\s*\(|expression\s*\(|javascript:|@|\\\\|behavior|-moz-binding/i', $declaration)) {
                continue;
            }

            $kept[] = $declaration;
        }

        return implode('; ', $kept);
    }
}
