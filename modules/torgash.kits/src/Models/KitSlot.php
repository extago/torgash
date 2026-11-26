<?php

namespace Torgash\Kits\Models;

use App\Core\Support\HasCode;
use App\Core\Support\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Selections\Selection;

/**
 * One place in a kit: which goods, how many, and how much cheaper they are
 * inside it (PLAN §33.5).
 *
 * `to_price` is deliberately among the discount kinds: "the case is 480 with a
 * phone" is how a shop thinks about a kit, and expressing it as a percentage
 * of a price that moves would mean re-deciding the promise every time the case
 * is repriced.
 */
class KitSlot extends Model
{
    use HasCode, HasTranslations;

    /** One product, one of a catalogue set, or one of the products typed by SKU. */
    public const KINDS = ['product', 'source', 'list'];

    public const DISCOUNTS = ['none', 'percent', 'fixed', 'to_price'];

    protected $table = 'm_kits_slots';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'match_features' => 'array',
            'caption' => 'array',
            'qty' => 'int',
            'discount_percent' => 'float',
            'discount_amount' => 'int',
            'discount_amount_original' => 'int',
            'required' => 'bool',
            'swappable' => 'bool',
            'enabled' => 'bool',
            'sort' => 'int',
        ];
    }

    /**
     * The characteristics this slot insists on matching, as ids.
     *
     * ALL of them have to match — an outfit is the same colour AND the same
     * fabric — and a slot that names none matches on nothing, which is how
     * "any case at all" is said.
     *
     * @return list<int>
     */
    public function matchFeatureIds(): array
    {
        return array_values(array_filter(array_map('intval', (array) $this->match_features)));
    }

    public function kit(): BelongsTo
    {
        return $this->belongsTo(Kit::class, 'kit_id');
    }

    /**
     * A slot has no name — it is "the thing in this position" — so its exim
     * code is derived from what stands in it.
     */
    protected function codeSource(): string
    {
        return (string) (($this->product_id ? optional($this->product)->sku : null) ?: ($this->skus()[0] ?? null) ?: $this->source ?: 'slot');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** A slot the customer may choose in: a catalogue set or a typed list. */
    public function isSet(): bool
    {
        return $this->kind === 'source' || $this->kind === 'list';
    }

    /**
     * The SKUs a `list` slot names, in the order typed, each once — read the
     * way a products block reads its own (`Selection::skus()`).
     *
     * @return list<string>
     */
    public function skus(): array
    {
        return $this->kind === 'list' ? Selection::skus(['products' => (string) $this->products]) : [];
    }

    /** What the slot's tick says on the card; the general words without one. */
    public function captionText(): string
    {
        return (string) ($this->t('caption') ?: __('In the set'));
    }

    /**
     * What one unit of this product costs inside the kit.
     *
     * Never below zero and never above the price itself: a shop that types
     * "−200" on a 150 item is making a mistake, not a gift, and an engine that
     * lets a line go negative makes an order that cannot be paid for.
     */
    public function priceFor(int $fullPrice): int
    {
        $price = match ($this->discount_type) {
            'percent' => round_money($fullPrice * (100 - (float) $this->discount_percent) / 100),
            'fixed' => $fullPrice - (int) $this->discount_amount,
            'to_price' => (int) $this->discount_amount,
            default => $fullPrice,
        };

        return max(0, min($fullPrice, $price));
    }

    /** What the customer saves on one unit — what the card prints. */
    public function cutFor(int $fullPrice): int
    {
        return max(0, $fullPrice - $this->priceFor($fullPrice));
    }

    public static function discountLabels(): array
    {
        return [
            'none' => __('No discount'),
            'percent' => __('Percent off'),
            'fixed' => __('Amount off'),
            'to_price' => __('The price becomes'),
        ];
    }
}
