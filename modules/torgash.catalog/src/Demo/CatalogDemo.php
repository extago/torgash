<?php

namespace Torgash\Catalog\Demo;

use App\Core\Demo\DemoPack;
use App\Core\Support\Currencies;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\Label;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Pricing\Prices;

/**
 * The catalogue's part of the demo shop (PLAN §55, core's DemoData): its
 * categories, characteristics with their values (a brand's logo among them),
 * products with pictures and variation groups, and the labels — "Demo" on
 * every product it makes, so a visitor to a freshly installed shop knows
 * what he is looking at.
 *
 * Read from `database/demo/catalog/*.json`. Everything goes through the
 * models, as the admin's own forms do, so codes, slugs, the search text and
 * prices in another currency come out exactly as for a product typed by
 * hand. Words are `{en, ru}` maps; prices are US dollars in major units,
 * converted to the shop's books (see price()).
 */
class CatalogDemo
{
    /** @var array<string, Category> */
    protected array $categories = [];

    /** @var array<string, Feature> */
    protected array $features = [];

    /** @var array<string, array<string, FeatureValue>> feature code => value code => value */
    protected array $values = [];

    /** @var array<string, Label> */
    protected array $labels = [];

    public function install(DemoPack $pack): void
    {
        $this->categories($pack);
        $this->features($pack);
        $this->labels($pack);
        $this->products($pack);
    }

    protected function categories(DemoPack $pack): void
    {
        foreach ($pack->json('catalog/categories') as $sort => $row) {
            $this->categories[$row['slug']] = Category::create([
                'slug' => $row['slug'],
                'name' => $row['name'],
                'description' => $row['description'] ?? null,
                'parent_id' => isset($row['parent']) ? $this->categories[$row['parent']]->id ?? null : null,
                'icon' => $row['icon'] ?? null,
                'sort' => $row['sort'] ?? $sort,
                'status' => Category::STATUS_ACTIVE,
            ]);
        }
    }

    protected function features(DemoPack $pack): void
    {
        foreach ($pack->json('catalog/features') as $sort => $row) {
            $feature = Feature::create([
                'code' => $row['code'],
                'name' => $row['name'],
                'purpose' => $row['purpose'] ?? 'filter',
                'input_type' => $row['input_type'] ?? 'select',
                'shown_in' => $row['shown_in'] ?? ['filters', 'specs', 'buy_box'],
                'product_style' => $row['product_style'] ?? 'labels',
                'filter_style' => $row['filter_style'] ?? 'list',
                'is_color' => (bool) ($row['is_color'] ?? false),
                'sort' => $row['sort'] ?? $sort,
            ]);
            $this->features[$row['code']] = $feature;

            foreach ((array) ($row['values'] ?? []) as $valueSort => $valueRow) {
                $value = FeatureValue::create([
                    'feature_id' => $feature->id,
                    'code' => $valueRow['code'],
                    'value' => $valueRow['value'],
                    'slug' => $valueRow['slug'] ?? null,
                    'swatch' => $valueRow['swatch'] ?? null,
                    'description' => $valueRow['description'] ?? null,
                    'sort' => $valueRow['sort'] ?? $valueSort,
                ]);

                if ($logo = $pack->image($valueRow['logo'] ?? null)) {
                    $value->addMedia($logo)->preservingOriginal()->toMediaCollection('logo');
                }

                $this->values[$row['code']][$valueRow['code']] = $value;
            }
        }
    }

    protected function labels(DemoPack $pack): void
    {
        foreach ($pack->json('catalog/labels') as $sort => $row) {
            $this->labels[$row['code']] = Label::create([
                'name' => $row['name'],
                'name_for_admin' => $row['name_for_admin'] ?? ($row['name']['en'] ?? $row['code']),
                'style' => 'text',
                'color' => $row['color'] ?? 'neutral',
                'position_list' => $row['position'] ?? 'top-left',
                'position_detail' => $row['position'] ?? 'top-left',
                'size_list' => 'sm',
                'size_detail' => 'sm',
                'tooltip' => $row['tooltip'] ?? null,
                'conditions' => $row['conditions'] ?? null,
                'enabled' => true,
                'sort' => $row['sort'] ?? $sort,
            ]);
        }
    }

