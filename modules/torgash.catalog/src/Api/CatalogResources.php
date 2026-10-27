<?php

namespace Torgash\Catalog\Api;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Torgash\Api\ApiResources;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductFeature;
use Torgash\Catalog\Pricing\Prices;
use Torgash\Catalog\Stock\AvailabilityStatuses;
use Torgash\Catalog\Support\ShortDescription;
use Torgash\Catalog\Support\Variations;

/**
 * What the catalogue puts on the API.
 *
 * Registered from the catalogue and not from the API module, for the same
 * reason exim's fields are: the module that owns the data owns what it says
 * about it, and `torgash.api` must not grow a list of every entity in the shop.
 *
 * The serialisers go through the same funnels the storefront does —
 * `catalog.price.display` for prices, `featureRows()` for specs — so a tax
 * module that adds tax to shown prices is not bypassed by asking over HTTP.
 */
class CatalogResources
{
    /** What `characteristics()` reads, loaded with the page rather than per row. */
    protected const CHARACTERISTICS = ['featureAssignments.feature', 'featureAssignments.featureValue'];

    public function register(): void
    {
        $this->products();
        $this->categories();
        $this->shop();
    }

    /**
     * The same two entities as a visitor sees them: only what is listed, only
     * what a customer may know, and prices through the display filter so a tax
     * module's addition is included exactly as it is on the page.
     *
     * A separate registration rather than a flag on the one above, because
     * these are different resources that happen to share a table — one carries
     * cost prices and disabled rows, the other must never be able to.
     */
    protected function shop(): void
    {
        ApiResources::register('shop.products', [
            'label' => 'Products',
            'description' => 'What a visitor may see: listed products only, prices through the same display filter the page uses, and no cost prices. Hidden products are absent here — they are still buyable by direct link, which is what the hidden state is for.',
            'query' => fn () => Product::listed()->withCardData()->with(self::CHARACTERISTICS),
            'sortable' => ['price', 'created_at', 'sales_count'],
            'lookup' => ['sku', 'slug'],
            'filters' => [
                'category' => [
                    'description' => 'A category slug, or its id.',
                    'example' => 'laptops',
                    'apply' => fn ($q, $v) => $q->whereHas('categories', fn ($c) => $c->where('slug', $v)->orWhere('id', is_numeric($v) ? $v : 0)),
                ],
                // The same full-text query the storefront's own search runs:
                // `websearch_to_tsquery` parses free-form input safely, and the
                // 'simple' pipeline is the one `search_vector` was built with.
                // Without the ranking here — a list has its own ordering — but
                // the same matching, so an app finds what the site finds.
                'q' => [
                    'description' => 'Free-form search, matched exactly as the storefront’s own search matches it — so an app finds what the site finds.',
                    'apply' => fn ($q, $v) => $q->whereRaw(
                        "search_vector @@ websearch_to_tsquery('simple', ?)",
                        [(string) $v],
                    ),
                ],
            ],
            'schema' => [
                'id' => 'integer',
                'url' => 'string',
                'sku' => 'string',
                'slug' => 'string',
                'name' => 'string',
                'short_description' => ['type' => 'string', 'description' => 'The one line under the name, exactly as the page prints it: what somebody wrote, or the shop’s own characteristics standing in for it. Empty when there is neither.'],
                'description' => 'string|null',
                'image' => ['type' => 'string|null', 'description' => 'The primary photo, already sized for a card.'],
                'currency' => ['type' => 'string', 'description' => 'What the prices below are displayed in — follows Accept-Language and the storefront.'],
                'price' => ['type' => 'integer', 'description' => 'Minor units, through the same display filter the page uses: a tax module’s addition is already inside it.'],
                'old_price' => ['type' => 'integer|null', 'description' => 'What it was struck through from, when there is one.'],
                'in_stock' => ['type' => 'boolean', 'description' => 'Orderable right now — a preorder counts, a discontinued product does not, whatever its stock number says.'],
                'characteristics' => ['type' => 'array', 'description' => 'The shop’s characteristics of this product — the ones its filters, variations and brand pages are made of — as the page’s specifications show them. One of two stores; the other is `features`, and nothing copies one into the other.', 'of' => [
                    'code' => ['type' => 'string', 'description' => 'The characteristic’s code: stable across renames and languages.'],
                    'name' => ['type' => 'string', 'description' => 'In the language of the request.'],
                    'value' => ['type' => 'string', 'description' => 'In the language of the request.'],
                    'value_code' => ['type' => 'string|null', 'description' => 'For a value picked from the characteristic’s list; null for one typed for this product.'],
                ]],
                'features' => ['type' => 'array', 'description' => 'The product’s OWN rows — typed for this product alone, printed under its characteristics and never filtered by — as a flat list in the order the shop arranged it: rows of `type: "section"` (a heading, with `label`) interleaved with rows of `type: "feature"` (`key` and `value`). Flat and ordered because jsonb does not keep key order.', 'of' => [
                    'type' => ['type' => 'string', 'enum' => ['section', 'feature']],
                    'label' => ['type' => 'string|null', 'description' => 'A section’s heading.'],
                    'key' => 'string|null',
                    'value' => 'string|null',
                ]],
            ],
            'serialize' => fn (Product $p) => hook_filter('api.shop.product.serialize', [
                'id' => $p->id,
                'url' => url('/api/v2/shop/products/'.$p->id),
                'sku' => $p->sku,
                'slug' => $p->slug,
                'name' => $p->t('name'),
                'short_description' => app(ShortDescription::class)->for($p),
                'description' => $p->t('description'),
                'image' => $p->primaryImageUrl(),
                // Through the same filter the storefront prices with, and in
                // the same minor units. `currency` says what a client should
                // format them as.
                'currency' => current_currency(),
                'price' => hook_filter('catalog.price.display', $p->effectivePrice(), ['product' => $p]),
                'old_price' => $p->oldPrice(),
                'in_stock' => $p->isOrderable(),
                'characteristics' => $this->characteristics($p, forVisitor: true),
                'features' => $p->featureRows(),
            ], ['product' => $p]),
        ]);

        ApiResources::register('shop.categories', [
            'label' => 'Categories',
            'description' => 'The catalogue tree as the storefront’s own menu draws it — active categories only; a hidden one opens by its address on the site but is not listed here.',
            'query' => fn () => Category::listed()->orderBy('sort'),
            'sortable' => ['sort'],
            'lookup' => ['slug'],
            'filters' => ['parent_id' => [
                'description' => 'Children of one category; empty for the top level.',
                'apply' => fn ($q, $v) => $q->where('parent_id', $v ?: null),
            ]],
            'schema' => [
                'id' => 'integer',
                'url' => 'string',
                'slug' => 'string',
                'name' => 'string',
                'parent_id' => ['type' => 'integer|null', 'description' => 'Null at the top level.'],
            ],
            'serialize' => fn (Category $c) => [
                'id' => $c->id,
                'url' => url('/api/v2/shop/categories/'.$c->slug),
                'slug' => $c->slug,
                'name' => $c->t('name'),
                'parent_id' => $c->parent_id,
            ],
        ]);
    }

