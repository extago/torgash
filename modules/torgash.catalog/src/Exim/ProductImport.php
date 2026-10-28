<?php

namespace Torgash\Catalog\Exim;

use Illuminate\Support\Str;
use Torgash\Catalog\Features\Spelling;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductFeature;
use Torgash\Catalog\Pricing\Prices;
use Torgash\Catalog\Support\CategoryTree;

/**
 * The product columns another system's file has and our own export does not
 * (PLAN §58): a category as a PATH, a brand by name, photos as links, the
 * characteristics — a typed cell `Name: S[value]; …`, a JSON table of sections,
 * or one column per characteristic — and prices written as a person writes
 * them (12.50, not 1250).
 *
 * Each is WRITTEN the way it is read, so a file exported with these columns
 * can be changed in a spreadsheet and imported back: prices as a person writes
 * them, every category as a path, the brand and the characteristics by name,
 * the photos as links (a link to one of the product's own photos is
 * recognised and never downloaded again). They are `on_request`: a profile
 * that ticks nothing exports what it always did, not a dozen new columns.
 * The two "named by the column" fields stay import-only — their column's
 * name IS the data. Whatever a field needs to look up more than once ("the
 * category at A///B") is remembered for the run in the import's batch.
 *
 * Every name this creates — a category, a characteristic, a value — is
 * written in the shop's CONTENT base language: the file names things in one
 * language, and that is the one the shop's catalogue is written in.
 */
class ProductImport
{
    /** The typed cell's characteristic types: one letter in front of `[value]`. */
    protected const CELL_TYPES = [
        'S' => 'select', 'N' => 'select', 'E' => 'select', 'M' => 'select',
        'T' => 'text', 'O' => 'number', 'C' => 'select', 'D' => 'text',
    ];

