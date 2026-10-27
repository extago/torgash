<?php

namespace Torgash\Options\Models;

use App\Core\Support\HasCode;
use App\Core\Support\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Torgash\Catalog\Models\Product;

/**
 * One answer to one question (PLAN §33.4) — a service priced by a formula, a
 * product, or a whole set of products to pick from.
 *
 * What a choice never carries is a copy of its product's name or price: the
 * product is the answer, and a product is allowed to be renamed and repriced
 * without every configurator in the shop going stale.
 */
class OptionChoice extends Model
{
    use HasCode, HasTranslations;

    public const KINDS = ['plain', 'product', 'source'];

    protected $table = 'm_options_choices';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'amount' => 'int',
            'amount_original' => 'int',
            'percent' => 'float',
            'weight' => 'int',
            'is_default' => 'bool',
            'enabled' => 'bool',
            'max_qty' => 'int',
            'sort' => 'int',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(OptionGroup::class, 'group_id');
    }

    /**
     * What an answer calls itself, for its exim code: the words the shop typed
     * for a service, and otherwise the thing it stands for. A `product` choice
     * has no name of its own on purpose (it reads the product's), so its SKU
     * is the only stable word it holds.
     */
    protected function codeSource(): string
    {
        $name = (array) $this->name;

        return (string) (reset($name)
            ?: ($this->product_id ? optional($this->product)->sku : null)
            ?: $this->source
            ?: 'choice');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** Does this choice stand for a set of products rather than one answer? */
    public function isSet(): bool
    {
        return $this->kind === 'source';
    }

    /**
     * What to call it. A product choice reads the PRODUCT's name — asked of the
     * product that was actually chosen, which for a `source` choice is not
     * known until the customer picks one.
     */
    public function label(?Product $product = null): string
    {
        $product ??= $this->kind === 'product' ? $this->product : null;

        if ($product) {
            return $product->t('name') ?: $product->sku;
        }

        $name = (array) $this->name;

        return $name[app()->getLocale()] ?? $name[content_base_locale()] ?? (reset($name) ?: '');
    }
}
