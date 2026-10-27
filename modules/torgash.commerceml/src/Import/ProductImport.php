<?php

namespace Torgash\CommerceMl\Import;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\CommerceMl\Models\Exchange;
use Torgash\CommerceMl\Models\Link;
use Torgash\CommerceMl\Xml\Node;
use Torgash\Media\ImageProcessor;

/**
 * One `<Товар>` becomes one product — or deliberately does not.
 *
 * Most of this class is about NOT writing. A nightly exchange runs unattended
 * against a shop somebody has spent a month arranging, and the failure mode that
 * matters is not "the import stopped" — that gets noticed — but "the import
 * quietly replaced the copywriting, the photographs and the category tree with
 * an accountant's spreadsheet". Hence the strategy, the per-field "only when
 * new", and the change policies for categories and photographs.
 */
class ProductImport
{
    public function __construct(
        protected Matching $matching,
        protected FieldMap $fields,
        protected ImageProcessor $images,
    ) {}

    /** @return string one of created|updated|skipped */
    public function run(Node $node, Exchange $exchange): string
    {
        $guid = $node->text('id');

        if ($guid === null) {
            return 'skipped';
        }

        $strategy = (string) setting('torgash.commerceml', 'import_strategy', 'all');

        if ($strategy === 'none') {
            // "Receive and record, change nothing" — the setting a shop should
            // start with. The classifier still fills the matching queue, so the
            // first exchange shows what 1C actually sends before it can write.
            return 'skipped';
        }

        // `Ид` of the form GUID#GUID is not a product but a CHARACTERISTIC of
        // one: 1C's way of saying "the red 42 of that shoe". The first half
        // names the product it varies.
        [$ownGuid, $variationOf] = $this->splitVariation($guid);

        $product = $this->find($guid, $node);
        $isNew = $product === null;

        if ($isNew && $strategy === 'update') {
            return 'skipped';
        }

        if (! $isNew && $strategy === 'create') {
            return 'skipped';
        }

        // `Статус="Удален"` — 1C says it is gone. Disabled, never deleted: the
        // URL stays reachable, orders that reference it stay readable, and a
        // deletion sent by mistake is one click to undo.
        if ($node->attr('status') === \Torgash\CommerceMl\Xml\Tags::of('deleted')) {
            $product?->forceFill(['status' => 'disabled'])->save();

            return $product ? 'updated' : 'skipped';
        }

        $product ??= new Product([
            'status' => (string) setting('torgash.commerceml', 'new_product_status', 'hidden'),
            'stock' => 0,
            'price' => 0,
        ]);

        $product->external_id = $guid;

        if ($variationOf !== null) {
            $product->variation_group = $ownGuid;
        }

        $this->basics($product, $node, $isNew);
        $product->save();

        $this->categories($product, $node, $isNew);
        $this->features($product, $node, $isNew);
        $this->photographs($product, $node, $exchange, $isNew);

        return $isNew ? 'created' : 'updated';
    }

    // ------------------------------------------------------------------ parts

    protected function basics(Product $product, Node $node, bool $isNew): void
    {
        $this->maybe($product, 'name', $isNew, function () use ($product, $node) {
            $name = $this->fields->name($node);

            if ($name !== null) {
                $product->name = $this->translate($product->name, $name);
            }
        });

        $this->maybe($product, 'sku', $isNew, function () use ($product, $node) {
            $sku = $this->fields->sku($node);

            // A blank sku is not a sku, and a duplicate one is a unique-index
            // violation five thousand products into an unattended import.
            if ($sku !== null && ! $this->skuTaken($sku, $product)) {
                $product->sku = $sku;
            }
        });

        $this->maybe($product, 'description', $isNew, function () use ($product, $node) {
            $description = $this->fields->description($node);

            if ($description !== null) {
                $product->description = $this->translate($product->description, $description);
            }
        });

        // Measurements are not in the "only when new" list: a weight is a fact
        // about the object, not an editorial decision, and a shop that corrects
        // one in 1C means it.
        $weight = $this->fields->weight($node);

        if ($weight !== null) {
            $product->weight = $weight;
        }

        foreach ($this->fields->box($node) as $side => $value) {
            if ($value !== null) {
                $product->{$side} = $value;
            }
        }

        if ($product->sku === null) {
            // Something has to be there; the column is what the shop searches
            // by. The identifier is ugly but it is unique and it is honest.
            $product->sku = Str::limit((string) $product->external_id, 60, '');
        }

        if ($isNew || ! $product->slug) {
            $product->slug = $this->freeSlug((string) ($this->fields->name($node) ?? $product->sku));
        }
    }

    protected function categories(Product $product, Node $node, bool $isNew): void
    {
        $policy = (string) setting('torgash.commerceml', 'category_change', 'add');

        if ($this->fields->onlyForNew('categories') && ! $isNew) {
            return;
        }

        if ($policy === 'new_only' && ! $isNew) {
            return;
        }

        $guids = $node->child('groups')?->texts('id') ?? [];

        if ($guids === []) {
            return;
        }

        $ids = Category::whereIn('external_id', $guids)->pluck('id')->all();

        if ($ids === []) {
            // Every group of this product is one the shop refused to create.
            // Leaving the product where it is beats moving it to nowhere.
            return;
        }

        $policy === 'replace'
            ? $product->categories()->sync($ids)
            : $product->categories()->syncWithoutDetaching($ids);
    }

