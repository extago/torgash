<?php

namespace Torgash\Reviews\Models;

use App\Core\Support\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One thing a shop asks its customers to rate — "Sound", "Fit", "Battery
 * life".
 *
 * A row rather than a setting, for the reason a product section's heading is a
 * column: the label has to exist in every language the shop speaks, and a
 * setting holds one string. It is also a list a shop edits — a bookshop rates
 * nothing a clothes shop rates — which is a screen, not a config key.
 *
 * @property array<string, string> $name
 */
class Criterion extends Model
{
    use HasTranslations;

    protected $table = 'm_reviews_criteria';

    protected $fillable = ['code', 'name', 'sort', 'enabled'];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Review criterion';
    }

    protected function casts(): array
    {
        return ['name' => 'array', 'enabled' => 'bool', 'sort' => 'int'];
    }

    /**
     * The categories this criterion is asked in. NONE means everywhere, which
     * is how a shop that rates everything the same way keeps an empty screen —
     * the same convention a filter characteristic already follows.
     */
    public function categories(): HasMany
    {
        return $this->hasMany(CriterionCategory::class, 'criterion_id');
    }

    public function label(): string
    {
        return $this->t('name') ?: $this->code;
    }
}
