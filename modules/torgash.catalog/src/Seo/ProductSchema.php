<?php

namespace Torgash\Catalog\Seo;

use App\Core\Seo\StructuredData;
use Torgash\Catalog\Models\Product;

/**
 * schema.org Product/Offer for the page head — the machine-readable version
 * for whoever reads the HTML rather than asking for the text.
 *
 * Moved OUT of the blade template it was written in, and not only for tidiness:
 * a literal `@context` in a .blade.php file is a known trap (Blade's directive
 * scanner reads the raw template text, comments and @php blocks included, and
 * misparses it), so the file that has to contain those keys is now a PHP file
 * where they are ordinary strings. The template prints what this returns.
 *
 * Two things it corrected while moving. The price was the stored `price`
 * column, so a product on sale advertised the wrong amount to every search
 * engine — it is `ProductFacts` now, the same answer the shop charges. And
 * modules may add to it (`catalog.product.structured_data`), which is how a
 * ratings module contributes `aggregateRating` without the catalogue learning
 * its name.
 */
class ProductSchema
{
    /** @return array<string, mixed> */
    public static function for(Product $product): array
    {
        $facts = new ProductFacts($product);
        $price = $facts->price();

        // Keys built by concatenation — see the class docblock. Nothing in
        // this file needs to be an at-sign followed by a word, and the one
        // place that does is here, once.
        $at = '@';

        $schema = [
            // No `@context`: this is a node in the page's one graph now
            // (App\Core\Seo\StructuredData), and the context is stated once
            // there. A node carrying its own would start a second graph.
            $at.'type' => 'Product',
            'name' => $facts->name(),
            'sku' => $product->sku,
            'description' => $facts->plainDescription(),
            'image' => array_column($facts->images(800), 'url'),
            // A product that cannot be bought without answering something is
            // an AggregateOffer with a `lowPrice`, which is the vocabulary's
            // own word for "from" — quoting the cheapest build as a plain
            // `price` would state a fixed offer the shop does not make, and
            // that is the disagreement between the head and the page
            // `ProductFacts` exists to prevent (PLAN §33.7).
            'offers' => [
                $at.'type' => $facts->priceIsFrom() ? 'AggregateOffer' : 'Offer',
                'url' => $facts->url(),
                'priceCurrency' => $price['currency'],
                ($facts->priceIsFrom() ? 'lowPrice' : 'price') => $price['amount'],
                'availability' => $facts->schemaAvailability(),
                'itemCondition' => 'https://schema.org/NewCondition',
                // Who is selling it, by reference — the shop states itself once
                // in the page's graph and every offer points at that node.
                'seller' => [$at.'id' => app(StructuredData::class)->organisationId()],
            ],
        ];

        // An empty field is a claim with nothing in it: no description is
        // better said by leaving it out than by `"description": ""`.
        $schema['description'] = $schema['description'] !== '' ? $schema['description'] : $facts->shortDescription();
        $schema = array_filter($schema, fn ($value) => $value !== '' && $value !== []);

        if ($brand = $facts->brand()) {
            $schema['brand'] = [$at.'type' => 'Brand', 'name' => $brand];
        }

        if ($categories = $facts->categories()) {
            $schema['category'] = implode(' > ', $categories);
        }

        return hook_filter('catalog.product.structured_data', $schema, ['product' => $product, 'facts' => $facts]);
    }
}
