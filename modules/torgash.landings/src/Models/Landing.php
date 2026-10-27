<?php

namespace Torgash\Landings\Models;

use App\Core\Display\DisplayRules;
use App\Core\Support\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;
use Torgash\Catalog\Models\Product;

/**
 * A landing on a product's page: HTML per language, one CSS, its own files
 * (`landings/<id>/` on the public disk), where it stands and for whom.
 *
 * WHO is two answers, either of which is enough: products picked by hand
 * (`m_landing_products`, not excluded), or core's display rules — and with
 * neither, every product. A product LEFT OUT (`excluded`) never sees it,
 * whatever the rules say: that is the one-off exception a shop needs ("every
 * laptop except this one"). When and whether are on top of both: `enabled`,
 * `starts_at` / `ends_at`.
 */
class Landing extends Model
{
    use HasTranslations;

    protected $table = 'm_landings';

    protected $guarded = [];

    public const PLACEMENTS = ['before', 'instead', 'after', 'tab'];

    public const SPACES = ['none', 'small', 'large'];

    /** The folder of its files on the public disk. */
    public const FILES = 'landings';

    protected $attributes = [
        'enabled' => true,
        'placement' => 'after',
        'space_top' => 'none',
        'space_bottom' => 'none',
        'fold' => false,
        'rules_match' => DisplayRules::MATCH_ALL,
        'sort' => 0,
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'fold' => 'boolean',
            'html' => 'array',
            'tab_title' => 'array',
            'rules' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Its files go with it — nothing else refers to them.
        static::deleted(fn (Landing $landing) => Storage::disk('public')->deleteDirectory($landing->folder()));
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'm_landing_products', 'landing_id', 'product_id')->withPivot('excluded');
    }

    public function folder(): string
    {
        return self::FILES.'/'.$this->getKey();
    }

    /** Where its files are on the public disk — what its HTML calls `assets/`. A storage path, not an address. */
    public function filesDir(): string
    {
        return $this->folder().'/'.self::ASSETS;
    }

    public const ASSETS = 'assets';

    /** off / scheduled / ended / running — the list's badge. */
    public function state(): string
    {
        return match (true) {
            ! $this->enabled => 'off',
            $this->starts_at !== null && $this->starts_at->isFuture() => 'scheduled',
            $this->ends_at !== null && ! $this->ends_at->isFuture() => 'ended',
            default => 'running',
        };
    }

    public function isRunning(): bool
    {
        return $this->state() === 'running';
    }

    /**
     * For this product, leaving aside `enabled` and the dates: picked, or the
     * rules pass (no rules and nobody picked = every product); left out = never.
     *
     * @param  bool|null  $excluded  the product's row for this landing: null — none,
     *                              false — picked by hand, true — left out
     * @param  bool  $anyPicked  whether this landing has products picked by hand at all
     */
    public function appliesTo(?bool $excluded, bool $anyPicked, array $context): bool
    {
        if ($excluded !== null) {
            return ! $excluded;
        }

        $rules = $this->rules ?? [];

        if ($rules === []) {
            return ! $anyPicked;
        }

        return app(DisplayRules::class)->passes($rules, $context, $this->rules_match ?? DisplayRules::MATCH_ALL);
    }
}
