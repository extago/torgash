<?php

namespace Torgash\Catalog\Selections;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Torgash\Blocks\BlockTypes;
use Torgash\Catalog\Filters\Flags;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\FilterPage;
use Torgash\Catalog\Models\Label;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Sorting\Sorts;
use Torgash\Catalog\Support\CategoryTree;
use Torgash\Catalog\Support\Labels;
use Torgash\Catalog\Support\RecentlyViewed;

/**
 * "Some products, here" as a content block — the thing the home page's tabs
 * were written by hand for (PLAN §31.4).
 *
 * The tabs themselves are NOT this block's business: several product blocks
 * standing side by side with the same `tabs` word are drawn as tabs by the
 * blocks module, so "Hits / New / On sale" is three blocks a shop can reorder,
 * switch off one at a time and give their own display rules.
 */
class ProductBlock
{
    /** The catalogue's own sources — see Sources for the shape. */
    public static function sources(array $sources): array
    {
        return $sources + [
            'all' => [
                'label' => 'The whole catalogue',
                'apply' => fn (Builder $query) => $query,
                'url' => fn () => route('catalog.index'),
            ],

            // Everything under it too — a category means itself and its
            // subcategories everywhere in this engine, with no switch.
            'category' => [
                'label' => 'Category',
                'choices' => fn () => app(CategoryTree::class)->options(),
                'apply' => function (Builder $query, string $arg) {
                    $category = app(CategoryTree::class)->find((int) $arg);

                    if (! $category || ! $category->isReachable()) {
                        return $query->whereRaw('1 = 0');
                    }

                    return $query->whereHas('categories', fn (Builder $q) => $q->whereIn(
                        'm_catalog_categories.id',
                        app(CategoryTree::class)->descendants($category->id),
                    ));
                },
                'heading' => fn (string $arg) => app(CategoryTree::class)->find((int) $arg)?->t('name'),
                'url' => function (string $arg) {
                    $category = app(CategoryTree::class)->find((int) $arg);

                    return $category?->isReachable() ? route('catalog.category', $category->slug) : null;
                },
            ],

            // What the label is on: the products it was put on by hand, plus the
            // ones its rules match. A rule that only exists on a loaded product
            // ("discount", see Label::rulesAreSql()) cannot narrow a query, so
            // such a label contributes its hand-picked products alone — the
            // yes/no filter "On sale" is the source for "everything discounted".
            'label' => [
                // "Product label", not "Label": one English word has one
                // translation, and a bare "Label" is also a caption.
                'label' => 'Product label',
                'choices' => fn () => Label::orderBy('sort')->pluck('name_for_admin', 'id')->all(),
                'apply' => function (Builder $query, string $arg) {
                    $label = app(Labels::class)->active()->firstWhere('id', (int) $arg);

                    if (! $label) {
                        return $query->whereRaw('1 = 0');
                    }

                    return $query->where(function (Builder $either) use ($label) {
                        $either->whereIn('m_catalog_products.id', DB::table('m_catalog_label_product')
                            ->where('label_id', $label->id)->select('product_id'));

                        if ($label->hasRules() && $label->rulesAreSql()) {
                            $either->orWhere(fn (Builder $rules) => $label->applyConditions($rules));
                        }
                    });
                },
                'heading' => fn (string $arg) => app(Labels::class)->active()->firstWhere('id', (int) $arg)?->sectionTitle(),
            ],

            // Every REGISTERED flag, offered in the panel or not: a block is the
            // shop's own choice, not a control a visitor could be stranded by.
            // Through applyKeys(), never a copy of a flag's SQL.
            'flag' => [
                'label' => 'Filter',
                'choices' => fn () => app(Flags::class)->options(),
                'apply' => fn (Builder $query, string $arg) => app(Flags::class)->applyKeys($query, [$arg]),
                'heading' => fn (string $arg) => app(Flags::class)->find($arg) ? app(Flags::class)->label($arg) : null,
                // A link to the catalogue with the box ticked — only while the
                // panel offers that box, or the page it leads to ignores it.
                'url' => fn (string $arg) => in_array($arg, app(Flags::class)->offered(), true)
                    ? route('catalog.index', [$arg => 1])
                    : null,
            ],

            // A category AND a filter at once — which is exactly what a filter
            // page is (its values, its yes/no filters, its price range), so the
            // combination is written once, where it is already written, rather
            // than growing a second editor here. A page switched OFF (not
            // published) still works as a set: that is how a shop keeps a
            // selection nobody can open at an address of its own.
            'filter_page' => [
                'label' => 'Filter page',
                'choices' => fn () => FilterPage::with('category')->orderBy('category_id')->orderBy('position')->orderBy('id')->get()
                    ->mapWithKeys(fn (FilterPage $page) => [
                        $page->id => trim(($page->category?->t('name') ?? '—').' → '.$page->title())
                            .($page->enabled ? '' : ' ('.__('not published').')'),
                    ])->all(),
                'apply' => function (Builder $query, string $arg) {
                    $page = FilterPage::with('category')->find((int) $arg);

                    // Its category gone (or unreachable): the set is empty, not
                    // "the page's filter over the whole catalogue".
                    if (! $page || ! $page->category?->isReachable()) {
                        return $query->whereRaw('1 = 0');
                    }

                    return $page->applyTo($query->whereHas('categories', fn (Builder $q) => $q->whereIn(
                        'm_catalog_categories.id',
                        app(CategoryTree::class)->descendants((int) $page->category_id),
                    )));
                },
                'heading' => fn (string $arg) => FilterPage::find((int) $arg)?->title(),
                'url' => function (string $arg) {
                    $page = FilterPage::with('category')->find((int) $arg);

                    return $page && $page->enabled && $page->category?->isReachable() ? $page->url() : null;
                },
            ],

            // The product on the page's neighbours: what shares a category
            // with it — its own categories, not their branches, or a phone in
            // "Electronics" would call a kettle similar. Its variations are
            // not "similar", they are the picker above. Only on a page that
            // has a product; anywhere else, nothing.
            'similar' => [
                'label' => 'Similar to the product on the page',
                'apply' => function (Builder $query, string $arg, array $settings, array $context = []) {
                    $product = $context['product'] ?? null;
                    $categories = $product instanceof Product
                        ? DB::table('m_catalog_category_product')->where('product_id', $product->id)->pluck('category_id')->all()
                        : [];

                    if ($categories === []) {
                        return $query->whereRaw('1 = 0');
                    }

                    return $query
                        ->where('m_catalog_products.id', '!=', $product->id)
                        ->whereHas('categories', fn (Builder $q) => $q->whereIn('m_catalog_categories.id', $categories))
                        ->when($product->variation_group, fn (Builder $q, string $group) => $q->where(
                            fn (Builder $other) => $other->whereNull('variation_group')->orWhere('variation_group', '!=', $group),
                        ));
                },
            ],

            // What this visitor looked at, newest first (RecentlyViewed: the
            // session, switched off by a setting) — minus the page's own
            // product, which they are looking at now.
            'viewed' => [
                'label' => 'Viewed recently by the visitor',
                'apply' => function (Builder $query, string $arg, array $settings, array $context = []) {
                    $ids = self::viewedIds($context);

                    return $ids === [] ? $query->whereRaw('1 = 0') : $query->whereIn('m_catalog_products.id', $ids);
                },
                'order' => fn (Builder $query, string $arg, array $settings, array $context = []) => $query->orderByRaw(
                    'array_position(?::bigint[], m_catalog_products.id)',
                    ['{'.implode(',', self::viewedIds($context)).'}'],
                ),
            ],

            'manual' => [
                'label' => 'Picked by hand',
                'apply' => function (Builder $query, string $arg, array $settings) {
                    $skus = Selection::skus($settings);

                    return $skus === [] ? $query->whereRaw('1 = 0') : $query->whereIn('sku', $skus);
                },
                // In the order they were typed, in SQL — so the limit keeps the
                // FIRST ones typed rather than whichever the database found first.
                'order' => fn (Builder $query, string $arg, array $settings) => $query->orderByRaw(
                    'array_position(?::text[], sku::text)',
                    [self::textArray(Selection::skus($settings))],
                ),
            ],
        ];
    }

