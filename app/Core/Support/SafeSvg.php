<?php

namespace App\Core\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * An uploaded SVG made safe to publish.
 *
 * An SVG is a DOCUMENT, not a picture: drawn through `<img>` its scripts do not
 * run, but the same file opened by its own address runs them on the shop's
 * origin — a stored XSS any admin who can upload a label could plant. So an SVG
 * is rebuilt from an ALLOW-list rather than cleaned by a deny-list: drawing
 * elements and presentation attributes survive, everything else (scripts,
 * event handlers, foreignObject, style sheets, links anywhere but inside the
 * file itself) is dropped. Parsed with no network and no entity expansion.
 *
 * Returns null for anything that is not an SVG worth keeping — refuse the
 * upload rather than store half a drawing.
 */
class SafeSvg
{
    public const MAX_BYTES = 256 * 1024;

    protected const ELEMENTS = [
        'svg', 'g', 'path', 'circle', 'ellipse', 'rect', 'line', 'polyline', 'polygon',
        'text', 'tspan', 'defs', 'lineargradient', 'radialgradient', 'stop', 'clippath',
        'mask', 'pattern', 'symbol', 'use', 'title', 'desc',
    ];

    protected const ATTRIBUTES = [
        'id', 'class', 'viewbox', 'width', 'height', 'x', 'y', 'x1', 'x2', 'y1', 'y2', 'cx', 'cy', 'r', 'rx', 'ry',
        'd', 'points', 'transform', 'fill', 'fill-opacity', 'fill-rule', 'clip-rule', 'stroke', 'stroke-width',
        'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset',
        'stroke-opacity', 'opacity', 'offset', 'stop-color', 'stop-opacity', 'gradientunits', 'gradienttransform',
        'spreadmethod', 'fx', 'fy', 'clip-path', 'mask', 'patternunits', 'patterncontentunits', 'preserveaspectratio',
        'font-family', 'font-size', 'font-weight', 'text-anchor', 'dominant-baseline', 'letter-spacing',
        'xmlns', 'version', 'href', 'xlink:href', 'color', 'display', 'visibility', 'vector-effect',
    ];

    /** What puts ink on the picture: without one of these it is blank. */
    protected const DRAWING = ['path', 'circle', 'ellipse', 'rect', 'line', 'polyline', 'polygon', 'text', 'use'];

    /** CSS an editor may write for a drawing, each the twin of an attribute above. */
    protected const STYLE_PROPERTIES = [
        'fill', 'fill-opacity', 'fill-rule', 'clip-rule', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin',
        'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'stroke-opacity', 'opacity', 'stop-color',
        'stop-opacity', 'clip-path', 'mask', 'font-family', 'font-size', 'font-weight', 'text-anchor',
        'dominant-baseline', 'letter-spacing', 'color', 'display', 'visibility',
    ];

    public static function clean(string $markup): ?string
    {
        if ($markup === '' || strlen($markup) > self::MAX_BYTES || stripos($markup, '<svg') === false) {
            return null;
        }

        // No DOCTYPE: an internal subset is where entity bombs and external
        // entities live, and an SVG needs neither.
        if (stripos($markup, '<!DOCTYPE') !== false || stripos($markup, '<!ENTITY') !== false) {
            return null;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($markup, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->documentElement;

        if (! $loaded || ! $root || strtolower($root->localName) !== 'svg') {
            return null;
        }

        self::inlineStyles($root);
        self::scrub($root);

        // A file that was nothing but a script is an empty <svg> once
        // scrubbed: refused, not published as a blank.
        if (! self::draws($root)) {
            return null;
        }

        if (! $root->hasAttribute('viewBox') && $root->hasAttribute('width') && $root->hasAttribute('height')) {
            $root->setAttribute('viewBox', '0 0 '.(float) $root->getAttribute('width').' '.(float) $root->getAttribute('height'));
        }

        return $document->saveXML($root) ?: null;
    }

    /**
     * Colours an editor wrote as CSS — Illustrator's `<style>.cls-1{fill:…}`,
     * Inkscape's `style="fill:…"` — become the attributes they stand for
     * BEFORE the scrub drops every style sheet and `style`, so a logo keeps
     * its colours (it came out black) and not a line of CSS is published.
     * Class rules first, in their order, then the element's own `style`,
     * which beats them, as in CSS; anything but a single class is ignored.
     */
    protected static function inlineStyles(DOMElement $root): void
    {
        $rules = [];

        foreach (iterator_to_array($root->getElementsByTagName('style')) as $sheet) {
            $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) $sheet->textContent);
            preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $found, PREG_SET_ORDER);

            foreach ($found as [, $selectors, $body]) {
                foreach (explode(',', $selectors) as $selector) {
                    if (preg_match('/^\s*\.([\w-]+)\s*$/', $selector, $class)) {
                        $rules[] = [$class[1], self::declarations($body)];
                    }
                }
            }
        }

        foreach ([$root, ...iterator_to_array($root->getElementsByTagName('*'))] as $element) {
            $classes = preg_split('/\s+/', trim($element->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            foreach ($rules as [$class, $declarations]) {
                if (in_array($class, $classes, true)) {
                    self::apply($element, $declarations);
                }
            }

            if ($element->hasAttribute('style')) {
                self::apply($element, self::declarations($element->getAttribute('style')));
            }
        }
    }

    /** @return array<string, string> property => value, the drawing's own properties only */
    protected static function declarations(string $css): array
    {
        $out = [];

        foreach (explode(';', $css) as $declaration) {
            [$name, $value] = array_pad(explode(':', $declaration, 2), 2, '');
            $name = strtolower(trim($name));
            $value = trim(str_ireplace('!important', '', $value));

            if ($value !== '' && in_array($name, self::STYLE_PROPERTIES, true)) {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    /** @param  array<string, string>  $declarations  checked by the scrub like any attribute */
    protected static function apply(DOMElement $element, array $declarations): void
    {
        foreach ($declarations as $name => $value) {
            $element->setAttribute($name, $value);
        }
    }

    protected static function draws(DOMElement $root): bool
    {
        foreach ($root->getElementsByTagName('*') as $element) {
            if (in_array(strtolower($element->localName), self::DRAWING, true)) {
                return true;
            }
        }

        return false;
    }

    protected static function scrub(DOMElement $element): void
    {
        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            $name = strtolower($attribute->nodeName);
            $value = trim($attribute->nodeValue ?? '');

            $allowed = in_array($name, self::ATTRIBUTES, true)
                // A link may point inside the file (a gradient, a <use>), never out of it.
                && (! in_array($name, ['href', 'xlink:href'], true) || str_starts_with($value, '#'))
                // A paint or clip reference likewise: url(#id) only.
                && ! (stripos($value, 'url(') !== false && ! preg_match('/^url\(\s*#[\w.-]+\s*\)$/i', $value))
                && stripos($value, 'javascript:') === false;

            if (! $allowed) {
                $element->removeAttributeNode($attribute);
            }
        }

        foreach (iterator_to_array($element->childNodes) as $child) {
            /** @var DOMNode $child */
            if ($child instanceof DOMElement) {
                if (in_array(strtolower($child->localName), self::ELEMENTS, true)) {
                    self::scrub($child);
                } else {
                    $element->removeChild($child);
                }
            } elseif ($child->nodeType !== XML_TEXT_NODE) {
                // Comments, processing instructions, CDATA: nothing a drawing needs.
                $element->removeChild($child);
            }
        }
    }
}
