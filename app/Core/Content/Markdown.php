<?php

namespace App\Core\Content;

use DOMDocument;
use DOMNode;
use DOMText;

/**
 * Turns the HTML an admin wrote in a rich-text field back into text.
 *
 * In CORE because the rich-text FIELD is core's (`core.field.rich`, which
 * `torgash.editor` only decorates): whatever core lets somebody type, core has
 * to be able to read back. Two modules already need it — a product's
 * description and a page's body — and two copies of an HTML walker would
 * disagree about nested lists within a month.
 *
 * Deliberately NOT a general HTML-to-Markdown library. The HTML this converts
 * is our own editor's output, and its toolbar (`Torgash\Editor\Toolbar::BUTTONS`)
 * names every tag it can produce, so the set is closed and small. What the
 * toolbar cannot make but a person can paste through the HTML source view —
 * a table, most of all — is passed through as HTML, which Markdown allows and
 * every reader of Markdown understands. Dropping it would silently lose a size
 * chart, and re-implementing a table layout engine to keep it is the library
 * this class exists not to need.
 */
class Markdown
{
    public static function fromHtml(?string $html): string
    {
        $html = trim((string) $html);

        if ($html === '') {
            return '';
        }

        // No tag at all: an old plain-text description, already what we want.
        if (strip_tags($html) === $html) {
            return trim(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        // The XML declaration is what tells libxml the bytes are UTF-8; without
        // it a Cyrillic description comes back as mojibake, and no error is
        // raised anywhere. LIBXML_HTML_NOIMPLIED/NODEFDTD keep it from wrapping
        // the fragment in <html><body>, which would then have to be unwrapped.
        $document->loadHTML(
            '<?xml encoding="utf-8" ?><div>'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->documentElement;

        $text = $root ? (new self)->node($root) : '';

        // Collapse the runs of blank lines the walker leaves between blocks.
        $text = preg_replace("/\n{3,}/", "\n\n", str_replace("\r\n", "\n", $text));

        return trim((string) $text);
    }

    /** @param  list<string>  $listStack  the ul/ol chain this node sits inside, innermost last */
    protected function node(DOMNode $node, array $listStack = []): string
    {
        if ($node instanceof DOMText) {
            // Newlines inside the source HTML are formatting of the markup,
            // not of the text — the editor writes one long line and a person
            // editing the source wraps it wherever the window ends.
            return preg_replace('/\s+/u', ' ', $node->textContent) ?? '';
        }

        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return '';
        }

        $tag = strtolower($node->nodeName);
        $inner = fn (?array $stack = null) => $this->children($node, $stack ?? $listStack);

        // What only works on the page — a form to fill in, a script — means
        // nothing as text, and a block marked `data-text-skip` (a form placed
        // in a text with its title) says so itself. Left out, not flattened
        // into "Your name Phone Send".
        if (in_array($tag, ['form', 'script', 'style', 'template', 'button'], true)
            || ($node instanceof \DOMElement && $node->hasAttribute('data-text-skip'))) {
            return '';
        }

        return match ($tag) {
            'br' => "\n",
            'hr' => "\n\n---\n\n",
            'strong', 'b' => $this->wrap($inner(), '**'),
            'em', 'i' => $this->wrap($inner(), '*'),
            's', 'del', 'strike' => $this->wrap($inner(), '~~'),
            'code' => $node->parentNode && strtolower($node->parentNode->nodeName) === 'pre'
                ? $inner()
                : $this->wrap($inner(), '`'),
            'pre' => "\n\n```\n".trim($node->textContent)."\n```\n\n",
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => "\n\n".str_repeat('#', (int) substr($tag, 1)).' '.trim($inner())."\n\n",
            'p' => "\n\n".trim($inner())."\n\n",
            'blockquote' => "\n\n".$this->prefixLines(trim($inner()), '> ')."\n\n",
            'ul', 'ol' => "\n\n".trim($this->children($node, [...$listStack, $tag]), "\n")."\n\n",
            'li' => $this->listItem($node, $listStack),
            'a' => $this->link($node, $inner()),
            'img' => $this->image($node),
            // A table (and anything else nobody typed on purpose) survives as
            // HTML rather than being flattened into a line of stuck-together
            // cells. Markdown carries inline HTML; a lost size chart is lost.
            'table' => "\n\n".trim($this->outerHtml($node))."\n\n",
            default => $inner(),
        };
    }

    /** @param  list<string>  $listStack */
    protected function children(DOMNode $node, array $listStack): string
    {
        $out = '';

        foreach ($node->childNodes as $child) {
            $out .= $this->node($child, $listStack);
        }

        return $out;
    }

    /** @param  list<string>  $listStack */
    protected function listItem(DOMNode $node, array $listStack): string
    {
        $depth = max(0, count($listStack) - 1);
        $ordered = (end($listStack) ?: 'ul') === 'ol';

        $marker = $ordered ? $this->itemNumber($node).'. ' : '- ';
        $indent = str_repeat('  ', $depth);

        $body = trim($this->children($node, $listStack));

        // A nested list is a block inside the item: its lines are indented
        // under the marker, and the marker keeps its own first line.
        $lines = explode("\n", $body);
        $first = array_shift($lines) ?? '';

        $rest = array_map(fn ($line) => $line === '' ? '' : $indent.'  '.$line, $lines);

        return $indent.$marker.$first."\n".($rest ? implode("\n", $rest)."\n" : '');
    }

    protected function itemNumber(DOMNode $node): int
    {
        $number = 1;

        for ($sibling = $node->previousSibling; $sibling !== null; $sibling = $sibling->previousSibling) {
            if ($sibling->nodeType === XML_ELEMENT_NODE && strtolower($sibling->nodeName) === 'li') {
                $number++;
            }
        }

        return $number;
    }

    protected function link(DOMNode $node, string $text): string
    {
        $href = trim($this->attr($node, 'href'));
        $text = trim($text);

        if ($href === '') {
            return $text;
        }

        return $text === '' ? '<'.$href.'>' : '['.$text.']('.$href.')';
    }

    protected function image(DOMNode $node): string
    {
        $src = trim($this->attr($node, 'src'));

        if ($src === '') {
            return '';
        }

        return '!['.trim($this->attr($node, 'alt')).']('.$src.')';
    }

    protected function attr(DOMNode $node, string $name): string
    {
        return $node->attributes?->getNamedItem($name)?->nodeValue ?? '';
    }

    protected function outerHtml(DOMNode $node): string
    {
        return $node->ownerDocument?->saveHTML($node) ?: '';
    }

    /**
     * Emphasis around text that may start or end with a space: the space has
     * to move outside the marks, or `** bold **` renders as literal asterisks.
     */
    protected function wrap(string $text, string $mark): string
    {
        if (trim($text) === '') {
            return $text;
        }

        $leading = str_starts_with($text, ' ') ? ' ' : '';
        $trailing = str_ends_with($text, ' ') ? ' ' : '';

        return $leading.$mark.trim($text).$mark.$trailing;
    }

    protected function prefixLines(string $text, string $prefix): string
    {
        return implode("\n", array_map(
            fn ($line) => $line === '' ? trim($prefix) : $prefix.$line,
            explode("\n", $text),
        ));
    }
}