    protected function products(): void
    {
        ApiResources::register('admin.products', [
            'label' => 'Products',
            'description' => 'The catalogue as the shop knows it — every row whatever its state, cost and list prices included. This is the half an exchange reads and writes.',
            'permission' => 'catalog.manage',
            'query' => fn () => Product::query()->with(['categories', 'labels', ...self::CHARACTERISTICS]),
            'sortable' => ['id', 'sku', 'price', 'stock', 'created_at', 'updated_at'],
            'lookup' => ['external_id', 'sku', 'slug'],
            'filters' => [
                'status' => [
                    'description' => 'active, hidden or disabled.',
                    'example' => 'active',
                    'apply' => fn ($q, $v) => $q->where('status', $v),
                ],
                'sku' => ['apply' => fn ($q, $v) => $q->where('sku', $v)],
                // Everything touched since a moment: the one filter an exchange
                // cannot work without, because pulling the whole catalogue every
                // night is what makes an integration something people turn off.
                'updated_after' => [
                    'description' => 'Everything touched since a moment (ISO 8601). Pulling the whole catalogue every night is what makes an integration something people turn off.',
                    'example' => '2026-09-01T00:00:00+03:00',
                    'apply' => fn ($q, $v) => $q->where('updated_at', '>=', $v),
                ],
            ],
            'serialize' => fn (Product $p) => $this->product($p),
            'update' => fn (Product $p, array $data) => $this->updateProduct($p, $data),
            'writable' => [
                'stock' => 'integer',
                'status' => ['type' => 'string', 'enum' => ['active', 'hidden', 'disabled']],
                'availability_status' => ['type' => 'string', 'description' => 'One of the registered availability statuses — see the schema field of the same name.'],
                'sku' => 'string',
                'external_id' => ['type' => 'string|null', 'description' => 'What your own system calls this row.'],
                'price' => ['type' => 'number', 'description' => 'MAJOR units here — what a human reads on an invoice. Stored exactly as the admin form would store it: the typed number kept, the shop’s own price derived from it.', 'example' => 420.5],
                'cost_price' => 'number',
                'list_price' => 'number',
                'price_currency' => ['type' => 'string|null', 'description' => 'What the prices above are typed in. Omit for the shop’s own currency.'],
                'categories' => ['type' => 'array', 'description' => 'The product’s categories, ALL of them: the list replaces what is there. A number is a category id, a string its `external_id`. One the shop does not have refuses the whole write — a product is never quietly left in fewer categories than you sent.', 'example' => [12, '1c-0042']],
                'variation_group' => ['type' => 'string|null', 'description' => 'The group word the product’s variations share; null takes it out. Refused when the product would answer every variation characteristic exactly as a product already in the group — nothing would tell the two apart.'],
                'seo' => ['type' => 'object', 'description' => 'Any of `meta_title`, `meta_description`, `h1` (each an object of locale → text, merged into the languages already there), `noindex`, `canonical_url`. Send an empty string for a locale to clear that language.'],
            ],
            'schema' => $this->productSchema(),
        ]);
    }

