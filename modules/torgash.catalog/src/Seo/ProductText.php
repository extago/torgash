<?php

namespace Torgash\Catalog\Seo;

use Torgash\Catalog\Models\Product;

/**
 * The product page as text — the same page, in the form a program reads
 * without a browser.
 *
 * Not a second frontend and deliberately not a second source of truth: every
 * line comes from `ProductFacts`, the same object the page head's structured
 * data is built from, so the text version cannot quote a price the shop does
 * not charge. What it costs is this file; what a "lightweight site for
 * agents" would have cost is a second catalogue that disagrees with the first
 * by the end of the month.
 *
 * Built as lines of PHP rather than a blade view because the output is
 * whitespace-significant: in Markdown a stray indent is a code block and a
 * missing blank line is a paragraph that never ends, and a template makes both
 * of those invisible.
 */
class ProductText
{
    public static function render(Product $product): string
    {
        $facts = new ProductFacts($product);
        $price = $facts->price();

        $out = ['# '.$facts->name(), ''];

        // The one-line answer to "what is this", before the list of facts —
        // where a person reads it on the page, and the first thing a machine
        // reading the file needs in order to know whether to read the rest.
        if ($short = $facts->shortDescription()) {
            $out[] = $short;
            $out[] = '';
        }

        $lines = ['- '.__('Page address').': '.$facts->url()];
        // "Price from" where the product cannot be bought without answering
        // something: a machine reading this file and a person reading the page
        // must be told the same thing, which is the whole point of both
        // renditions coming out of one ProductFacts.
        $lines[] = '- '.($facts->priceIsFrom() ? __('Price from') : __('Price')).': '
            .$price['amount'].' '.$price['currency'];

        if ($old = $facts->oldPrice()) {
            $lines[] = '- '.__('Old price').': '.$old['amount'].' '.$old['currency'];
        }

        $lines[] = '- '.__('Availability').': '.$facts->availability();

        if ($product->sku) {
            $lines[] = '- '.__('SKU').': '.$product->sku;
        }

        if ($brand = $facts->brand()) {
            $lines[] = '- '.__('Brand').': '.$brand;
        }

        if ($categories = $facts->categories()) {
            $lines[] = '- '.__('Categories').': '.implode(', ', $categories);
        }

        $out = [...$out, ...$lines, ''];

        if ($description = $facts->description()) {
            $out[] = '## '.__('Description');
            $out[] = '';
            $out[] = $description;
            $out[] = '';
        }

        if ($specs = $facts->specs()) {
            $out[] = '## '.__('Characteristics');
            $out[] = '';
            $out = [...$out, ...self::table($specs), ''];
        }

        if ($images = $facts->images()) {
            $out[] = '## '.__('Photographs');
            $out[] = '';

            foreach ($images as $image) {
                $out[] = '- !['.$image['alt'].']('.$image['url'].')';
            }

            $out[] = '';
        }

        if ($variants = $facts->variants()) {
            $out[] = '## '.__('Other variations of this product');
            $out[] = '';

            foreach ($variants as $variant) {
                $out[] = '- ['.$variant['name'].']('.$variant['url'].'.md)';
            }

            $out[] = '';
        }

        // Another module's half of the page — reviews, delivery terms for this
        // product, whatever hangs off it in HTML. Appended, never replacing:
        // the same rule as the sitemap's own hook.
        $out[] = trim((string) hook_filter('catalog.product.text', '', ['product' => $product, 'facts' => $facts]));

        return trim(implode("\n", $out))."\n";
    }

    /**
     * A pipe table, with the layout's section headings as a full-width row of
     * their own: a Markdown table has no way to say "the rows below belong
     * together", and dropping the headings would leave two "Weight" rows in
     * one table meaning different things.
     *
     * @param  list<array{type: string, label: string, value?: string}>  $specs
     * @return list<string>
     */
    protected static function table(array $specs): array
    {
        $rows = ['| '.__('Characteristic').' | '.__('Value').' |', '| --- | --- |'];

        foreach ($specs as $spec) {
            $label = self::cell($spec['label']);

            $rows[] = $spec['type'] === 'section'
                ? '| **'.$label.'** | |'
                : '| '.$label.' | '.self::cell((string) ($spec['value'] ?? '')).' |';
        }

        return $rows;
    }

    /** A pipe inside a cell ends it; a newline ends the row. */
    protected static function cell(string $value): string
    {
        return trim(str_replace(['|', "\n"], ['\\|', ' '], $value));
    }
}
