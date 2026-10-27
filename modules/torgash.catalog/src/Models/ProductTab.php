<?php

namespace Torgash\Catalog\Models;

use App\Core\Display\DisplayRules;
use App\Core\Support\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tab on a product page, written by the shop.
 *
 * Two ways of saying where it goes, and they are one question asked at two
 * scales: `product_id` names ONE product (the tab is edited on that product's
 * own form), and display rules name a kind of product — a category, a label, a
 * stock state, a storefront, a language. Both are answered here so that no
 * screen has to remember the difference.
 *
 * Title and content are ordinary admin-authored text, so both carry
 * placeholders: "{feature_name:ram_gb}" in a title is what another engine's add-on
 * spells `[feature_12]`, only by code rather than by a database id nobody can
 * read a year later.
 */
class ProductTab extends Model
{
    use HasTranslations;

    protected $table = 'm_catalog_product_tabs';

    protected $guarded = [];

    /** A change to it can be put back from the audit trail (Torgash\Guard\Audit\Revert): it is content, saved by its own model. */
    public static function auditRevertible(): bool
    {
        return true;
    }

    protected function casts(): array
    {
        return [
            'title' => 'array',
            'heading' => 'array',
            'content' => 'array',
            'rules' => 'array',
            'enabled' => 'bool',
            'sort' => 'int',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /** Global tabs plus the ones written for this product, in display order. */
    public function scopeForProduct(Builder $query, Product $product): Builder
    {
        return $query
            ->where(fn (Builder $q) => $q->whereNull('product_id')->orWhere('product_id', $product->id))
            ->orderBy('sort')->orderBy('id');
    }

    /**
     * The same answer a block gives, through the same engine — including its
     * two rules that must not drift: an unknown kind fails CLOSED, and so does
     * a rule whose context is missing.
     */
    public function isVisible(array $context = []): bool
    {
        return app(DisplayRules::class)->passes(
            (array) ($this->rules ?? []),
            app(DisplayRules::class)->context($context),
            (string) ($this->rules_match ?: DisplayRules::MATCH_ALL),
        );
    }

    /** A row that renames one of the page's own sections carries no content. */
    public function renamesASection(): bool
    {
        return $this->section !== null;
    }
}
