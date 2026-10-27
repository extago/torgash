<?php

namespace Torgash\Taxes\Models;

use App\Core\Support\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tax extends Model
{
    use HasTranslations;

    public const PERCENT = 'percent';

    public const FIXED = 'fixed';

    protected $table = 'm_taxes_taxes';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'rate_percent' => 'float',
            'rate_amount' => 'int',
            'price_includes_tax' => 'bool',
            'applies_by_default' => 'bool',
            'applies_to_shipping' => 'bool',
            'only_listed' => 'bool',
            'enabled' => 'bool',
            'priority' => 'int',
        ];
    }

    /** Where this tax charges something other than its own rate. */
    public function rates(): HasMany
    {
        return $this->hasMany(TaxRate::class)->orderBy('position')->orderBy('id');
    }

    /**
     * The name a customer reads — with the rate that applies HERE.
     *
     * Shops name a tax with its rate in it ("VAT 20%", the common habit, and
     * this install's own rows). A copy placed by Destinations at another rate
     * would then print "incl. VAT 20%" over a 0% export, so when the copy's
     * percent differs from the tax's own, the old figure in the name is
     * swapped for the new one — or the new one appended when the name has no
     * figure to swap. A fixed amount is left alone: nobody names a tax after
     * one, and printing it needs a currency this has no business choosing.
     */
    public function label(): string
    {
        $name = trim((string) $this->t('name'));

        if ($this->rate_type !== self::PERCENT || ! $this->isDirty('rate_percent')) {
            return $name;
        }

        $own = self::percentLabel((float) $this->getOriginal('rate_percent'));
        $here = self::percentLabel((float) $this->rate_percent);

        if ($own === $here) {
            return $name;
        }

        return str_contains($name, $own) ? str_replace($own, $here, $name) : trim($name.' '.$here);
    }

    public static function percentLabel(float $percent): string
    {
        return rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.').'%';
    }

    /**
     * "20%" or a formatted amount, for the two ADMIN screens that show it — the
     * tax list and the field this module adds to the product form.
     *
     * admin_price(), not format_price(): the second reads the STOREFRONT's
     * session currency, which the admin shares with the shop, so an owner who
     * had been walking their own shop in roubles found a fixed tax quoted in
     * roubles — a number nobody chose, and one the admin's own "show amounts
     * in" switcher could not correct.
     */
    public function rateLabel(): string
    {
        return $this->rate_type === self::FIXED
            ? admin_price((int) $this->rate_amount)
            : self::percentLabel((float) $this->rate_percent);
    }
}
