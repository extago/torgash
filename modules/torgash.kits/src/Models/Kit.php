<?php

namespace Torgash\Kits\Models;

use App\Core\Support\HasCode;
use App\Core\Support\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Torgash\Catalog\Models\Product;

/**
 * "Everything you need, in one set" — several separate products that are
 * cheaper bought together (PLAN §33.5).
 */
class Kit extends Model
{
    use HasCode, HasTranslations;

    /**
     * Three places, and they are three WIDTHS.
     *
     * `before_details` is the one to reach for: a kit is a ROW — several
     * products side by side with the totals beside them — and the buy panel
     * is 400px wide, which is not a row. It is the default for a new kit for
     * that reason; a kit already placed keeps whatever the shop chose.
     */
    public const PLACEMENTS = ['before_details', 'after_buy_box', 'tab'];

    protected $table = 'm_kits_kits';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'description' => 'array',
            'rules' => 'array',
            'enabled' => 'bool',
            'whole_set_only' => 'bool',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'sort' => 'int',
        ];
    }

    public function slots(): HasMany
    {
        return $this->hasMany(KitSlot::class, 'kit_id')->orderBy('sort')->orderBy('id');
    }

    public function mainProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'main_product_id');
    }

    /** A kit nobody owns goes where its rules say; one with a product goes with it. */
    public function isShared(): bool
    {
        return $this->main_product_id === null;
    }

    /**
     * Is it on offer at all right now — enabled and inside its dates.
     *
     * Asked wherever a kit is about to be priced, not only where it is drawn:
     * a cart line bought yesterday out of a kit that ended overnight is charged
     * full price today, which is what "the offer has ended" means.
     */
    public function isCurrent(): bool
    {
        if (! $this->enabled) {
            return false;
        }

        $now = now();

        return ! (($this->starts_at && $this->starts_at->greaterThan($now))
            || ($this->ends_at && $this->ends_at->lessThan($now)));
    }

    /**
     * How the goods stand on a wide screen: rows that wrap, or one line that
     * scrolls. See the migration for why these are the two.
     */
    public const LAYOUTS = ['grid', 'scroller'];

    /**
     * What a kit IS — see the 2026-09-20 migration for the whole argument.
     *
     * `simple` puts several SEPARATE lines in the basket and carries no
     * discount; `extended` is ONE line whose parts are the rest of the set,
     * and is the only one that may. The split exists because "separate lines
     * plus a discount" was a measured hole: a hundred glasses behind one
     * phone, all at half price.
     */
    public const TYPES = ['simple', 'extended'];

    public static function typeLabels(): array
    {
        return [
            'simple' => __('Several separate items, no discount'),
            'extended' => __('One item with the rest inside it, discounts allowed'),
        ];
    }

    /** May this kit give money away, and is it bought as one thing? */
    public function isExtended(): bool
    {
        return $this->type === 'extended';
    }

    public static function layoutLabels(): array
    {
        return [
            'grid' => __('A grid that wraps'),
            'scroller' => __('One line that scrolls sideways'),
        ];
    }

    public static function placementLabels(): array
    {
        return [
            'before_details' => __('Above the tabs, the full width of the page'),
            'after_buy_box' => __('Under the buy button, in the narrow column'),
            'tab' => __('A section of the product page'),
        ];
    }
}
