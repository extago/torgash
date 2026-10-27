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

        self::scrub($root);

        if (! $root->hasAttribute('viewBox') && $root->hasAttribute('width') && $root->hasAttribute('height')) {
            $root->setAttribute('viewBox', '0 0 '.(float) $root->getAttribute('width').' '.(float) $root->getAttribute('height'));
        }

        return $document->saveXML($root) ?: null;
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
