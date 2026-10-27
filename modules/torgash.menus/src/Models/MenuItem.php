<?php

namespace Torgash\Menus\Models;

use App\Core\Display\DisplayRules;
use App\Core\Support\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Torgash\Catalog\Models\Category;

/**
 * One row of a menu. WHAT it is comes from its `kind` (Torgash\Menus\ItemKinds);
 * what it looks like on the page is decided by whoever draws the menu. The row
 * holds only what the shop typed — the storefront reads nodes from
 * Torgash\Menus\MenuBuilder, never this model.
 */
class MenuItem extends Model
{
    use HasTranslations;

    protected $table = 'm_menu_items';

    protected $guarded = [];

    public const BADGE_STYLES = ['primary', 'accent', 'error', 'success', 'neutral'];

    public const SHOW_ON = ['all', 'desktop', 'mobile'];

    protected $attributes = [
        'kind' => 'link',
        'show_on' => 'all',
        'rules_match' => DisplayRules::MATCH_ALL,
    ];

    /** A change to it can be put back from the audit trail (Torgash\Guard\Audit\Revert): it is content, saved by its own model. */
    public static function auditRevertible(): bool
    {
        return true;
    }

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'badge' => 'array',
            'note' => 'array',
            'rules' => 'array',
            'options' => 'array',
            'new_tab' => 'bool',
            'enabled' => 'bool',
            'highlight' => 'bool',
        ];
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort')->orderBy('id');
    }

    public function sourceCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'source_category_id');
    }

    /** One of the kind's own settings, with its default. */
    public function option(string $key, mixed $default = null): mixed
    {
        return ($this->options ?? [])[$key] ?? $default;
    }

    /** The name the shop typed, in the current language — '' when it typed none. */
    public function typedName(?string $locale = null): string
    {
        return trim((string) ($this->t('name', $locale) ?? ''));
    }

    /** What the admin list calls the row, whatever the kind. */
    public function adminLabel(): string
    {
        $locale = admin_content_locale();
        $typed = $this->typedName($locale);

        if ($typed !== '') {
            return $typed;
        }

        return app(\Torgash\Menus\ItemKinds::class)->describe($this, $locale);
    }
}
