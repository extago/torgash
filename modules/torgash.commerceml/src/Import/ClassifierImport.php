<?php

namespace Torgash\CommerceMl\Import;

use Illuminate\Support\Str;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\CommerceMl\Models\Link;
use Torgash\CommerceMl\Xml\Node;

/**
 * The classifier: the catalogue tree and the vocabulary of properties.
 *
 * Read whole rather than streamed — it is the shape of the catalogue, not the
 * goods, and it is asked cross-wise (a group's children, a property's variants).
 *
 * The two halves are handled deliberately differently, and the difference is the
 * whole argument of this module. A GROUP is a category, and a shop that connects
 * 1C generally means for 1C to own its catalogue tree, so groups are created.
 * A PROPERTY is 1C's internal bookkeeping vocabulary — "ЕдиницаХраненияОстатков",
 * "СтавкаНДСДляУчёта" — and letting that in unread is how a filter panel grows
 * forty entries nobody meant to publish. So properties are RECORDED and wait for
 * a person, unless the shop has said otherwise.
 */
class ClassifierImport
{
    public function __construct(protected Matching $matching) {}

    /** @return array{groups: int, properties: int} */
    public function run(Node $classifier): array
    {
        $groups = $this->groups($classifier->child('groups')?->all('group') ?? [], null);
        $properties = $this->properties($classifier->each('properties', 'property'));

        return ['groups' => $groups, 'properties' => $properties];
    }

    /**
     * The tree, depth first, each group carrying its parent.
     *
     * @param  array<int, Node>  $nodes
     */
    protected function groups(array $nodes, ?int $parentId): int
    {
        $count = 0;
        $sort = 0;

        foreach ($nodes as $node) {
            $guid = $node->text('id');
            $name = $node->text('name');

            if ($guid === null) {
                continue;
            }

            $category = $this->category($guid, $name, $parentId, $sort++);
            $count++;

            if ($category) {
                // The children are recursed into whether or not this one was
                // created: a shop that has said "no new categories" still wants
                // the tree walked, because a deeper group may already be linked.
                $count += $this->groups($node->child('groups')?->all('group') ?? [], $category->id);
            }
        }

        return $count;
    }

    protected function category(string $guid, ?string $name, ?int $parentId, int $sort): ?Category
    {
        // The identifier first, always — that is the whole of §58, and it is
        // what makes renaming a category on either side harmless.
        $category = Category::withTrashed()->where('external_id', $guid)->first();

        if (! $category && setting('torgash.commerceml', 'match_categories_by_name', true) && $name !== null) {
            // Once, and only for a row that has no identifier yet: this is how
            // an existing catalogue acquires its links on the first exchange.
            // A category that already belongs to another 1C group is not a
            // candidate, however alike the names.
            $category = Category::whereNull('external_id')
                ->get()
                ->first(fn (Category $c) => $this->sameName($c->name, $name));

            $category?->forceFill(['external_id' => $guid])->save();
        }

        if ($category) {
            $category->forceFill(['name' => $this->translate($category->name, $name)])->save();

            return $category;
        }

        if (! setting('torgash.commerceml', 'create_categories', true) || $name === null) {
            return null;
        }

        return Category::create([
            'external_id' => $guid,
            'parent_id' => $parentId,
            'name' => $this->translate(null, $name),
            'slug' => $this->slug(Category::class, $name),
            'sort' => $sort,
            'status' => Category::STATUS_ACTIVE,
        ]);
    }

    /**
     * Properties, and their variants under them.
     *
     * @param  array<int, Node>  $nodes
     */
    protected function properties(array $nodes): int
    {
        $count = 0;

        foreach ($nodes as $node) {
            $guid = $node->text('id');
            $name = $node->text('name');

            if ($guid === null) {
                continue;
            }

            $link = $this->matching->see(Link::KIND_FEATURE, $guid, $name);
            $count++;

            if ($link->decision === Link::CREATE && ! $link->local_id) {
                $feature = Feature::create(['name' => $this->translate(null, $name ?? $guid), 'sort' => 0]);
                $this->matching->point($link, $feature->id);
                $link->refresh();
            }

            $count += $this->variants($node, $link);
        }

        return $count;
    }

    protected function variants(Node $property, Link $propertyLink): int
    {
        $count = 0;

        foreach ($property->child('property_variants')?->all('directory') ?? [] as $variant) {
            $valueGuid = $variant->text('value_id');
            $value = $variant->text('value');

            if ($valueGuid === null) {
                continue;
            }

            $link = $this->matching->see(
                Link::KIND_FEATURE_VALUE,
                Link::valueGuid($propertyLink->guid, $valueGuid),
                $value,
                $propertyLink->guid,
            );
            $count++;

            // A variant cannot be created before its property is: it has
            // nowhere to hang. The row stays in the queue and the screen shows
            // it under a property that is still undecided, which is exactly the
            // shape of the question a person has to answer.
            if ($link->decision === Link::CREATE && ! $link->local_id && $propertyLink->local_id) {
                $featureValue = FeatureValue::create([
                    'feature_id' => $propertyLink->local_id,
                    'value' => $this->translate(null, $value ?? $valueGuid),
                    'sort' => 0,
                ]);
                $this->matching->point($link, $featureValue->id);
            }
        }

        return $count;
    }

    /**
     * A translatable field, with 1C's language written into the right slot.
     *
     * 1C speaks one language and does not say which; the shop does, on the
     * exchange screen. Everything else already in the field is left alone —
     * a nightly import must not wipe the English a translator wrote.
     *
     * @param  array<string, string>|string|null  $existing
     * @return array<string, string>
     */
    protected function translate(array|string|null $existing, ?string $value): array
    {
        $locale = (string) setting('torgash.commerceml', 'content_locale', config('app.locale'));
        $current = is_array($existing) ? $existing : ($existing === null ? [] : ['en' => $existing]);

        return $value === null ? $current : [$locale => $value] + $current;
    }

    /** @param array<string, string>|string|null $name */
    protected function sameName(array|string|null $name, string $other): bool
    {
        $values = is_array($name) ? $name : [$name];

        foreach ($values as $value) {
            if ($value !== null && mb_strtolower(trim((string) $value)) === mb_strtolower(trim($other))) {
                return true;
            }
        }

        return false;
    }

    /** A slug that is free, because a duplicate is a 500 on save and not a warning. */
    protected function slug(string $model, string $from): string
    {
        $base = Str::slug($from) ?: 'item';
        $slug = $base;
        $n = 2;

        while ($model::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }
}
