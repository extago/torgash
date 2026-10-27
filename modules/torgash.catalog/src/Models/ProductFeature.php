<?php

namespace Torgash\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A product's assigned value for one global catalog Feature. */
class ProductFeature extends Model
{
    protected $table = 'm_catalog_product_features';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            // A typed value's words in other languages, {locale: word};
            // `value_text` is the base language's (see the migration).
            'value_translations' => 'array',
        ];
    }

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Characteristic of a product';
    }

    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }

    public function featureValue(): BelongsTo
    {
        return $this->belongsTo(FeatureValue::class);
    }

    /**
     * The value as a reader of that language sees it: a picked value from the
     * value catalogue, a typed one in that language — the base word wherever
     * it has none.
     */
    public function display(?string $locale = null): ?string
    {
        if ($this->featureValue) {
            return $this->featureValue->display($locale);
        }

        $words = (array) ($this->value_translations ?? []);
        $word = trim((string) ($words[$locale ?? app()->getLocale()] ?? ''));

        return $word !== '' ? $word : $this->value_text;
    }
}
