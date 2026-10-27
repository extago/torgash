<?php

namespace Torgash\Menus\Models;

use App\Core\Support\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Menu extends Model
{
    use HasTranslations;

    protected $table = 'm_menus';

    protected $guarded = [];

    /** A change to it can be put back from the audit trail (Torgash\Guard\Audit\Revert): it is content, saved by its own model. */
    public static function auditRevertible(): bool
    {
        return true;
    }

    protected function casts(): array
    {
        return ['name' => 'array', 'enabled' => 'bool'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class)->orderBy('sort')->orderBy('id');
    }

    /** Root items only; children hang off them via MenuItem::children(). */
    public function rootItems(): HasMany
    {
        return $this->items()->whereNull('parent_id');
    }
}
