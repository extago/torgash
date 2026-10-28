<?php

namespace Torgash\Catalog\Models;

use App\Core\Seo\HasPageMeta;
use App\Core\Seo\PageSubject;
use App\Core\Support\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Torgash\Catalog\Support\KeepsAddress;

class Category extends Model implements PageSubject
{
    use HasPageMeta, HasTranslations, KeepsAddress, SoftDeletes;

    public function addressPath(string $slug): string
    {
        return '/catalog/'.$slug;
    }

    public const PAGE_META_KIND = 'category';

    protected $table = 'm_catalog_categories';

    protected $guarded = [];

    /** @see the add_status_to_catalog_categories migration for what each state means. */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_HIDDEN = 'hidden';

    public const STATUS_DISABLED = 'disabled';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_HIDDEN, self::STATUS_DISABLED];

    /**
     * The DB default, mirrored: Postgres does not hand a column default back
     * after an INSERT, so a freshly created row would otherwise read NULL —
     * and a NULL status is neither listed nor reachable.
     */
    protected $attributes = ['status' => self::STATUS_ACTIVE];

    /** A change to it can be put back from the audit trail (Torgash\Guard\Audit\Revert): it is content, saved by its own model. */
    public static function auditRevertible(): bool
    {
        return true;
    }

    /** @return array<string, string> status => its admin label */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_ACTIVE => __('Active'),
            self::STATUS_HIDDEN => __('Hidden'),
            self::STATUS_DISABLED => __('Disabled'),
        ];
    }

    public function statusLabel(): string
    {
        return self::statusLabels()[$this->status] ?? self::statusLabels()[self::STATUS_DISABLED];
    }

    /** In the menus, the tree, the sitemap: what a browsing customer is SHOWN. */
    public function isListed(): bool
    {
        // A category closed to this visitor's group (PLAN §46) is not in their
        // menus, trees or suggestions — whatever the shop's setting says about
        // opening its address.
        return $this->status === self::STATUS_ACTIVE && app(\Torgash\Catalog\Support\Assortment::class)->categoryOpen($this);
    }

    /** Opens by its address — active or hidden. The one thing `hidden` keeps. */
    public function isReachable(): bool
    {
        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_HIDDEN], true);
    }

    public function scopeListed(Builder $query): Builder
    {
        $closed = app(\Torgash\Catalog\Support\Assortment::class)->closedCategoryIds();

        return $query->where('status', self::STATUS_ACTIVE)
            ->when($closed !== [], fn ($q) => $q->whereNotIn($q->getModel()->getTable().'.id', $closed));
    }

    public function scopeReachable(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_HIDDEN]);
    }

    /**
     * The boolean this column replaced, kept for code written before it (a
     * module, an import file, a test). Writing it is the old meaning exactly —
     * on or off. Reading it answers "listed", the stricter of the two, so old
     * code that built a menu from it cannot put a hidden category in one; the
     * price is that such code 404s a hidden page, which fails closed.
     * Nothing in this engine reads it — ask isListed() / isReachable().
     */
    protected function enabled(): Attribute
    {
        return Attribute::make(
            get: fn ($value, array $attributes) => ($attributes['status'] ?? self::STATUS_ACTIVE) === self::STATUS_ACTIVE,
            set: fn ($value) => ['status' => filter_var($value, FILTER_VALIDATE_BOOL) ? self::STATUS_ACTIVE : self::STATUS_DISABLED],
        );
    }

    protected function casts(): array
    {
        return [
            'customer_groups' => 'array',
            'name' => 'array',
            'description' => 'array',
            'meta_title' => 'array',
            'meta_description' => 'array',
            'h1' => 'array',
            'noindex' => 'bool',
            'shown_in_parent' => 'bool',
        ];
    }

    /** How a category's page can show the categories under it (`subcategories_view`, theme setting `subcategories`). */
    public const SUBCATEGORY_VIEWS = ['tiles', 'buttons', 'none'];

    /**
     * How this category's page shows the categories under it: its own word,
     * else the theme's — tiles when the theme has no such setting.
     */
    public function subcategoriesView(): string
    {
        $own = (string) ($this->subcategories_view ?? '');

        if (in_array($own, self::SUBCATEGORY_VIEWS, true)) {
            return $own;
        }

        $theme = (string) theme_setting('subcategories', 'tiles');

        return in_array($theme, self::SUBCATEGORY_VIEWS, true) ? $theme : 'tiles';
    }

    /** Where a category's own icon pictures are kept on the public disk. */
    public const ICON_DIR = 'category-icons';

    /** The category's own picture for its icon, or null — a Lucide name is {@see $icon}. */
    public function iconImageUrl(): ?string
    {
        $path = (string) ($this->icon_image ?? '');

        return $path !== '' && str_starts_with($path, self::ICON_DIR.'/')
            ? \Illuminate\Support\Facades\Storage::disk('public')->url($path)
            : null;
    }

    /** Where a category's tile pictures are kept on the public disk. */
    public const TILE_DIR = 'category-tiles';

    /** The category's picture for a tile (the "Category tiles" block), or null. */
    public function tileImageUrl(): ?string
    {
        $path = (string) ($this->tile_image ?? '');

        return $path !== '' && str_starts_with($path, self::TILE_DIR.'/')
            ? \Illuminate\Support\Facades\Storage::disk('public')->url($path)
            : null;
    }

    /** The Lucide name, when it names an icon the pack has — a typo draws nothing rather than throwing. */
    public function lucideIcon(): ?string
    {
        $name = (string) ($this->icon ?? '');

        return $name !== '' && preg_match('/^[a-z0-9-]+$/', $name) && is_file(resource_path('icons/lucide/'.$name.'.svg')) ? $name : null;
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort');
    }

    /**
     * The chain from the root down to this category's parent, outermost first
     * — what a breadcrumb trail is made of. Walks `parent` rather than a
     * materialised path: the tree is a handful of levels deep and a shop edits
     * it by dragging rows about, which a stored path would have to be kept in
     * step with.
     *
     * @return list<self>
     */
    public function ancestors(): array
    {
        $chain = [];
        $cursor = $this->parent;

        while ($cursor) {
            array_unshift($chain, $cursor);
            $cursor = $cursor->parent;
        }

        return $chain;
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'm_catalog_category_product', 'category_id', 'product_id');
    }
}
