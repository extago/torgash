<?php

namespace Torgash\Catalog\Models;

use App\Core\Support\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeatureGroup extends Model
{
    use HasTranslations;

    protected $table = 'm_catalog_feature_groups';

    protected $guarded = [];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Characteristic group';
    }

    protected function casts(): array
    {
        return ['name' => 'array'];
    }

    public function features(): HasMany
    {
        return $this->hasMany(Feature::class, 'group_id')->orderBy('sort');
    }
}
