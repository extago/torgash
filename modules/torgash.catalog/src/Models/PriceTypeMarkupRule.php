<?php

namespace Torgash\Catalog\Models;

use App\Core\Support\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Torgash\Catalog\Support\CategoryTree;

class PriceTypeMarkupRule extends Model
{
    use HasTranslations;

    protected $table = 'm_catalog_price_type_markup_rules';

    protected $guarded = [];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Markup rule';
    }

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'cost_price_min' => 'int',
            'cost_price_max' => 'int',
            'markup_value' => 'int',
            'round_to' => 'int',
            'psychological_offset' => 'int',
            'enabled' => 'bool',
        ];
    }

    public function priceType(): BelongsTo
    {
        return $this->belongsTo(PriceType::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'm_catalog_price_type_markup_rule_category', 'rule_id', 'category_id');
    }

    public function featureValues(): BelongsToMany
    {
        return $this->belongsToMany(FeatureValue::class, 'm_catalog_price_type_markup_rule_feature_value', 'rule_id', 'feature_value_id');
    }

    /** Every present condition must match; an unset one (no rows / no min-max) matches everything. */
    public function matches(Product $product): bool
    {
        if ($this->categories->isNotEmpty()) {
            // The chosen categories AND everything under them: a markup written
            // for "Electronics" is meant for the cameras in it, not for the
            // nothing that is filed directly under the branch.
            $wanted = app(CategoryTree::class)->descendants($this->categories->pluck('id')->all());

            if (array_intersect($wanted, $product->categories->pluck('id')->all()) === []) {
                return false;
            }
        }

        if ($this->featureValues->isNotEmpty()) {
            $productValueIds = $product->featureAssignments->pluck('feature_value_id')->filter();
            if ($this->featureValues->pluck('id')->intersect($productValueIds)->isEmpty()) {
                return false;
            }
        }

        if ($this->cost_price_min !== null && $product->cost_price < $this->cost_price_min) {
            return false;
        }

        if ($this->cost_price_max !== null && $product->cost_price > $this->cost_price_max) {
            return false;
        }

        return true;
    }

    public function apply(int $costPrice): int
    {
        $marked = $this->markup_type === 'fixed'
            ? $costPrice + $this->markup_value
            : (int) round($costPrice * (10000 + $this->markup_value) / 10000);

        return $this->rounding_mode === 'psychological'
            ? $this->roundPsychological($marked)
            : $this->roundNearest($marked);
    }

    protected function roundNearest(int $value): int
    {
        $n = max(1, $this->round_to);

        return match ($this->round_direction) {
            'up' => (int) (ceil($value / $n) * $n),
            'down' => (int) (floor($value / $n) * $n),
            default => (int) (round($value / $n) * $n),
        };
    }

    /** Round down to round_to, then subtract a fixed offset — e.g. round_to=1000, offset=1 → ...999. */
    protected function roundPsychological(int $value): int
    {
        $n = max(1, $this->round_to);
        $offset = $this->psychological_offset ?? 0;

        return max(0, (int) (floor($value / $n) * $n) - $offset);
    }

    /**
     * The rule in words, in three parts — which products, how much over cost,
     * how it is rounded — so the price type's card and anything else that
     * lists rules say the same sentence. Amounts through admin_price(): these
     * are the shop's books.
     *
     * @return array{when: string, markup: string, rounding: string}
     */
    public function describe(): array
    {
        $when = [];
        if ($this->categories->isNotEmpty()) {
            $when[] = trans_choice(':count category|:count categories', $this->categories->count(), ['count' => $this->categories->count()]);
        }
        if ($this->featureValues->isNotEmpty()) {
            $when[] = trans_choice(':count characteristic value|:count characteristic values', $this->featureValues->count(), ['count' => $this->featureValues->count()]);
        }
        if ($this->cost_price_min !== null) {
            $when[] = __('cost from :amount', ['amount' => admin_price($this->cost_price_min)]);
        }
        if ($this->cost_price_max !== null) {
            $when[] = __('cost up to :amount', ['amount' => admin_price($this->cost_price_max)]);
        }

        return [
            'when' => $when ? implode(', ', $when) : __('every product'),
            'markup' => $this->markup_type === 'percent'
                ? __('cost + :percent%', ['percent' => rtrim(rtrim(number_format($this->markup_value / 100, 2, '.', ''), '0'), '.')])
                : __('cost + :amount', ['amount' => admin_price($this->markup_value)]),
            'rounding' => $this->rounding_mode === 'psychological'
                ? __('down to :step, minus :offset', ['step' => admin_price($this->round_to), 'offset' => admin_price($this->psychological_offset ?? 0)])
                : match ($this->round_direction) {
                    'up' => __('up to :step', ['step' => admin_price($this->round_to)]),
                    'down' => __('down to :step', ['step' => admin_price($this->round_to)]),
                    default => __('to the nearest :step', ['step' => admin_price($this->round_to)]),
                },
        ];
    }
}
