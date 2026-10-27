<?php

namespace Torgash\Kits;

use Torgash\Catalog\Models\Product;
use Torgash\Kits\Models\Kit;
use Torgash\Kits\Models\KitSlot;

/**
 * What an EXTENDED kit makes of the line it was bought as (PLAN §33.5, revised
 * with the owner 2026-09-20).
 *
 * The set is one line — the main product — and everything else in it is a PART
 * of that line (`cart.line.parts`), exactly as an option's linked product is.
 * A part that is itself a product becomes its own order line with
 * `parent_key`, so stock, 1C, a refund and a return all still deal in goods;
 * what they no longer deal in is four independent lines that only a runtime
 * check tied together.
 *
 * **The check is gone because the structure says it.** A part's quantity is
 * its own times the parent's, so one glass per phone is arithmetic rather than
 * a rule; there is no separate line to raise to a hundred, and there is no
 * "…but only while the phone is still in the basket", because removing the
 * phone removes the line the glass is part of. The previous version needed
 * three collaborating pieces for that — a negative part, a search of the
 * basket for the main product, and `auto_assemble` — and a measured hole
 * remained (a hundred glasses at half price behind one phone).
 *
 * **The discount is expressed as the part's PRICE, not as a negative.** The
 * glass costs 50 inside the set and 100 outside it; the child order line says
 * 50, which is what it really cost. A negative part beside a full-price one
 * would be the same money written as two numbers, and a return would have to
 * add them up to find out what to give back.
 *
 * A SIMPLE kit reaches none of this: it adds ordinary lines and carries no
 * discount, so there is nothing to make of them.
 */
class Discounts
{
    /** This module's key inside a cart line's configuration bag. */
    public const KEY = 'kit';

    public function __construct(protected Kits $kits, protected Offer $offer) {}

    /**
     * The parts a line gets from this module: one per slot of the extended kit
     * it was bought as.
     *
     * @param  array<string, mixed>  $config
     * @return list<array<string, mixed>>
     */
    public function parts(Product $product, int $qty, array $config): array
    {
        $bought = (array) ($config[self::KEY] ?? []);

        if ($bought === []) {
            return [];
        }

        $kit = $this->kits->find((int) ($bought['id'] ?? 0));

        // A kit that has been switched off, deleted or has run its dates out
        // sells nothing more: the line falls back to the bare product, which
        // is the same restraint the old version showed about expired kits.
        if (! $kit || ! $kit->isExtended() || ! $kit->isCurrent()) {
            return [];
        }

        $parts = [];

        foreach ((array) ($bought['slots'] ?? []) as $slotId => $productId) {
            $slot = $kit->slots->firstWhere('id', (int) $slotId);

            if (! $slot || ! $slot->enabled) {
                continue;
            }

            $chosen = $this->offer->productFor($slot, $product, (int) $productId);

            // Not the slot's own default when the customer's pick has gone:
            // the line says which product was bought, and quietly substituting
            // another is selling something nobody chose.
            if (! $chosen || (int) $chosen->id !== (int) $productId) {
                continue;
            }

            $parts[] = $this->part($kit, $slot, $chosen);
        }

        return $parts;
    }

    /**
     * One slot, as a part of the line.
     *
     * @return array<string, mixed>
     */
    protected function part(Kit $kit, KitSlot $slot, Product $product): array
    {
        $full = (int) $product->effectivePrice();

        return [
            'code' => 'kit'.$kit->id.'s'.$slot->id.'p'.$product->id,
            // What it was bought AS, which an invoice prints beside the
            // product's own name: "In the set “Starter”: Remax GL-83".
            'group' => __('In the set “:kit”', ['kit' => $kit->t('name')]),
            'label' => __('In the set “:kit”', ['kit' => $kit->t('name')]).': '.$product->t('name'),
            'qty' => max(1, (int) $slot->qty),
            // Per unit of the set: two phones bought as two sets carry two
            // glasses each. This is the whole of what used to need a check.
            'per_unit' => true,
            // The price INSIDE the set. Not a full price with a negative
            // beside it: the same money written twice is money a return has to
            // add up before it knows what to give back.
            'amount' => $slot->priceFor($full),
            'product_id' => (int) $product->id,
            'sku' => $product->sku,
            'name' => $product->t('name'),
            'weight' => (int) ($product->resolvedWeight() ?? 0),
            'url' => route('catalog.product', $product->slug),
            'image' => $product->primaryImageUrl(120),
        ];
    }

    /** For tests and for a screen that has just saved. */
    public function flush(): void
    {
        $this->kits->flush();
    }
}
