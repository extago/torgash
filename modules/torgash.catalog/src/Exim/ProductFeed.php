<?php

namespace Torgash\Catalog\Exim;

use App\Models\Currency;
use App\Models\Storefront;
use Torgash\Catalog\Features\OwnRows;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductFeature;
use Torgash\Catalog\Support\CategoryTree;

/**
 * What a marketplace feed asks of a product and of the shop around it (a
 * YML for Yandex Market, a Google XML) — the values an export profile's own
 * template prints (Torgash\Exim\Template\FeedTemplate).
 *
 * Export only and `on_request`: a profile ticking "every field" exports
 * what it always did; a template that names `item.pictures` reads it. The
 * two lists — `pictures` and `params` — are `template_only`: a cell of a
 * spreadsheet cannot hold a list (the `images` column is the photos as one
 * line).
 *
 * The comparisons a feed makes are made HERE, where the rules are, not in the
 * template: `old_price` is there only when it is higher than the price, and
 * `available` is the product's own answer to "can it be ordered".
 */
class ProductFeed
{
    /** @return list<array> exim field declarations */
    public function fields(): array
    {
        $features = ['featureAssignments.feature', 'featureAssignments.featureValue'];

        return [
            ['key' => 'id', 'group' => 'main', 'on_request' => true, 'label' => 'ID', 'get' => fn (Product $p) => $p->id, 'set' => null],
            ['key' => 'url', 'group' => 'main', 'on_request' => true, 'label' => 'Product page address', 'set' => null,
                'get' => fn (Product $p) => $p->slug ? route('catalog.product', $p->slug) : null],
            ['key' => 'available', 'group' => 'stock', 'on_request' => true, 'label' => 'Can be ordered (true/false)', 'set' => null,
                'get' => fn (Product $p) => $p->isOrderable()],
            ['key' => 'category_id', 'group' => 'main', 'on_request' => true, 'label' => 'Category ID', 'set' => null, 'with' => ['categories'],
                'get' => fn (Product $p) => $p->categories->sortBy('id')->first()?->id],
            // The struck-through price, only when there is something to strike through.
            ['key' => 'old_price', 'group' => 'prices', 'on_request' => true, 'label' => 'Old price, when higher (export only)', 'set' => null,
                'get' => fn (Product $p) => ($old = $p->oldPrice()) > $p->publicPrice() ? $old : null],
            ['key' => 'pictures', 'group' => 'media', 'on_request' => true, 'template_only' => true, 'label' => 'Photos (a list)', 'set' => null, 'with' => ['media'],
                'get' => fn (Product $p) => $p->getMedia('gallery')->map(fn ($media) => url($media->getUrl()))->values()->all()],
            ['key' => 'params', 'group' => 'features', 'on_request' => true, 'template_only' => true, 'label' => 'Characteristics (a list)', 'set' => null, 'with' => $features,
                'get' => fn (Product $p) => self::params($p)],
        ];
    }

    /**
     * The product's characteristics as a feed lists them: the shop's first —
     * the brand left out, a feed has its own `vendor` — then the product's
     * own rows in the order its page prints them. `own` tells them apart
     * (`{{^own}}` in a template keeps a feed to the shop's).
     *
     * An own row named like a characteristic the product already has a value
     * of is left out: the page may print both (a filter's "16 GB" beside the
     * row "16 GB DDR4, two slots"), but a marketplace reads two
     * `<param name="RAM">` as a conflict, not as detail, and keeps the
     * structured one anyway.
     *
     * @return list<array{name: string, value: string, code: ?string, own: bool}>
     */
    public static function params(Product $product, ?string $locale = null): array
    {
        $locale ??= content_base_locale();

        $assigned = $product->featureAssignments
            ->filter(fn (ProductFeature $pf) => $pf->feature && trim((string) $pf->display($locale)) !== '');

        $params = $assigned
            ->filter(fn (ProductFeature $pf) => $pf->feature->purpose !== 'brand')
            ->sortBy(fn (ProductFeature $pf) => [$pf->feature->sort, $pf->feature->id])
            ->map(fn (ProductFeature $pf) => [
                'name' => (string) pick_translation((array) $pf->feature->name, $locale),
                'value' => trim((string) $pf->display($locale)),
                'code' => $pf->feature->code,
                'own' => false,
            ])
            ->filter(fn (array $param) => $param['name'] !== '')
            ->values()
            ->all();

        foreach ($product->featureRows(locale: $locale) as $row) {
            if ($row['type'] !== 'feature' || $assigned->contains(fn (ProductFeature $pf) => OwnRows::names($pf->feature, $row['key']))) {
                continue;
            }

            $value = is_array($row['value'])
                ? implode(', ', array_filter(array_map(fn ($word) => trim((string) $word), $row['value']), fn ($word) => $word !== ''))
                : trim((string) $row['value']);

            if (trim($row['label']) !== '' && $value !== '') {
                $params[] = ['name' => trim($row['label']), 'value' => $value, 'code' => null, 'own' => true];
            }
        }

        return $params;
    }

    /**
     * The shop around the offers: `shop.name`, `shop.url`, `shop.currency`,
     * `shop.currencies` (id, rate) and `shop.categories` (id, parent_id, name)
     * — the categories a customer can open, parents before children.
     */
    public function context(array $context, ?array $locales = null): array
    {
        $locale = $locales[0] ?? content_base_locale();
        $storefront = Storefront::query()->where('is_default', true)->first() ?? Storefront::query()->orderBy('id')->first();
        $name = (string) ($storefront?->name ?? config('app.name'));

        $categories = app(CategoryTree::class)->ordered(true)->map(fn (Category $category) => [
            'id' => $category->id,
            'parent_id' => $category->parent_id,
            'name' => (string) pick_translation((array) $category->name, $locale),
        ])->values()->all();

        $currencies = Currency::query()->where('enabled', true)->orderBy('id')->get()
            ->map(fn (Currency $currency) => ['id' => $currency->code, 'rate' => $currency->code === shop_currency() ? 1 : $currency->rate])
            ->values()->all();

        return $context + ['shop' => [
            'name' => $name,
            'company' => $name,
            // Its own domain, in the scheme the shop is served in — an export
            // run by the scheduler has no request to borrow one from.
            'url' => ($domain = collect($storefront?->domains ?? [])->first())
                ? (parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https').'://'.$domain
                : rtrim(url('/'), '/'),
            'currency' => shop_currency(),
            'currencies' => $currencies,
            'categories' => $categories,
        ]];
    }
}