    /** @return list<array> exim field declarations */
    public function fields(): array
    {
        return [
            // How a person writes a price; `base_price` stays the minor-units
            // column our own export round-trips.
            [
                'key' => 'price_units', 'group' => 'prices', 'on_request' => true,
                'label' => 'Price (as written: 12.50)',
                'aliases' => ['Price', 'Цена'],
                // In the product's own pricing currency, as the import reads it back.
                'get' => fn (Product $p) => self::written($p->typedPrice('price')),
                'set' => fn (Product $p, $v) => $this->writePrice($p, 'price', $v, false),
            ],
            [
                'key' => 'list_price_units', 'group' => 'prices', 'on_request' => true,
                'label' => 'List price (as written: 12.50)',
                'aliases' => ['List price', 'Старая цена', 'oldprice'],
                'get' => fn (Product $p) => self::written($p->typedPrice('list_price')),
                'set' => fn (Product $p, $v) => $this->writePrice($p, 'list_price', $v, true),
            ],
            [
                'key' => 'weight_units', 'group' => 'stock', 'on_request' => true,
                'label' => 'Weight (in the shop\'s unit)',
                'aliases' => ['Weight', 'Вес'],
                'get' => fn (Product $p) => $p->weight === null ? '' : units()->weightToForm($p->weight),
                'set' => function (Product $p, $v) {
                    $p->weight = trim((string) $v) === '' ? null : units()->weightToStorage(str_replace(',', '.', trim((string) $v)));
                },
            ],
            [
                'key' => 'category', 'group' => 'main', 'on_request' => true,
                'label' => 'Category (path)',
                'help' => 'Levels separated as set below ("Phones///Smartphones"); a category that does not exist is created. Replaces the product\'s categories.',
                'aliases' => ['Category', 'Категория', 'Categories'],
                // Every category of the product, as the import reads them: paths separated by ";".
                'with' => ['categories'],
                'get' => fn (Product $p) => $p->categories->sortBy('id')->map(fn (Category $c) => self::pathOf($c))->filter()->implode('; '),
                'set' => null,
                'after' => fn (Product $p, $v, array $ctx) => $this->writeCategories($p, $v, $ctx, false),
            ],
            [
                'key' => 'secondary_categories', 'group' => 'main', 'import_only' => true,
                'label' => 'Secondary categories (paths)',
                'help' => 'Several paths separated by ";". Added to the category above.',
                'aliases' => ['Secondary categories', 'Дополнительные категории'],
                'set' => null,
                'after' => fn (Product $p, $v, array $ctx) => $this->writeCategories($p, $v, $ctx, true),
            ],
            [
                'key' => 'brand', 'group' => 'features', 'on_request' => true,
                'label' => 'Brand',
                'aliases' => ['Brand', 'Бренд', 'Manufacturer', 'Производитель', 'vendor'],
                'with' => ['featureAssignments.feature', 'featureAssignments.featureValue'],
                'get' => fn (Product $p) => (string) $p->brandValue()?->display(content_base_locale()),
                'set' => null,
                'after' => fn (Product $p, $v, array $ctx) => $this->assign($p, $this->brandFeature($ctx), $v, $ctx),
            ],
            [
                'key' => 'characteristics', 'group' => 'features', 'on_request' => true,
                'label' => 'Characteristics (Name: S[value] or JSON)',
                // Written as a typed cell, which the import reads back.
                'with' => ['featureAssignments.feature', 'featureAssignments.featureValue'],
                'get' => fn (Product $p) => self::featuresCell($p),
                'help' => '"Colour: S[Black]; Memory: S[128 GB]" sets the shop\'s characteristics (created if missing); a JSON list of sections and rows becomes the product\'s specification table.',
                'aliases' => ['Features', 'Характеристики'],
                // A file exported before the label changed is still recognised.
                'alias_patterns' => ['/^(Characteristics|Характеристики) \(.+ (or|или|або) JSON\)$/u'],
                'set' => function (Product $p, $v) {
                    // The JSON table belongs on the product row itself.
                    if (($table = $this->specTable((string) $v)) !== null) {
                        [$p->features, $p->features_layout] = $table;
                    }
                },
                'after' => fn (Product $p, $v, array $ctx) => $this->specTable((string) $v) === null ? $this->writeFeaturesCell($p, (string) $v, $ctx) : null,
            ],
            [
                'key' => 'feature_column', 'group' => 'features', 'import_only' => true, 'per_column' => true,
                'label' => 'Characteristic named by the column',
                'help' => 'The column\'s name is the characteristic ("Colour", or "[Colour]" in brackets); found by name or created.',
                // A YML feed's <param name="Colour">, as the XML import names its column.
                'alias_patterns' => ['/^param \[.+\]$/u'],
                'set' => null,
                'after' => fn (Product $p, $v, array $ctx) => $this->assign($p, $this->namedFeature(self::columnName($ctx['column']), 'filter', 'select', $ctx), $v, $ctx),
            ],
            [
                'key' => 'variation_column', 'group' => 'features', 'import_only' => true, 'per_column' => true,
                'label' => 'Variation axis named by the column',
                'help' => 'As above, and the characteristic switches the products of one variation group ("VAR Feature 2 [Colour]").',
                'alias_patterns' => ['/^VAR Feature \d+ \[.+\]$/u'],
                'set' => null,
                'after' => fn (Product $p, $v, array $ctx) => $this->assign($p, $this->namedFeature(self::columnName($ctx['column']), Feature::PURPOSE_VARIATION, 'select', $ctx), $v, $ctx),
            ],
            [
                'key' => 'variation_group', 'group' => 'features',
                'label' => 'Variation group',
                'aliases' => ['Variation group code', 'Variation group', 'Группа вариаций'],
                'get' => fn (Product $p) => $p->variation_group,
                'set' => fn (Product $p, $v) => $p->variation_group = (trim((string) $v) ?: null),
            ],
            [
                'key' => 'images', 'group' => 'media', 'on_request' => true,
                'label' => 'Photos (links)',
                'with' => ['media'],
                'get' => fn (Product $p) => $p->getMedia('gallery')->map(fn ($media) => url($media->getUrl()))->implode(' '),
                'help' => 'Links separated by commas, spaces or "///"; downloaded after the import, as a background task of its own.',
                'aliases' => ['Images', 'Detailed image', 'Image URL', 'Detailed image URL', 'Фото', 'Изображения', 'picture'],
                'set' => null,
                'after' => fn (Product $p, $v, array $ctx) => $this->queueImages($p, (string) $v, $ctx),
            ],
            ...$this->eachFeature(),
        ];
    }

