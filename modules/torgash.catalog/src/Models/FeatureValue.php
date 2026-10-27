<?php

namespace Torgash\Catalog\Models;

use App\Core\Seo\HasPageMeta;
use App\Core\Seo\PageSubject;
use App\Core\Support\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Torgash\Catalog\Support\AssignsCode;

class FeatureValue extends Model implements HasMedia, PageSubject
{
    use AssignsCode, HasPageMeta, HasTranslations, InteractsWithMedia;

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Characteristic value';
    }

    /** A feature value with a slug IS the brand page, so it is a subject like any other. */
    public const PAGE_META_KIND = 'brand';

    protected $table = 'm_catalog_feature_values';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'value' => 'array',
            'description' => 'array',
            'meta_title' => 'array',
            'meta_description' => 'array',
            'h1' => 'array',
            'noindex' => 'bool',
        ];
    }

    /**
     * A value is named in `value`, not in `name` — and its code is unique
     * within its own characteristic, not across the table: "WHITE" may
     * reasonably be a value of both Colour and Frame colour, which is what the
     * partial unique index on (feature_id, code) already says.
     */
    protected function codeNames(): array
    {
        return array_filter((array) ($this->value ?? []), 'is_string');
    }

    protected function codeScope(): array
    {
        return ['feature_id' => $this->feature_id];
    }

    protected function codeFallbackPrefix(): string
    {
        return 'VALUE';
    }

    public function registerMediaCollections(): void
    {
        // Only meaningful for Feature::purpose === 'brand' values — a single logo.
        $this->addMediaCollection('logo')->singleFile();
    }

    public function logoUrl(): ?string
    {
        return $this->getFirstMediaUrl('logo') ?: null;
    }

    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }

    /** Values are stored under "value", not "name" — HasTranslations::t() takes the field name explicitly. */
    public function display(?string $locale = null): ?string
    {
        return $this->t('value', $locale);
    }

    /** Products carrying this value, via the m_catalog_product_features pivot. */
    public function products()
    {
        return Product::whereHas('featureAssignments', fn ($q) => $q->where('feature_value_id', $this->id));
    }
}