    public static function register(): void
    {
        if (! class_exists(BlockTypes::class)) {
            return;
        }

        // The catalogue as a menu (PLAN §38, the phone menu): the top
        // categories with their icons, a chevron into the ones below — the
        // departments list every reference shop opens its menu with. Beside
        // `products` because it is the same kind of thing: catalogue data
        // drawn where a shop places a block.
        BlockTypes::register('categories', [
            'description' => 'The tree of the catalogue as a menu, from its root or from one category.',
            'fits' => ['wide', 'narrow', 'drawer'],
            'label' => 'Categories menu',
            'icon' => 'folder-tree',
            'view' => 'torgash.catalog::blocks.categories',
            'schema' => [
                ['key' => 'heading', 'type' => 'text', 'translatable' => true, 'label' => 'Heading',
                    'help' => 'Optional.'],
            ],
        ]);

        // Categories as tiles — a picture and a name — the shop picks and
        // orders, as a grid or a row that scrolls (the home page's "Shop by
        // category", which used to be printed by the home template itself).
        BlockTypes::register('category_tiles', [
            'description' => 'Categories as tiles with a picture: a grid, or a row that scrolls with a thin indicator under it.',
            'fits' => ['wide'],
            'label' => 'Category tiles',
            'icon' => 'layout-grid',
            'view' => 'torgash.catalog::blocks.category-tiles',
            'schema' => [
                ['key' => 'heading', 'type' => 'text', 'translatable' => true, 'label' => 'Heading',
                    'help' => 'Optional.'],
                ['key' => 'categories', 'type' => 'picks', 'label' => 'Which categories',
                    'empty' => 'None picked — the top categories, in the catalogue\'s order.',
                    'help' => 'In the order picked. A category that is switched off or closed to the visitor is skipped.',
                    'options' => fn () => collect(app(\Torgash\Catalog\Support\CategoryTree::class)->options())
                        ->map(fn ($label, $id) => ['value' => (int) $id, 'label' => $label])->values()->all()],
                ['key' => 'layout', 'type' => 'select', 'default' => 'strip', 'label' => 'Layout', 'options' => [
                    ['value' => 'grid', 'label' => 'Grid'],
                    ['value' => 'strip', 'label' => 'One row that scrolls'],
                    ['value' => 'strip2', 'label' => 'Two rows that scroll'],
                ]],
                ['key' => 'look', 'type' => 'select', 'default' => 'under', 'label' => 'Tile', 'options' => [
                    ['value' => 'under', 'label' => 'The picture on a card, the name under it'],
                    ['value' => 'inside', 'label' => 'The picture and the name on one card'],
                    ['value' => 'corner', 'label' => 'The name on a card, a large picture in its corner'],
                ]],
                ['key' => 'shape', 'type' => 'select', 'default' => 'card', 'label' => 'Picture frame',
                    'help' => 'For "The picture on a card, the name under it".', 'options' => [
                    ['value' => 'card', 'label' => 'A card as wide as the tile'],
                    ['value' => 'circle', 'label' => 'A circle'],
                    ['value' => 'rounded', 'label' => 'A square with rounded corners'],
                ]],
                ['key' => 'colors', 'type' => 'select', 'default' => 'plain', 'label' => 'Tile colours', 'options' => [
                    ['value' => 'plain', 'label' => "Plain, in the theme's colours"],
                    ['value' => 'tinted', 'label' => 'Each tile its own soft colour'],
                ]],
                ['key' => 'picture', 'type' => 'select', 'default' => 'auto', 'label' => 'Picture', 'options' => [
                    ['value' => 'auto', 'label' => 'Tile picture, else the icon'],
                    ['value' => 'tile', 'label' => 'Tile picture only'],
                    ['value' => 'icon', 'label' => 'Icon only'],
                    ['value' => 'none', 'label' => 'No picture'],
                ]],
                ['key' => 'photos', 'type' => 'checkbox', 'default' => false, 'label' => 'A photo from its products',
                    'help' => 'A category with no tile picture shows the photo of its most popular product in stock.'],
                ['key' => 'counts', 'type' => 'checkbox', 'default' => false, 'label' => 'How many products',
                    'help' => 'Under each name, counting everything inside the category.'],
                ['key' => 'picture_size', 'type' => 'number', 'default' => 72, 'min' => 24, 'max' => 240, 'suffix' => 'px',
                    'label' => 'Picture size'],
                ['key' => 'padding', 'type' => 'text', 'label' => 'Padding inside a tile', 'short' => true, 'placeholder' => '12px 16px',
                    'help' => 'In px, as in CSS: one to four numbers — top, right, bottom, left (12, 12 16, 30px 40px 30px 40px). Empty: what the look has by default.'],
                ['key' => 'gap_size', 'type' => 'number', 'default' => 16, 'min' => 0, 'max' => 80, 'suffix' => 'px',
                    'label' => 'Space between tiles'],
                ['key' => 'text_size', 'type' => 'number', 'default' => 14, 'min' => 10, 'max' => 32, 'suffix' => 'px',
                    'label' => 'Text size'],
                ['key' => 'indicator', 'type' => 'checkbox', 'default' => true, 'label' => 'Scroll indicator',
                    'help' => 'A thin line under a row that scrolls, showing where in it the visitor is'],
                ['key' => 'arrows', 'type' => 'checkbox', 'default' => false, 'label' => 'Arrows',
                    'help' => 'Arrows at the ends of a row that scrolls, for a mouse'],
            ],
        ]);

        BlockTypes::register('products', [
            'description' => 'A row or a grid of products from a source: new, on sale, a category, related to this product…',
            'fits' => ['wide', 'narrow'],
            'label' => 'Products',
            'icon' => 'layout-grid',
            'view' => 'torgash.catalog::blocks.products',
            'heading' => fn (array $settings) => app(Selection::class)->heading($settings),
            'schema' => [
                [
                    'key' => 'heading',
                    'type' => 'text',
                    'translatable' => true,
                    'label' => 'Heading',
                    'help' => 'Empty — what the products come from is named instead: the label\'s section title, the category, the promotion. In a row of tabs this is the tab.',
                ],
                [
                    'key' => 'source',
                    'type' => 'select',
                    'default' => 'all',
                    'label' => 'Which products',
                    // A closure: the list is the shop's own categories, labels
                    // and promotions, and registration runs on every request.
                    'options' => fn () => app(Sources::class)->options(),
                ],
                [
                    'key' => 'products',
                    'type' => 'textarea',
                    'label' => 'Products picked by hand',
                    'help' => 'SKUs, one per line, in the order they should appear. Read only when "Which products" is "Picked by hand".',
                ],
                [
                    'key' => 'sort',
                    'type' => 'select',
                    'default' => '',
                    'label' => 'Sort order',
                    'options' => fn () => [
                        ['value' => '', 'label' => 'Its own (as typed, or the shop\'s default sort)'],
                        ...array_map(
                            fn ($key, $label) => ['value' => (string) $key, 'label' => $label],
                            array_keys(app(Sorts::class)->options()),
                            app(Sorts::class)->options(),
                        ),
                    ],
                ],
                [
                    'key' => 'limit',
                    'type' => 'number',
                    'default' => 8,
                    'min' => 1,
                    'max' => Selection::MAX_LIMIT,
                    'label' => 'How many products',
                ],
                [
                    'key' => 'layout',
                    'type' => 'select',
                    'default' => 'grid',
                    'label' => 'Layout',
                    'options' => [
                        ['value' => 'grid', 'label' => 'Grid'],
                        ['value' => 'carousel', 'label' => 'Carousel (one row, scrolls sideways)'],
                    ],
                ],
                [
                    'key' => 'per_row',
                    'type' => 'select',
                    'default' => '4',
                    'label' => 'In a row on a computer',
                    'help' => 'Grid and carousel alike. A phone shows two and a tablet three whatever this says — a card narrower than that stops being readable.',
                    'options' => [
                        ['value' => '3', 'label' => '3'],
                        ['value' => '4', 'label' => '4'],
                        ['value' => '5', 'label' => '5'],
                        ['value' => '6', 'label' => '6'],
                    ],
                ],
                ...self::arrowSettings(),
                [
                    'key' => 'reveal',
                    'type' => 'number',
                    'default' => 0,
                    'min' => 0,
                    'max' => Selection::MAX_LIMIT,
                    'label' => 'Show at first (grid)',
                    'help' => '0 — all of them. Otherwise this many, and a "Show more" button reveals the next as many.',
                ],
                [
                    'key' => 'link_all',
                    'type' => 'checkbox',
                    'default' => true,
                    'label' => 'Link to all of them',
                    'help' => 'When what is shown has a page of its own — a category, a promotion, the catalogue.',
                ],
            ],
        ]);
    }

