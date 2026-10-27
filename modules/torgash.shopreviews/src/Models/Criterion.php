<?php

namespace Torgash\ShopReviews\Models;

use App\Core\Support\HasTranslations;
use Illuminate\Database\Eloquent\Model;

/**
 * One thing customers rate the shop by — "Delivery", "Service".
 *
 * @property array<string, string> $name
 */
class Criterion extends Model
{
    use HasTranslations;

    protected $table = 'm_shopreviews_criteria';

    protected $fillable = ['code', 'name', 'sort', 'enabled'];

    public static function auditLabel(): string
    {
        return 'Shop review criterion';
    }

    protected function casts(): array
    {
        return ['name' => 'array', 'enabled' => 'bool', 'sort' => 'int'];
    }

    public function label(): string
    {
        return $this->t('name') ?: $this->code;
    }
}
