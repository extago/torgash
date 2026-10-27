<?php

namespace Torgash\Options\Models;

use App\Core\Support\HasCode;
use App\Core\Support\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Torgash\Catalog\Models\Product;

/**
 * One question asked about a product (PLAN §33.4).
 *
 * `type` is only HOW it is asked; what it means is in the numbers beside it
 * (`required`, the select and quantity bounds, `per_unit`). A theme may draw a
 * `select` as a list and a `radio` as a dropdown without changing a single
 * answer the shop is storing.
 */
class OptionGroup extends Model
{
    use HasCode, HasTranslations;

    /**
     * One of, one of as a list, any of, a quantity each, rows you add, two the
     * customer types and one they upload.
     */
    public const TYPES = ['select', 'radio', 'checkboxes', 'quantities', 'rows', 'text', 'textarea', 'file'];

    /** The types that take at most one answer — where "required" means "pick one". */
    public const SINGLE = ['select', 'radio'];

    /** The types where each chosen answer carries a quantity of its own. */
    public const COUNTED = ['quantities', 'rows'];

    /** The types the customer WRITES rather than picks. */
    public const TYPED = ['text', 'textarea'];

    /**
     * The type whose answer is BYTES — a layout, a logo, artwork.
     *
     * Its own constant rather than a third member of TYPED, because the two
     * differ everywhere it matters: what is stored (a token, not the words),
     * what the shop limits (a size and a list of formats, not a length), and
     * what a refusal costs (a file input cannot be re-filled by the browser).
     */
    public const UPLOADED = ['file'];

    protected $table = 'm_options_groups';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'hint' => 'array',
            'required' => 'bool',
            'per_unit' => 'bool',
            'enabled' => 'bool',
            'qty_steps' => 'array',
            'rules' => 'array',
            'depends_on_choices' => 'array',
            'min_select' => 'int',
            'max_select' => 'int',
            'min_qty' => 'int',
            'max_qty' => 'int',
            'max_length' => 'int',
            'sort' => 'int',
        ];
    }

    /**
     * How each type reads on screen. In the model rather than in a view
     * because three screens name them — the list, the form and the product's
     * own tab — and three copies would drift.
     *
     * @return array<string, string>
     */
    public static function typeLabels(): array
    {
        return [
            'select' => __('One of, as a dropdown'),
            'radio' => __('One of, as a list'),
            'checkboxes' => __('Any of, tick boxes'),
            'quantities' => __('A quantity for each choice'),
            'rows' => __('Rows you add, each with a quantity'),
            'text' => __('The customer types a line'),
            'textarea' => __('The customer types a paragraph'),
            'file' => __('The customer uploads a file'),
        ];
    }

    public function typeLabel(): string
    {
        return static::typeLabels()[$this->type] ?? $this->type;
    }

    public function choices(): HasMany
    {
        return $this->hasMany(OptionChoice::class, 'group_id')->orderBy('sort')->orderBy('id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function isSingle(): bool
    {
        return in_array($this->type, self::SINGLE, true);
    }

    public function isCounted(): bool
    {
        return in_array($this->type, self::COUNTED, true);
    }

    /**
     * Is this an option the customer WRITES?
     *
     * What it costs is still a choice with a price rule — the first one the
     * group holds — so an engraving is priced exactly as a licence is, and a
     * group with no choice at all is a free note to the workshop.
     */
    public function isTyped(): bool
    {
        return in_array($this->type, self::TYPED, true);
    }

    /** The choice a typed option is charged by, if any. */
    public function priceChoice(): ?OptionChoice
    {
        return $this->choices->first(fn (OptionChoice $choice) => $choice->enabled);
    }

    /** Is this an option the customer UPLOADS? */
    public function isFile(): bool
    {
        return in_array($this->type, self::UPLOADED, true);
    }

    /** A shared group is one nobody owns — it goes where its rules say. */
    public function isShared(): bool
    {
        return $this->product_id === null;
    }

    /** Is this question only asked once another has been answered? */
    public function isDependent(): bool
    {
        return $this->depends_on_group_id !== null;
    }

    /**
     * Does an answer to the group this one hangs off satisfy it?
     *
     * `depends_on_choices` empty means any answer will do; naming choices
     * narrows it to those. A choice id that no longer exists simply fails to
     * match — the same answer an unknown characteristic value already gets in
     * the catalogue's filters, and the safe one: a dependency naming a deleted
     * choice asks less, never more.
     *
     * @param  list<array<string, mixed>>  $entries  what was answered there
     */
    public function satisfiedBy(array $entries): bool
    {
        if ($entries === []) {
            return false;
        }

        $wanted = array_map('intval', (array) $this->depends_on_choices);

        if ($wanted === []) {
            return true;
        }

        foreach ($entries as $entry) {
            if (in_array((int) ($entry['c'] ?? 0), $wanted, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * How many of one choice may be taken. The group's own ceiling unless the
     * choice sets a lower one — a shop with two power-supply bays says so on
     * the bay, not on the question.
     */
    public function qtyCeiling(?OptionChoice $choice = null): int
    {
        $ceilings = array_filter([$this->max_qty, $choice?->max_qty]);

        return $ceilings === [] ? 99 : (int) min($ceilings);
    }
}
