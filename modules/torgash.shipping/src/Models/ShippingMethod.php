<?php

namespace Torgash\Shipping\Models;

use App\Core\Support\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Torgash\Checkout\Shipping\Package;

/**
 * A way to deliver. It carries no price of its own: what it costs, and whether
 * it is offered at all, is a property of the pair (method, zone) — see
 * ShippingRate — unless `provider` names a carrier, and then that carrier
 * prices it live from `provider_config` (its variant: to branch, to locker,
 * by courier).
 */
class ShippingMethod extends Model implements HasMedia
{
    use HasTranslations, InteractsWithMedia;

    /**
     * How the price is arrived at. See the price-modes migration for why this
     * and ON_INCALCULABLE are two lists rather than one.
     */
    public const MODES = [
        'free' => 'Always free',
        'bands' => 'By the rate in the zone',
        'carrier' => 'Asked of the carrier',
        'quote' => 'Quoted by a human',
    ];

    /** What to do when the chosen mode cannot produce a number. */
    public const ON_INCALCULABLE = [
        'quote' => 'Offer it, quoted by a human',
        'bands' => 'Fall back to the rate in the zone',
        'hide' => 'Do not offer this method',
    ];

    /** Which weight the weight bands are read with — see the volumetric migration. */
    public const CHARGE_ON = [
        'actual' => 'What it weighs',
        'volumetric' => 'What the box measures',
        'chargeable' => 'Whichever is greater',
    ];

    protected $table = 'm_shipping_methods';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['name' => 'array', 'enabled' => 'bool', 'needs_address' => 'bool', 'provider_config' => 'array'];
    }

    /**
     * The carrier's mark, shown beside the method wherever a customer chooses
     * one. A single file with no conversions: it is rendered at one small size
     * and a shop uploads an already-small PNG or SVG — running it through the
     * thumbnail pipeline would cost work and lose the transparency that makes a
     * logo sit on any background.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('logo')->singleFile();
    }

    public function logoUrl(): ?string
    {
        return $this->getFirstMediaUrl('logo') ?: null;
    }

    public function rates(): HasMany
    {
        return $this->hasMany(ShippingRate::class, 'shipping_method_id');
    }

    /** What a cart must satisfy before this method is offered at all. */
    public function conditions(): HasMany
    {
        return $this->hasMany(MethodCondition::class, 'shipping_method_id')->orderBy('position')->orderBy('id');
    }

    /**
     * The weight this method's bands are read with, in grams.
     *
     * Volumetric weight is (L × W × H in cm) ÷ divisor — the one formula every
     * carrier uses, with only the divisor differing (4000 for Nova Poshta and
     * DHL, 5000 on some international tariffs). The cart hands over the goods'
     * own volume; the packing factor accounts for the air a real carton adds
     * around them, and is a setting because a shop that ships in its own
     * moulded boxes packs tighter than one shipping loose in cartons.
     *
     * "Chargeable" is what carriers actually bill: the greater of the two. A
     * pillow is priced by its box, a battery by its mass, and the same method
     * has to do both without the admin choosing per order.
     */
    public function billableWeight(Package $package): int
    {
        return match ($this->charge_on) {
            'volumetric' => $this->volumetricWeight($package),
            'chargeable' => max($package->weight, $this->volumetricWeight($package)),
            default => $package->weight,
        };
    }

    /** Grams, from cubic centimetres. */
    public function volumetricWeight(Package $package): int
    {
        $divisor = max(1, (int) ($this->volumetric_divisor ?: 4000));
        $factor = (float) setting('torgash.shipping', 'packing_factor', 1.15);

        // volume(cm³) × factor ÷ divisor = kilograms, and grams from there.
        return (int) round($package->volume * max(1.0, $factor) / $divisor * 1000);
    }

    /**
     * Which measurement this method needs but the cart does not have, if any —
     * the reason code an unpriceable option carries (PLAN §12.4).
     */
    public function missingMeasurement(Package $package): ?string
    {
        return match ($this->charge_on) {
            'volumetric' => $package->volumeKnown ? null : 'dimensions_missing',
            'chargeable' => match (true) {
                // Either input missing understates the greater-of, so both are
                // required rather than "whatever we happen to have".
                ! $package->weightKnown => 'weight_missing',
                ! $package->volumeKnown => 'dimensions_missing',
                default => null,
            },
            default => $package->weightKnown ? null : 'weight_missing',
        };
    }
}