    /**
     * One column per characteristic the shop has, named after it in every
     * language: a file with a "Цвет" column maps itself.
     *
     * @return list<array>
     */
    protected function eachFeature(): array
    {
        return Feature::query()->orderBy('sort')->orderBy('id')->get()->map(fn (Feature $feature) => [
            'key' => 'feature:'.$feature->code,
            'group' => 'features',
            'on_request' => true,
            'with' => ['featureAssignments.feature', 'featureAssignments.featureValue'],
            'get' => fn (Product $p) => (string) $p->featureAssignments->firstWhere('feature_id', $feature->id)?->display(content_base_locale()),
            'label' => 'Characteristic ('.$feature->code.')',
            'base_label' => 'Characteristic',
            'suffix' => (string) (pick_translation((array) $feature->name, app()->getLocale()) ?: $feature->code),
            // Two characteristics may share a name; the id tells them apart.
            'hint' => '#'.$feature->id,
            'aliases' => array_values(array_filter((array) $feature->name, 'is_string')),
            'set' => null,
            'after' => fn (Product $p, $v, array $ctx) => $this->assign($p, Feature::find($feature->id), $v, $ctx),
        ])->all();
    }

    /** A new product's address, and a trashed one's way back, before it is written. */
    public function saving(Product $product): void
    {
        if ($product->exists && method_exists($product, 'trashed') && $product->trashed()) {
            $product->deleted_at = null;
        }

        // A file of names and photos is a catalogue to price later, not an error.
        if (! $product->exists && $product->price === null) {
            foreach (app(Prices::class)->columns(['price' => 0], $product->price_currency) as $key => $amount) {
                $product->{$key} = $amount;
            }
        }

        if (trim((string) $product->slug) === '') {
            $source = pick_translation((array) $product->name, content_base_locale()) ?: (string) $product->sku;
            $product->slug = $this->freeSlug(Product::class, \Torgash\Catalog\Support\Slugs::make($source) ?: 'product');
        }
    }

    /** A new category's address, the same way. */
    public function savingCategory(Category $category): void
    {
        if (trim((string) $category->slug) === '') {
            $source = pick_translation((array) $category->name, content_base_locale()) ?: 'category';
            $category->slug = $this->freeSlug(Category::class, \Torgash\Catalog\Support\Slugs::make($source) ?: 'category');
        }
    }