    /**
     * `ЗначенияСвойств` — the product's own answers to the classifier's
     * questions.
     *
     * A property the shop has not decided about writes NOTHING, which is the
     * point of the queue: an unread vocabulary does not reach the filter panel
     * by accident.
     */
    protected function features(Product $product, Node $node, bool $isNew): void
    {
        if ($this->fields->onlyForNew('features') && ! $isNew) {
            return;
        }

        $attach = [];

        foreach ($node->each('property_values', 'property_value') as $value) {
            $propertyGuid = $value->text('id');
            $raw = $value->text('value');

            if ($propertyGuid === null || $raw === null) {
                continue;
            }

            if ($this->matching->decision(Link::KIND_FEATURE, $propertyGuid) !== Link::LINK) {
                continue;
            }

            $featureId = $this->matching->localId(Link::KIND_FEATURE, $propertyGuid);

            // The value is either one of the property's variants (sent as its
            // identifier) or free text. The identifier case is the one worth
            // having: it is what a filter can group by.
            $valueId = $this->matching->localId(
                Link::KIND_FEATURE_VALUE,
                Link::valueGuid($propertyGuid, $raw),
            );

            $attach[] = [
                'feature_id' => $featureId,
                'feature_value_id' => $valueId,
                'value_text' => $valueId === null ? $raw : null,
            ];
        }

        if ($attach === []) {
            return;
        }

        // Replaced rather than merged, and only for the features 1C owns: a
        // second run must not leave last night's value beside tonight's.
        $product->featureAssignments()
            ->whereIn('feature_id', array_column($attach, 'feature_id'))
            ->delete();

        foreach ($attach as $row) {
            $product->featureAssignments()->create($row);
        }
    }

    /**
     * `Картинка` names a file 1C uploaded earlier in this same conversation,
     * relative to the exchange's own directory.
     */
    protected function photographs(Product $product, Node $node, Exchange $exchange, bool $isNew): void
    {
        $policy = (string) setting('torgash.commerceml', 'image_change', 'new_only');

        if ($policy === 'new_only' && ! $isNew) {
            return;
        }

        $paths = [];

        foreach ($node->texts('image') as $relative) {
            $path = $this->safePath($exchange, $relative);

            if ($path !== null && is_file($path)) {
                $paths[] = $path;
            }
        }

        if ($paths === []) {
            return;
        }

        if ($policy === 'replace') {
            $product->clearMediaCollection('gallery');
        }

        foreach ($paths as $path) {
            // An UploadedFile in test mode is the only way into the media
            // module's own pipeline for a file that is already on disk, and
            // going through that pipeline is the point: the same resizing,
            // the same limits, the same conversions as an upload by hand.
            $this->images->addUpload(
                $product,
                new UploadedFile($path, basename($path), null, null, true),
            );
        }
    }

    // ---------------------------------------------------------------- helpers

    /**
     * The product this element is about, by identifier first and by article
     * number only when there is no identifier yet.
     *
     * The order is the shop's own exchange convention (§58) and the reason it
     * exists: an article number is the shop's and the shop renames it, while
     * an identifier is 1C's and it does not.
     */
    protected function find(string $guid, Node $node): ?Product
    {
        $product = Product::withTrashed()->where('external_id', $guid)->first();

        if ($product) {
            return $product;
        }

        if (! setting('torgash.commerceml', 'match_products_by_sku', true)) {
            return null;
        }

        $sku = $this->fields->sku($node);

        return $sku === null
            ? null
            : Product::withTrashed()->whereNull('external_id')->where('sku', $sku)->first();
    }

    /** @return array{0: string, 1: ?string} the product's own id, and what it varies */
    protected function splitVariation(string $guid): array
    {
        $parts = explode('#', $guid, 2);

        return [$parts[0], $parts[1] ?? null];
    }

    /** Run the writer unless this field is one the shop pinned to first import. */
    protected function maybe(Product $product, string $field, bool $isNew, callable $write): void
    {
        if ($isNew || ! $this->fields->onlyForNew($field)) {
            $write();
        }
    }

    protected function skuTaken(string $sku, Product $product): bool
    {
        return Product::withTrashed()
            ->where('sku', $sku)
            ->when($product->exists, fn ($q) => $q->whereKeyNot($product->getKey()))
            ->exists();
    }

    protected function freeSlug(string $from): string
    {
        $base = Str::slug($from) ?: 'product';
        $slug = $base;
        $n = 2;

        while (Product::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    /**
     * A path inside the exchange's directory, or null.
     *
     * This is the one genuinely dangerous string in the module: it comes out of
     * a file a machine uploaded, and `../../.env` is a perfectly valid thing to
     * write in an XML element. Resolved and then checked to be under the
     * directory it claims to be in — the check is on the RESOLVED path,
     * because stripping "../" from the text is a filter people get wrong.
     */
    protected function safePath(Exchange $exchange, string $relative): ?string
    {
        $base = realpath($exchange->path);
        $target = realpath($exchange->path.DIRECTORY_SEPARATOR.ltrim(str_replace('\\', '/', $relative), '/'));

        if ($base === false || $target === false) {
            return null;
        }

        return str_starts_with($target, $base.DIRECTORY_SEPARATOR) ? $target : null;
    }

    /**
     * @param  array<string, string>|string|null  $existing
     * @return array<string, string>
     */
    protected function translate(array|string|null $existing, string $value): array
    {
        $locale = (string) setting('torgash.commerceml', 'content_locale', config('app.locale'));
        $current = is_array($existing) ? $existing : ($existing === null ? [] : ['en' => $existing]);

        return [$locale => $value] + $current;
    }
}