    protected function categories(): void
    {
        ApiResources::register('admin.categories', [
            'label' => 'Categories',
            'description' => 'The catalogue tree, with each row’s link to whatever your own system calls it.',
            'permission' => 'catalog.manage',
            'query' => fn () => Category::query(),
            'sortable' => ['id', 'slug', 'sort'],
            'lookup' => ['external_id', 'slug'],
            'filters' => [
                'parent_id' => ['apply' => fn ($q, $v) => $q->where('parent_id', $v ?: null)],
                'status' => [
                    'description' => 'active, hidden or disabled.',
                    'example' => 'active',
                    'apply' => fn ($q, $v) => $q->where('status', $v),
                ],
                'updated_after' => [
                    'description' => 'Everything touched since a moment (ISO 8601).',
                    'apply' => fn ($q, $v) => $q->where('updated_at', '>=', $v),
                ],
            ],
            'schema' => [
                'id' => 'integer',
                'url' => 'string',
                'external_id' => 'string|null',
                'slug' => 'string',
                'name' => ['type' => 'object', 'description' => 'Translated: one key per locale.'],
                'parent_id' => 'integer|null',
                'sort' => 'integer',
                'status' => ['type' => 'string', 'enum' => Category::STATUSES, 'description' => 'active: listed and reachable; hidden: out of the menus and the sitemap, its address still opens; disabled: no page.'],
                'updated_at' => ['type' => 'string|null', 'format' => 'date-time'],
            ],
            'serialize' => fn (Category $c) => [
                'id' => $c->id,
                'url' => url("/api/v2/admin/categories/{$c->id}"),
                'external_id' => $c->external_id,
                'slug' => $c->slug,
                'name' => $c->name,
                'parent_id' => $c->parent_id,
                'sort' => $c->sort,
                'status' => $c->status,
                'updated_at' => $c->updated_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * What the serialiser above answers with, said in words.
     *
     * Beside the registration and not in a separate document, because a
     * description kept somewhere else is a description that is wrong within a
     * month — usually about the field somebody added on a Friday. Read by
     * `Torgash\Api\OpenApi`; the runtime ignores it.
     *
     * @return array<string, mixed>
     */
    protected function productSchema(): array
    {
        return [
            'id' => 'integer',
            'url' => 'string',
            'external_id' => ['type' => 'string|null', 'description' => 'What your own system calls this row. Imports match on it BEFORE the sku, because the sku is the shop’s own and the shop renames it.'],
            'sku' => 'string',
            'slug' => 'string',
            'name' => ['type' => 'object', 'description' => 'Translated: one key per locale.'],
            'short_description' => ['type' => 'object|null', 'description' => 'The one line under the name, as STORED — translated, one key per locale, and null on most of a catalogue. What the page actually prints may be composed from the product’s characteristics instead; see the shop resource.'],
            'status' => ['type' => 'string', 'enum' => ['active', 'hidden', 'disabled']],
            'stock' => 'integer',
            'availability_status' => ['type' => 'string', 'description' => 'A second axis from `status`: whether the product can be bought right now and whether `stock` even applies. A registry, not a fixed enum — `in_stock`/`out_of_stock`/`preorder`/`discontinued`/`unlimited` ship in core, a module may add its own.'],
            'currency' => ['type' => 'string', 'description' => 'The shop’s books — the units `price`, `cost_price` and `list_price` are in.'],
            'price' => ['type' => 'integer', 'description' => 'Minor units of `currency`. DERIVED when the price was typed in another currency: it is recomputed on save and on every rate change.'],
            'price_currency' => ['type' => 'string|null', 'description' => 'What the shop actually agreed the price in, when that is not its own currency.'],
            'price_typed' => ['type' => 'number|null', 'description' => 'The number a human typed, in `price_currency`. This is the fixed one; `price` follows it.'],
            'cost_price' => 'integer|null',
            'list_price' => 'integer|null',
            'weight' => ['type' => 'integer|null', 'description' => 'Whole grams, always. Null means not measured, which is a different answer from 0 — the shipping engine acts on it.'],
            'dimensions' => ['type' => 'object', 'description' => 'Whole millimetres, always.', 'of' => [
                'length' => 'integer|null', 'width' => 'integer|null', 'height' => 'integer|null',
            ]],
            'categories' => ['type' => 'array', 'of' => [
                'id' => 'integer', 'external_id' => 'string|null', 'url' => 'string',
            ]],
            'variation_group' => ['type' => 'string|null', 'description' => 'The word shared by the products that are variations of one another; null when this one has none.'],
            'characteristics' => ['type' => 'array', 'description' => 'The shop’s characteristics of this product — what filters, variations, the brand and delivery conditions read — every one it has a value for, shown on the page or not. One of two stores; the other is `features`, and nothing copies one into the other.', 'of' => [
                'code' => ['type' => 'string', 'description' => 'The characteristic’s code — what an exchange matches on.'],
                'name' => ['type' => 'object', 'description' => 'locale → name.'],
                'purpose' => ['type' => 'string', 'description' => 'filter (an ordinary one), variation (tells a group’s products apart) or brand.'],
                'value' => ['type' => 'object', 'description' => 'locale → value. A typed value’s base-language word is the one filters and conditions read.'],
                'value_code' => ['type' => 'string|null', 'description' => 'For a value picked from the characteristic’s list; null for one typed for this product.'],
            ]],
            'features' => ['type' => 'array', 'description' => 'The product’s OWN rows, typed for this product alone and never filtered by, as a flat ordered list — see the shop resource.'],
            'seo' => ['type' => 'object', 'description' => 'What the product says about its own page. Every text field is translated (one key per locale) and an empty one means "nobody has said" — the shop then works the answer out from the name, the description and the price.', 'of' => [
                'meta_title' => 'object|null',
                'meta_description' => 'object|null',
                'h1' => 'object|null',
                'noindex' => ['type' => 'boolean', 'description' => 'Asks search engines not to list the page. It stays open to anyone with the link.'],
                'canonical_url' => 'string|null',
            ]],
            'updated_at' => ['type' => 'string|null', 'format' => 'date-time'],
        ];
    }

    /**
     * The shop's characteristics of a product — the second store beside the
     * own rows in `features` (Features\OwnRows). An exchange that read only
     * `features` saw no colour, no brand and nothing a filter is made of, and
     * concluded the own table was where characteristics live.
     *
     * A visitor gets what the page's specifications show, in the request's
     * language (`.md` and JSON-LD follow the same tick, ProductFacts::specs());
     * the admin half gets every one, every language, and the purpose.
     *
     * @return list<array<string, mixed>>
     */
    protected function characteristics(Product $product, bool $forVisitor): array
    {
        return $product->featureAssignments
            ->filter(fn (ProductFeature $pf) => $pf->feature && $pf->display() !== null && (! $forVisitor || $pf->feature->shows('specs')))
            ->sortBy(fn (ProductFeature $pf) => [$pf->feature->sort, $pf->feature->id])
            ->map(fn (ProductFeature $pf) => $forVisitor
                ? [
                    'code' => $pf->feature->code,
                    'name' => (string) $pf->feature->t('name'),
                    'value' => (string) $pf->display(),
                    'value_code' => $pf->featureValue?->code,
                ]
                : [
                    'code' => $pf->feature->code,
                    'name' => (array) $pf->feature->name,
                    'purpose' => $pf->feature->purpose,
                    // A typed value is the base word plus its words in other languages.
                    'value' => $pf->featureValue
                        ? (array) $pf->featureValue->value
                        : array_filter([content_base_locale() => $pf->value_text] + (array) ($pf->value_translations ?? []), fn ($word) => $word !== null && $word !== ''),
                    'value_code' => $pf->featureValue?->code,
                ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    protected function product(Product $product): array
    {
        return hook_filter('api.product.serialize', [
            'id' => $product->id,
            'url' => url("/api/v2/admin/products/{$product->id}"),
            'external_id' => $product->external_id,
            'sku' => $product->sku,
            'slug' => $product->slug,
            'name' => $product->name,
            'short_description' => $product->short_description,
            'status' => $product->status,
            'stock' => $product->stock,
            'availability_status' => $product->availability_status,
            // Minor units, and the currency they are in: a price is two facts,
            // and sending the number alone is how an exchange puts hryvnia into
            // a dollar column. `price` is the shop's books; `price_typed` is
            // what the shop actually agreed, in `price_currency`, where those
            // differ (§52).
            'currency' => shop_currency(),
            'price' => $product->price,
            'price_currency' => $product->price_currency,
            'price_typed' => $product->price_original,
            'cost_price' => $product->cost_price,
            'list_price' => $product->list_price,
            'weight' => $product->weight,
            'dimensions' => ['length' => $product->length, 'width' => $product->width, 'height' => $product->height],
            'categories' => $product->categories->map(fn (Category $c) => [
                'id' => $c->id,
                'external_id' => $c->external_id,
                'url' => url("/api/v2/admin/categories/{$c->id}"),
            ])->values(),
            'variation_group' => $product->variation_group,
            'characteristics' => $this->characteristics($product, forVisitor: false),
            'features' => $product->featureRows(),
            'seo' => $this->seo($product),
            'updated_at' => $product->updated_at?->toIso8601String(),
        ], ['product' => $product]);
    }

    /**
     * A partial write, and only of what an exchange legitimately owns.
     *
     * Stock, prices, status and the link to its own id — not the slug, not the
     * categories, not the media. An import that can rewrite everything is one
     * bad mapping away from flattening the catalogue, and the shop's own admin
     * is where the rest belongs.
     *
     * @param  array<string, mixed>  $data
     */
    protected function updateProduct(Product $product, array $data): void
    {
        $allowed = ['stock', 'status', 'availability_status', 'external_id', 'sku'];

        // Its own page's metadata, merged rather than replaced — the same rule
        // the admin form follows, because a client sending only Russian must
        // not silently drop the English the shop wrote.
        if (is_array($data['seo'] ?? null)) {
            $this->writeSeo($product, $data['seo']);
        }

        // A key nobody registers is refused rather than written verbatim — a
        // stray value here would otherwise sit unreadable by isOrderable()
        // (fail-closed) until somebody notices in the admin.
        if (isset($data['availability_status'])
            && ! app(AvailabilityStatuses::class)->find((string) $data['availability_status'])) {
            unset($data['availability_status']);
        }

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $product->{$field} = $data[$field] === '' ? null : $data[$field];
            }
        }

        // Prices go through the one class that owns the arithmetic, so a price
        // sent in the product's own currency is stored the way the admin form
        // would have stored it — books amount derived, typed amount kept.
        $prices = array_intersect_key($data, array_flip(['price', 'cost_price', 'list_price']));

        if ($prices !== []) {
            // Major units on the wire: an exchange writes what a human would
            // read on an invoice, not hundredths.
            $product->fill(app(Prices::class)->columns(
                $prices,
                $data['price_currency'] ?? $product->price_currency,
            ));
        }

        // Categories are resolved BEFORE anything is written: an unknown one
        // refuses the call whole, rather than leaving the product in the
        // categories that happened to be found.
        $categoryIds = array_key_exists('categories', $data) ? $this->categoryIds($data['categories']) : null;

        if (array_key_exists('variation_group', $data)) {
            $product->variation_group = trim((string) $data['variation_group']) ?: null;
        }

        DB::transaction(function () use ($product, $categoryIds) {
            $groupChanged = $product->isDirty('variation_group');
            $product->save();

            if ($categoryIds !== null) {
                $changes = $product->categories()->sync($categoryIds);

                // What the exchange reads back with `updated_after`.
                if (array_filter($changes)) {
                    $product->touch();
                }
            }

            if ($groupChanged && $product->variation_group) {
                $this->refuseDuplicateVariation($product);
            }
        });
    }

    /**
     * Category ids from what the client sent: a number is an id, a string an
     * `external_id` (1C's keys are strings even when they look like numbers).
     *
     * @return list<int>
     */
    protected function categoryIds(mixed $sent): array
    {
        if (! is_array($sent) || ! array_is_list($sent)) {
            throw ValidationException::withMessages(['categories' => __('Send the categories as a list.')]);
        }

        $ids = [];
        $unknown = [];

        foreach ($sent as $ref) {
            $id = match (true) {
                is_int($ref) => Category::whereKey($ref)->value('id'),
                is_string($ref) && trim($ref) !== '' => Category::where('external_id', trim($ref))->value('id'),
                default => null,
            };

            if ($id === null) {
                $unknown[] = is_scalar($ref) ? (string) $ref : json_encode($ref);
            } else {
                $ids[] = (int) $id;
            }
        }

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'categories' => __('The shop has no such category: :keys.', ['keys' => implode(', ', $unknown)]),
            ]);
        }

        return array_values(array_unique($ids));
    }

    /**
     * A product that would answer every variation characteristic exactly as
     * another member does cannot join the group: the admin shows such a pair
     * in yellow for a person to fix, but an exchange has nobody to look.
     * Thrown inside the transaction, so the whole write goes back.
     */
    protected function refuseDuplicateVariation(Product $product): void
    {
        $duplicates = app(Variations::class)->for($product, true)['duplicates'];

        foreach ($duplicates as $duplicate => $original) {
            if ($duplicate === $product->id || $original === $product->id) {
                $other = Product::find($duplicate === $product->id ? $original : $duplicate);

                throw ValidationException::withMessages([
                    'variation_group' => __('Product :sku already answers every variation characteristic of this group the same way — nothing would tell the two apart.', ['sku' => $other?->sku ?: '#'.$other?->id]),
                ]);
            }
        }
    }

    /**
     * The SEO half of a serialised row.
     *
     * Whole objects rather than the current locale's string: this is the admin
     * half of the API, where a client is an exchange editing the catalogue, and
     * it has to be able to read back exactly what it can write.
     *
     * @return array<string, mixed>
     */
    protected function seo(Model $model): array
    {
        return [
            'meta_title' => $model->meta_title,
            'meta_description' => $model->meta_description,
            'h1' => $model->h1,
            'noindex' => (bool) $model->noindex,
            'canonical_url' => $model->canonical_url,
        ];
    }

    /**
     * ...and writing it back. Per locale, merged, with an empty string meaning
     * "clear this language" — `null` and `""` have to be the same answer, or a
     * cleared description would print an empty tag for ever.
     *
     * @param  array<string, mixed>  $seo
     */
    protected function writeSeo(Model $model, array $seo): void
    {
        foreach (['meta_title', 'meta_description', 'h1'] as $column) {
            if (! array_key_exists($column, $seo)) {
                continue;
            }

            $values = array_filter(array_map(
                fn ($value) => trim((string) $value),
                [...(array) ($model->{$column} ?? []), ...(array) $seo[$column]],
            ));

            $model->{$column} = $values === [] ? null : $values;
        }

        if (array_key_exists('noindex', $seo)) {
            $model->noindex = filter_var($seo['noindex'], FILTER_VALIDATE_BOOLEAN);
        }

        if (array_key_exists('canonical_url', $seo)) {
            $model->canonical_url = trim((string) $seo['canonical_url']) ?: null;
        }
    }
}
