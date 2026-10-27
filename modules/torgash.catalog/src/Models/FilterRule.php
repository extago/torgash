<?php

namespace Torgash\Catalog\Models;

use App\Core\Support\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Torgash\Catalog\Support\CategoryTree;

/**
 * "A page per value of this characteristic, in these categories" — or "a page
 * per town" — or "a page per price bracket".
 *
 * The rule owns the ADDRESS and the NAME of what it makes, and nothing else:
 * titles, descriptions and H1s come from `torgash.seo`'s templates, which every
 * other kind of page in this shop already uses. Two template engines writing
 * the same head is exactly the duplication §30 exists to remove.
 *
 * **One axis per rule**, and {@see axis()} is the one answer to which: a
 * characteristic's values, a list of places, or a list of price brackets. Not
 * several at once — a rule multiplying twenty towns by thirty makes of laptop
 * publishes six hundred addresses from one screen, which is the same argument
 * that already makes one rule mean one characteristic. A page at the crossing
 * of two axes is written by hand, and there are never many of those.
 */
class FilterRule extends Model
{
    use HasTranslations;

    protected $table = 'm_catalog_filter_rules';

    protected $guarded = [];

    public const AXIS_FEATURE = 'feature';

    public const AXIS_PLACE = 'place';

    public const AXIS_PRICE = 'price';

    protected function casts(): array
    {
        return [
            'category_ids' => 'array',
            'place_ids' => 'array',
            'price_bounds' => 'array',
            'page_name' => 'array',
            'with_children' => 'bool',
            'in_sitemap' => 'bool',
            'in_list' => 'bool',
            'enabled' => 'bool',
        ];
    }

    /**
     * What this rule makes a page PER — read from what it actually holds, not
     * from a column saying so.
     *
     * Derived rather than stored because the two are the same fact twice: a
     * rule with a characteristic makes a page per value, one with a list of
     * towns makes a page per town, one with price brackets makes a page per
     * bracket, and a stored "kind" could disagree with all three.
     */
    public function axis(): string
    {
        if ($this->feature_id) {
            return self::AXIS_FEATURE;
        }

        return $this->priceBounds() !== [] ? self::AXIS_PRICE : self::AXIS_PLACE;
    }

    /**
     * The price brackets this rule writes a page per, minor units, normalised —
     * both-ends-empty rows dropped, so a half-cleared row in the admin form
     * makes no page rather than one with no boundary at all.
     *
     * NEVER computed from the catalogue (no quantiles): a boundary that moved
     * with the prices would rewrite an address that has already gone out —
     * see PLAN §30.14.
     *
     * @return list<array{min: int|null, max: int|null}>
     */
    public function priceBounds(): array
    {
        $bounds = [];

        foreach ((array) $this->price_bounds as $row) {
            $min = isset($row['min']) && $row['min'] !== null && $row['min'] !== '' ? (int) $row['min'] : null;
            $max = isset($row['max']) && $row['max'] !== null && $row['max'] !== '' ? (int) $row['max'] : null;

            if ($min === null && $max === null) {
                continue;
            }

            $bounds[] = ['min' => $min, 'max' => $max];
        }

        return $bounds;
    }

    /**
     * The brackets as editable text, one "min-max" per line — the inverse of
     * whatever the admin form parses, so an edit screen shows back exactly what
     * generated the pages it lists.
     */
    public function priceBoundsText(): string
    {
        return collect($this->priceBounds())
            ->map(fn (array $b) => ($b['min'] !== null ? FilterPage::bareAmount($b['min']) : '')
                .'-'.($b['max'] !== null ? FilterPage::bareAmount($b['max']) : ''))
            ->implode("\n");
    }

    /**
     * The towns this rule writes pages for, ids only.
     *
     * Empty is EMPTY here, never "all of them", which is the opposite of
     * categoryIds() below — and deliberately so: a pack holds 192 000 towns, so
     * "every place" is not a useful default but a way to publish a hundred
     * thousand pages by leaving a field blank.
     *
     * @return list<int>
     */
    public function placeIds(): array
    {
        return array_values(array_unique(array_filter(array_map('intval', (array) $this->place_ids))));
    }

    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(FilterPage::class, 'rule_id');
    }

    /**
     * The categories this rule writes pages in, ids only.
     *
     * Empty means every listed (active) category — which is the useful default for a
     * shop with one level of categories and the dangerous one for a shop with
     * six, hence `min_products` sitting beside it.
     *
     * @return list<int>
     */
    public function categoryIds(): array
    {
        $ids = array_values(array_filter(array_map('intval', (array) $this->category_ids)));

        if ($ids === []) {
            return Category::listed()->pluck('id')->all();
        }

        // The one place in this engine where "and everything under it" is a
        // CHOICE rather than the rule: how many categories a generator writes
        // pages in — four or forty — is a decision about how much of the
        // catalogue to publish, not about what a category means.
        return $this->with_children ? app(CategoryTree::class)->descendants($ids) : $ids;
    }
}