    /**
     * A description as the storefront prints it: HTML stays as it is, plain
     * text becomes paragraphs (escaped — a supplier's "<3 years" is text).
     */
    public static function asHtml(string $text): string
    {
        $text = trim($text);

        if ($text === '' || preg_match('/<\/?[a-z][a-z0-9]*[\s>\/]/i', $text)) {
            return $text;
        }

        return implode('', array_map(
            fn (string $paragraph) => '<p>'.nl2br(e(trim($paragraph)), false).'</p>',
            preg_split('/\R{2,}/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [$text],
        ));
    }

    protected function writePrice(Product $product, string $column, mixed $value, bool $nullable): void
    {
        $typed = self::number($value);

        if ($typed === null && ! $nullable) {
            return;
        }

        foreach (app(Prices::class)->columns([$column => $typed], $product->price_currency) as $key => $amount) {
            $product->{$key} = $amount;
        }
    }

    /** Minor units as a person writes the amount: 129000 → "1290", 1250 → "12.50". */
    public static function written(?int $minor): string
    {
        if ($minor === null) {
            return '';
        }

        return $minor % 100 === 0 ? (string) intdiv($minor, 100) : number_format($minor / 100, 2, '.', '');
    }

    /** "Phones///Smartphones" — the category's path in the content's base language. */
    public static function pathOf(Category $category): string
    {
        $tree = app(CategoryTree::class);

        // ancestors() walks up from the category; a path reads down to it.
        return collect(array_reverse($tree->ancestors($category->id, true)))
            ->map(fn (int $id) => str_replace(';', ',', (string) pick_translation((array) $tree->find($id)?->name, content_base_locale())))
            ->implode('///');
    }

    /** The product's characteristics as a typed cell: "Colour: S[Black]; Brand: E[Apple]". */
    public static function featuresCell(Product $product): string
    {
        return $product->featureAssignments
            ->filter(fn (ProductFeature $pf) => $pf->feature !== null)
            ->sortBy(fn (ProductFeature $pf) => [$pf->feature->sort, $pf->feature->id])
            ->map(function (ProductFeature $pf) {
                $value = trim((string) $pf->display(content_base_locale()));
                if ($value === '') {
                    return null;
                }
                $type = match (true) {
                    $pf->feature->purpose === 'brand' => 'E',
                    $pf->feature->isChoiceType() => 'S',
                    $pf->feature->input_type === 'number' => 'O',
                    default => 'T',
                };
                $name = (string) pick_translation((array) $pf->feature->name, content_base_locale());

                return str_replace(';', '\\;', $name).': '.$type.'['.str_replace(';', '\\;', $value).']';
            })
            ->filter()
            ->implode('; ');
    }

    /** "1 299,50" and "1,299.50" are both a person's way of writing 1299.5. */
    public static function number(mixed $value): ?float
    {
        $text = preg_replace('/[\s\x{00A0}\x{202F}]/u', '', trim((string) $value)) ?? '';

        if ($text === '') {
            return null;
        }

        // The LAST separator is the decimal one; any before it group thousands.
        if (preg_match('/^(.*)[.,](\d{1,2})$/', $text, $m)) {
            $text = str_replace([',', '.'], '', $m[1]).'.'.$m[2];
        } else {
            $text = str_replace([',', '.'], '', $text);
        }

        return is_numeric($text) ? (float) $text : null;
    }

    protected function writeCategories(Product $product, mixed $value, array $ctx, bool $secondary): void
    {
        $row = $ctx['row'] ?? [];
        $delimiter = (string) ($ctx['options']['category_delimiter'] ?? '///') ?: '///';

        // The main column does both, so the order of the file's columns does
        // not matter: written second, it would wipe the secondary ones.
        if ($secondary && array_key_exists('category', $row)) {
            return;
        }

        $paths = $secondary ? [] : self::paths((string) $value);
        $extra = self::paths((string) ($row['secondary_categories'] ?? ''));
        $ids = [];

        foreach ([...$paths, ...$extra] as $path) {
            if ($category = $this->categoryAt($path, $delimiter, $ctx)) {
                $ids[] = $category->id;
            }
        }

        if ($secondary) {
            $product->categories()->syncWithoutDetaching($ids);
        } elseif ($ids !== []) {
            $product->categories()->sync(array_values(array_unique($ids)));
        }
    }

    /** @return list<string> */
    protected static function paths(string $cell): array
    {
        return array_values(array_filter(array_map('trim', explode(';', $cell)), fn ($p) => $p !== ''));
    }

    /** The category at "A///B///C", created level by level where missing. */
    protected function categoryAt(string $path, string $delimiter, array $ctx): ?Category
    {
        $batch = $ctx['batch'];
        $parent = null;
        $walked = [];

        foreach (array_values(array_filter(array_map('trim', explode($delimiter, $path)), fn ($n) => $n !== '')) as $name) {
            $walked[] = mb_strtolower($name);
            $parentId = $parent?->id;

            $parent = $batch->remember('category:'.implode("\x1F", $walked), function () use ($name, $parentId, $batch) {
                $found = Category::query()
                    ->where('parent_id', $parentId)
                    ->whereRaw('exists (select 1 from jsonb_each_text(name) n where lower(n.value) = lower(?))', [$name])
                    ->orderBy('id')
                    ->first();

                if ($found) {
                    return $found;
                }

                $category = new Category([
                    'name' => [content_base_locale() => $name],
                    'parent_id' => $parentId,
                    'status' => Category::STATUS_ACTIVE,
                ]);
                $this->savingCategory($category);
                $category->save();
                // The tree is a per-request memo; the next row may ask it about this one.
                app(CategoryTree::class)->flush();
                $batch->note('Line :line: category ":name" created', ['name' => $name]);

                return $category;
            });
        }

        return $parent;
    }

    protected function brandFeature(array $ctx): Feature
    {
        return $ctx['batch']->remember('brand-feature', function () use ($ctx) {
            $brand = Feature::query()->where('purpose', 'brand')->orderBy('id')->first();

            if ($brand) {
                return $brand;
            }

            $ctx['batch']->note('Line :line: characteristic ":name" created', ['name' => __('Brand', [], content_base_locale())]);

            return Feature::create([
                'name' => [content_base_locale() => __('Brand', [], content_base_locale())],
                'purpose' => 'brand',
                'input_type' => 'select',
            ]);
        });
    }

    /** "VAR Feature 2 [Цвет]" names "Цвет"; any other column names itself. */
    public static function columnName(?string $column): string
    {
        $column = trim((string) $column);

        return preg_match('/\[([^\]]+)\]\s*$/u', $column, $m) ? trim($m[1]) : $column;
    }

    /**
     * The characteristic called `$name` in any language, else a new one. A
     * variation axis found under another purpose BECOMES one: the file says the
     * products of a group differ by it, which is exactly what the purpose says.
     */
    protected function namedFeature(string $name, string $purpose, string $inputType, array $ctx): ?Feature
    {
        if ($name === '') {
            return null;
        }

        $feature = $ctx['batch']->remember('feature:'.mb_strtolower($name), function () use ($name, $purpose, $inputType, $ctx) {
            $found = Feature::query()
                ->whereRaw('exists (select 1 from jsonb_each_text(name) n where lower(n.value) = lower(?))', [$name])
                ->orderBy('id')
                ->first();

            if ($found) {
                return $found;
            }

            $ctx['batch']->note('Line :line: characteristic ":name" created', ['name' => $name]);

            return Feature::create([
                'name' => [content_base_locale() => $name],
                'purpose' => $purpose,
                'input_type' => $inputType,
                // A typed text is read on the product page, not filtered by.
                'shown_in' => $inputType === 'select' ? ['filters', 'specs', 'buy_box'] : ['specs'],
            ]);
        });

        if ($purpose === Feature::PURPOSE_VARIATION && $feature->purpose !== Feature::PURPOSE_VARIATION && $feature->purpose !== 'brand') {
            $feature->update(['purpose' => Feature::PURPOSE_VARIATION, 'input_type' => 'select']);
            $ctx['batch']->note('Line :line: characteristic ":name" now switches variations', ['name' => $name]);
        }

        return $feature;
    }

    /**
     * The product's value of one characteristic: a choice found or added among
     * its values, or typed words. An empty cell takes the value off — blank
     * and "not stated" are the same thing, as on the form.
     */
    protected function assign(Product $product, ?Feature $feature, mixed $value, array $ctx): void
    {
        if (! $feature) {
            return;
        }

        $text = trim((string) $value);

        if ($text === '') {
            ProductFeature::where('product_id', $product->id)->where('feature_id', $feature->id)->delete();

            return;
        }

        $attributes = $feature->isChoiceType()
            ? ['feature_value_id' => $this->valueOf($feature, $text, $ctx)->id, 'value_text' => null, 'value_translations' => null]
            : ['feature_value_id' => null, 'value_text' => mb_substr($feature->input_type === 'number' ? (string) (self::number($text) ?? $text) : $text, 0, 255), 'value_translations' => null];

        ProductFeature::updateOrCreate(['product_id' => $product->id, 'feature_id' => $feature->id], $attributes);
    }

    /**
     * Found by its spelling (Features\Spelling), not its exact characters: a
     * supplier's "темно-синий" is the shop's "тёмно‑синий", and a spelling
     * merged into a value is still that value — compared exactly, each file
     * made the value the shop had just merged away.
     */
    protected function valueOf(Feature $feature, string $text, array $ctx): FeatureValue
    {
        return $ctx['batch']->remember('value:'.$feature->id.':'.mb_strtolower($text), function () use ($feature, $text) {
            $found = Spelling::find($feature->id, $text);

            if ($found) {
                return $found;
            }

            $attributes = [
                'feature_id' => $feature->id,
                'value' => [content_base_locale() => $text],
                'sort' => (int) FeatureValue::where('feature_id', $feature->id)->max('sort') + 1,
            ];

            // A brand's value IS its page, and a page needs an address.
            if ($feature->purpose === 'brand') {
                $slug = Str::slug($text);
                if ($slug !== '' && ! FeatureValue::where('slug', $slug)->exists()) {
                    $attributes['slug'] = $slug;
                }
            }

            return FeatureValue::create($attributes);
        });
    }

    /**
     * The typed characteristics cell: `(Group) Name: T[value]; Other: S[a///b]`,
     * a `;` inside a value written `\;`. Only the characteristics the cell
     * names are touched; a multiple choice keeps its first value, since a
     * product holds one value per characteristic here.
     */
    protected function writeFeaturesCell(Product $product, string $cell, array $ctx): void
    {
        foreach (self::parseFeaturesCell($cell) as ['name' => $name, 'type' => $type, 'values' => $values]) {
            $inputType = self::CELL_TYPES[$type] ?? 'select';
            $feature = $type === 'E'
                ? $this->brandFeature($ctx)
                : $this->namedFeature($name, 'filter', $inputType, $ctx);

            if (count($values) > 1) {
                $ctx['batch']->note('Line :line: ":name" has several values; the first is kept', ['name' => $name]);
            }

            $this->assign($product, $feature, $values[0] ?? '', $ctx);
        }
    }

    /** @return list<array{name: string, type: string, values: list<string>}> */
    public static function parseFeaturesCell(string $cell, string $valuesDelimiter = '///'): array
    {
        $cell = preg_replace('/\{\d*\}/', '', $cell) ?? $cell; // the old `{12}Name` spelling
        $parsed = [];

        foreach (preg_split('/(?<!\\\\);/u', $cell) as $item) {
            $item = str_replace('\\;', ';', trim($item));

            if (! preg_match('/^(?:\((?<group>[^)]*)\)\s*)?(?<name>.+?):\s*(?<type>[A-Z])\[(?<value>.*)\]$/su', $item, $m)) {
                continue;
            }

            $values = array_values(array_filter(array_map('trim', explode($valuesDelimiter, $m['value'])), fn ($v) => $v !== ''));

            if ($m['type'] === 'C') {
                $values = array_map(fn ($v) => in_array(strtoupper($v), ['Y', 'YES', '1'], true) ? __('Yes', [], content_base_locale()) : __('No', [], content_base_locale()), $values);
            }

            $parsed[] = ['name' => trim($m['name']), 'type' => $m['type'], 'values' => $values];
        }

        return $parsed;
    }

    /**
     * A JSON list of `{type: section, label}` and `{key, value}` rows — the
     * specification table as some exports write it — as the product's own
     * `features` map and its `features_layout` (the order, which jsonb would
     * not keep). Null when the cell is not that.
     *
     * @return array{0: array, 1: array}|null
     */
    public function specTable(string $cell): ?array
    {
        $cell = trim($cell);

        if ($cell === '' || $cell[0] !== '[') {
            return null;
        }

        $rows = json_decode($cell, true);

        if (! is_array($rows)) {
            return null;
        }

        $map = [];
        $layout = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            if (($row['type'] ?? '') === 'section') {
                $layout[] = ['type' => 'section', 'label' => (string) ($row['label'] ?? '')];
            } elseif (($key = trim((string) ($row['key'] ?? $row['label'] ?? ''))) !== '' && ! array_key_exists($key, $map)) {
                $map[$key] = is_scalar($row['value'] ?? null) ? (string) $row['value'] : '';
                $layout[] = ['type' => 'feature', 'key' => $key];
            }
        }

        // Never a null map: the column is NOT NULL, so a supplier's `[]` for a
        // product with no specs failed its whole row. An empty table is an answer.
        return [$map, $layout ?: null];
    }

