<?php

namespace Torgash\Catalog\Models;

use App\Core\Support\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Torgash\Catalog\Support\AssignsCode;

class Feature extends Model
{
    /**
     * A characteristic that tells the products of one variation group apart
     * (Support\Variations) — the shop's explicit "this one may switch a
     * product", because a group's products can differ in many more ways.
     */
    public const PURPOSE_VARIATION = 'variation';

    /**
     * How the values are drawn (see the migration that added the columns):
     * on the product page's variation picker, and in the filter panel. Only
     * drawing — the control underneath is the same in every style.
     */
    public const PRODUCT_STYLES = ['labels', 'images', 'swatches', 'dropdown'];

    public const FILTER_STYLES = ['list', 'chips', 'swatches'];

    use AssignsCode, HasTranslations;

    protected $table = 'm_catalog_features';

    protected $guarded = [];

    /**
     * Postgres does not read a column DEFAULT back into memory after INSERT
     * (only `RETURNING id`), so a freshly created Feature would answer NULL for
     * `shown_in` until something called `fresh()` — and `shows()` would then
     * report the legacy answer for a row that is not legacy at all. Mirrored
     * here for the same reason `availability_status` is on Product.
     */
    protected $attributes = ['shown_in' => '["filters","specs","buy_box"]', 'product_style' => 'labels', 'filter_style' => 'list'];

    /** The picker's style as it can be drawn: swatches need a colour characteristic, images a variation axis. */
    public function productStyle(): string
    {
        $style = in_array($this->product_style, self::PRODUCT_STYLES, true) ? $this->product_style : 'labels';

        return match (true) {
            $style === 'swatches' && ! $this->is_color => 'labels',
            default => $style,
        };
    }

    /** The filter panel's style as it can be drawn: swatches need a colour characteristic. */
    public function filterStyle(): string
    {
        $style = in_array($this->filter_style, self::FILTER_STYLES, true) ? $this->filter_style : 'list';

        return $style === 'swatches' && ! $this->is_color ? 'list' : $style;
    }

    protected function casts(): array
    {
        return ['name' => 'array', 'shown_in' => 'array', 'is_color' => 'bool', 'in_short_description' => 'bool'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(FeatureGroup::class, 'group_id');
    }

    public function values(): HasMany
    {
        return $this->hasMany(FeatureValue::class)->orderBy('sort');
    }

    /** Empty = applies to every category. */
    /** Which products carry a value of it — counted on the list screen. */
    public function assignments(): HasMany
    {
        return $this->hasMany(ProductFeature::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'm_catalog_feature_category', 'feature_id', 'category_id');
    }

    /** `FEATURE-11` for a characteristic whose name nothing can be made of — see AssignsCode. */
    protected function codeFallbackPrefix(): string
    {
        return 'FEATURE';
    }

    public function isChoiceType(): bool
    {
        return $this->input_type === 'select';
    }

    /** Typed words rather than a pick or a number — the one kind whose value is said per language. */
    public function isTypedText(): bool
    {
        return ! $this->isChoiceType() && $this->input_type !== 'number';
    }

    /**
     * Is this characteristic drawn in that place — the filter panel, the
     * specifications, the short list under the buy button?
     *
     * NULL is the one answer that is not a list: a row written before the
     * column existed, or by a raw insert that did not mention it. It means
     * every place, because that is what such a row already did — reading it as
     * "nowhere" would make an imported characteristic disappear from the shop
     * without anybody having asked for it. What a MODULE no longer answers for
     * needs no rule at all: a key nobody asks about is simply never matched.
     *
     * Says nothing about filtering — see Torgash\Catalog\Features\Places.
     */
    public function shows(string $place): bool
    {
        $places = $this->shown_in;

        return $places === null || in_array($place, (array) $places, true);
    }

    /** @param  Builder<Feature>  $query */
    public function scopeShownIn(Builder $query, string $place): void
    {
        $query->where(fn ($q) => $q
            ->whereNull('shown_in')
            ->orWhereRaw('shown_in @> ?::jsonb', [json_encode([$place])]));
    }
}