    protected function products(DemoPack $pack): void
    {
        $prices = app(Prices::class);
        [$currency, $rate] = $this->currency($pack);

        // A long description lives apart from the list (`description_from`).
        $descriptions = $pack->json('catalog/descriptions');

        foreach ($pack->json('catalog/products') as $row) {
            if (isset($row['description_from'])) {
                $row['description'] = $descriptions[$row['description_from']] ?? null;
            }

            $typed = ['price' => $this->converted((float) $row['price'], $rate)];
            if (isset($row['list_price'])) {
                $typed['list_price'] = $this->converted((float) $row['list_price'], $rate);
            }

            $product = Product::create([
                'sku' => $row['sku'],
                'slug' => $row['slug'],
                'name' => $row['name'],
                'short_description' => $row['short'] ?? null,
                'description' => $row['description'] ?? null,
                'stock' => $row['stock'] ?? 25,
                'weight' => $row['weight'] ?? null,
                'variation_group' => $row['group'] ?? null,
                'status' => Product::STATUS_ACTIVE,
            ] + $prices->columns($typed, $currency));

            $product->categories()->attach(array_values(array_filter(array_map(
                fn (string $slug) => $this->categories[$slug]->id ?? null,
                (array) ($row['categories'] ?? []),
            ))));

            foreach ((array) ($row['features'] ?? []) as $code => $answer) {
                $this->assign($product, (string) $code, $answer);
            }

            foreach ((array) ($row['images'] ?? []) as $file) {
                if ($path = $pack->image($file)) {
                    $product->addMedia($path)->preservingOriginal()->toMediaCollection('gallery');
                }
            }

            if (is_array($row['description'] ?? null)) {
                $product->update(['description' => $this->pictures($product, $row['description'], $pack)]);
            }

            $labels = array_unique(['demo', ...(array) ($row['labels'] ?? [])]);
            $product->labels()->attach(array_values(array_filter(array_map(
                fn (string $code) => $this->labels[$code]->id ?? null,
                $labels,
            ))));
        }
    }

    /**
     * Pictures inside a description: written `<img data-demo="file.png" …>`,
     * each becomes a picture of the product's text (the `content` collection,
     * as the editor puts one there) and the tag gets its address. A file the
     * pack does not have takes its tag with it — no broken image.
     *
     * @param  array<string, string>  $html  locale => markup
     * @return array<string, string>
     */
    protected function pictures(Product $product, array $html, DemoPack $pack): array
    {
        $urls = [];

        foreach ($html as $locale => $text) {
            $html[$locale] = (string) preg_replace_callback('~<img\b[^>]*\bdata-demo="([^"]+)"[^>]*>~', function (array $tag) use ($product, $pack, &$urls) {
                $file = $tag[1];
                if (! array_key_exists($file, $urls)) {
                    $path = $pack->image($file);
                    $urls[$file] = $path ? $product->addMedia($path)->preservingOriginal()->toMediaCollection('content')->getUrl() : null;
                }

                return $urls[$file] === null ? '' : str_replace('data-demo="'.$file.'"', 'src="'.e($urls[$file]).'"', $tag[0]);
            }, (string) $text);
        }

        return $html;
    }

    /** One characteristic of a product: a value's code for a list, the text or number otherwise. */
    protected function assign(Product $product, string $code, mixed $answer): void
    {
        $feature = $this->features[$code] ?? null;

        if (! $feature || $answer === null || $answer === '') {
            return;
        }

        if ($feature->input_type === 'select') {
            $value = $this->values[$code][(string) $answer] ?? null;
            if ($value) {
                $product->featureAssignments()->create(['feature_id' => $feature->id, 'feature_value_id' => $value->id]);
            }

            return;
        }

        $product->featureAssignments()->create(is_array($answer)
            ? ['feature_id' => $feature->id, 'value_text' => (string) ($answer['en'] ?? reset($answer)), 'value_translations' => $answer]
            : ['feature_id' => $feature->id, 'value_text' => (string) $answer]);
    }

    /**
     * What the pack's US dollars become: the shop's own currency, always. A
     * shop in dollars takes them as they are; any other gets them multiplied
     * by the pack's rough rate for its currency — a demo price, not a quote —
     * and typed in its books currency, as the owner would type them.
     *
     * Not "typed in dollars whenever the shop has dollars", which it used to
     * be: every shop has dollars, the seeder makes them, so a Russian shop's
     * demo goods all stood in its admin at "$799" (found on the first live
     * shop, 2026-10-21).
     *
     * @return array{0: ?string, 1: float}
     */
    protected function currency(DemoPack $pack): array
    {
        $books = strtoupper((string) shop_currency());

        if ($books === 'USD') {
            return [null, 1.0];
        }

        $rates = (array) ($pack->json('catalog/settings')['rates'] ?? []);

        return [null, (float) ($rates[$books] ?? 1.0)];
    }

    /**
     * A pack's dollar amount as minor units of the shop's books — for the
     * parts that hang off the catalogue (an option's fee, a kit's discount),
     * so their money lands in the same currency as the products' prices.
     */
    public function minor(DemoPack $pack, float $dollars): int
    {
        return (int) round($this->converted($dollars, $this->currency($pack)[1]) * 100);
    }

    /** A converted demo price, rounded as the books currency is written — whole roubles, kopecks for euros. */
    protected function converted(float $dollars, float $rate): float
    {
        if ($rate === 1.0) {
            return $dollars;
        }

        $decimals = (int) (app(Currencies::class)->find(shop_currency())?->decimals ?? 2);

        return round($dollars * $rate, $decimals);
    }
}