    /** Photo links for the gallery, fetched once the rows are in (torgash.media listens). */
    protected function queueImages(Product $product, string $cell, array $ctx): void
    {
        $urls = self::imageLinks($cell, (string) ($ctx['options']['images_base'] ?? ''));

        foreach ($urls['invalid'] as $bad) {
            $ctx['batch']->note('Line :line: ":value" is not a link to a photo', ['value' => Str::limit($bad, 80)]);
        }

        if ($urls['valid'] !== []) {
            $ctx['batch']->add('images', [
                'type' => $product->getMorphClass(),
                'id' => $product->id,
                'collection' => 'gallery',
                'urls' => $urls['valid'],
                'mode' => $ctx['options']['images_mode'] ?? 'empty',
            ]);
        }
    }

    /** @return array{valid: list<string>, invalid: list<string>} */
    public static function imageLinks(string $cell, string $base = ''): array
    {
        $cell = trim($cell);

        if ($cell === '') {
            return ['valid' => [], 'invalid' => []];
        }

        // Some engines separate images with "///"; otherwise commas, semicolons,
        // line breaks or spaces — none of which a sane photo address contains.
        $parts = str_contains($cell, '///') ? explode('///', $cell) : preg_split('/[\s,;|]+/u', $cell);
        $valid = [];
        $invalid = [];

        foreach (array_filter(array_map('trim', $parts), fn ($p) => $p !== '') as $part) {
            if (! preg_match('#^https?://#i', $part) && $base !== '') {
                $part = rtrim($base, '/').'/'.ltrim($part, '/');
            }

            preg_match('#^https?://[^\s/]+#i', $part) ? $valid[] = $part : $invalid[] = $part;
        }

        return ['valid' => array_values(array_unique($valid)), 'invalid' => $invalid];
    }

    /** $base, or $base-2, -3… — the first address nobody has, the bin included. */
    protected function freeSlug(string $model, string $base): string
    {
        $base = Str::limit($base, 180, '');
        $candidate = $base;

        for ($n = 2; $model::withTrashed()->where('slug', $candidate)->exists(); $n++) {
            $candidate = $base.'-'.$n;
        }

        return $candidate;
    }
}