    /**
     * The carousel's arrows, one choice for a computer/tablet and one for a
     * phone (partials/product-carousel). Public: every block that draws that
     * carousel offers the same two questions in the same words.
     *
     * @return list<array<string, mixed>>
     */
    public static function arrowSettings(): array
    {
        $kinds = [
            ['value' => 'side', 'label' => 'Round, at the sides over the cards'],
            ['value' => 'head', 'label' => 'Small, above the row on the right'],
            ['value' => 'none', 'label' => 'None — swiped or scrolled'],
        ];

        return [
            ['key' => 'arrows', 'type' => 'select', 'default' => 'side', 'label' => 'Carousel arrows on a computer and a tablet', 'options' => $kinds],
            ['key' => 'arrows_phone', 'type' => 'select', 'default' => 'head', 'label' => 'Carousel arrows on a phone', 'options' => $kinds,
                'help' => "Two cards fill a phone's width, so arrows at the sides lie on them."],
        ];
    }

    /** @return list<int> the visitor's recently viewed products, newest first, without the page's own. */
    protected static function viewedIds(array $context): array
    {
        $current = ($context['product'] ?? null) instanceof Product ? $context['product']->id : null;

        return array_values(array_filter(
            app(RecentlyViewed::class)->ids(),
            fn (int $id) => $id !== $current,
        ));
    }

    /**
     * A Postgres text[] literal. Every element double-quoted with its own
     * quotes and backslashes escaped, so a SKU holding a comma or a brace is
     * still one element.
     *
     * @param  list<string>  $values
     */
    protected static function textArray(array $values): string
    {
        return '{'.implode(',', array_map(
            fn (string $value) => '"'.addcslashes($value, '"\\').'"',
            $values,
        )).'}';
    }
}
