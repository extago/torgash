<?php

namespace Torgash\Kits\Invariants;

use Illuminate\Support\Facades\DB;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Selections\Sources;
use Torgash\Catalog\Stock\AvailabilityStatuses;
use Torgash\Kits\Models\Kit;
use Torgash\Kits\Models\KitSlot;
use Torgash\Kits\Offer;

/**
 * What must be true about a kit, said by the module that owns it.
 *
 * **A kit breaks silently, which is the whole reason this exists.** Everything
 * about it is written to degrade quietly: a slot with nothing to put in it is
 * left out of the card, and a kit that cannot be assembled at all draws
 * NOTHING — no error, no gap, no line in a log. That is the right behaviour on
 * a page a customer is reading, and it means a shop can lose an upsell in
 * March and find out in September. The product it named was disabled, the
 * source it drew from was a category that has been renamed, the characteristic
 * it matched on was deleted — three ordinary afternoons in the admin, none of
 * which mentions kits.
 *
 * Two statements, and the second is the one the new "show only the whole set"
 * tick makes necessary: turning it on is exactly a decision to hide the card
 * rather than show half of it, so the shop has to be told when it fires.
 *
 * Bounded like every invariant: the engine's limit counts KITS looked at, and
 * each kit costs one query per slot at worst. There are tens of kits in a shop,
 * not thousands — a kit is written by hand.
 *
 * Answered lazily through `guard.invariants`; nothing here depends on the guard
 * being installed.
 */
class KitInvariants
{
    /** @return array<string, array<string, mixed>> */
    public function register(): array
    {
        return [
            'kits.slots_stand_for_something' => [
                'label' => 'Every item of every kit has goods behind it',
                'explain' => 'An item with nothing to put in it is left out of the card and the rest of the set is shown at the rest of the price, so nothing on the page says anything is wrong. Open the kit: the product it names has been disabled or deleted, the set it draws from no longer stands for anything, or the characteristic it matches on is gone.',
                'check' => fn (int $limit) => $this->brokenSlots($limit),
            ],

            'kits.kits_reach_their_page' => [
                'label' => 'Every kit written for a product is actually shown on it',
                'explain' => 'The kit is enabled and inside its dates, and its own product page still draws nothing: the main product cannot be bought, a required item cannot be filled, or "show only the whole set" is on and one of the goods is missing. Fix the item, or untick the whole-set rule and the rest of the set will be offered again. Kits placed by conditions are not checked here — which page they land on depends on the visitor.',
                'check' => fn (int $limit) => $this->unreachableKits($limit),
            ],
        ];
    }

    /**
     * Slots with nothing behind them, over the kits that are on offer today.
     *
     * A kit that is switched off or out of its dates is not examined: it is
     * not making a promise, and telling a shop that last December's set is
     * broken is the kind of noise that gets a screen ignored.
     *
     * One finding per SLOT, because that is what gets fixed — and its id is
     * the slot's, so putting it right makes the row disappear by itself.
     *
     * @return list<array<string, mixed>>
     */
    protected function brokenSlots(int $limit): array
    {
        $found = [];

        foreach ($this->currentKits($limit) as $kit) {
            foreach ($kit->slots as $slot) {
                if (! $slot->enabled) {
                    continue;
                }

                $why = $this->whySlotIsEmpty($slot);

                if ($why === null) {
                    continue;
                }

                $found[] = [
                    'id' => 'slot'.$slot->id,
                    'title' => $kit->admin_name ?: $kit->t('name'),
                    'detail' => $why,
                    'url' => route('admin.kits.edit', $kit->id),
                ];

                if (count($found) >= $limit) {
                    return $found;
                }
            }
        }

        return $found;
    }

    /**
     * Why this slot offers nothing at all — as a sentence the shop can act on,
     * or null when it is fine.
     *
     * Each answer is the same question `Offer` asks while drawing the card,
     * read the other way round: there it is "what fills this", here it is "and
     * if nothing does, what is the reason". Deliberately NOT a call into
     * `Offer::candidates()`, because that one needs a main product and half of
     * these kits have none — what is checked here is what is true of the slot
     * whatever product it is asked about.
     *
     * @return array{0: string, 1: array<string, mixed>}|null a translation key and its params
     */
    protected function whySlotIsEmpty(KitSlot $slot): ?array
    {
        // A characteristic that has been deleted takes the slot with it: the
        // main product can no longer answer it, and `Offer::compatible()`
        // fails closed, which is right and invisible.
        foreach ($slot->matchFeatureIds() as $featureId) {
            if (! DB::table('m_catalog_features')->where('id', $featureId)->exists()) {
                return ['The characteristic it matches on has been deleted.', []];
            }
        }

        if (! $slot->isSet()) {
            $product = Product::visible()->find($slot->product_id);

            if (! $product) {
                return ['The product it names is disabled or deleted.', []];
            }

            // `visible()` and not `listed()`: this runs on a timer with no
            // visitor, and `listed()` would narrow by the assortment of a
            // pickup point nobody has chosen.
            return $product->isOrderable()
                ? null
                : ['":product" cannot be bought right now.', ['product' => $product->t('name') ?: $product->sku]];
        }

        $resolved = app(Sources::class)->resolve((string) $slot->source);

        if (! $resolved) {
            return ['The set it draws from no longer stands for anything.', []];
        }

        [$definition, $arg] = $resolved;
        $query = ($definition['apply'])(Product::visible(), $arg, []);
        app(AvailabilityStatuses::class)->applyOrderable($query);

        return $query->exists()
            ? null
            : ['Nothing in the set it draws from can be bought.', []];
    }

    /**
     * Kits with a main product of their own that draw nothing on its page.
     *
     * Asked of `Offer` itself rather than reasoned about, because `Offer` is
     * the one place that decides what a card is — a second opinion here would
     * be a second answer to "is this kit shown", and the two would drift.
     *
     * Only kits that belong to ONE product. A shared kit is placed by display
     * rules, and which products it lands on is not knowable without walking
     * the catalogue; saying "probably broken" is how a screen of statements
     * turns into a screen of guesses.
     *
     * @return list<array<string, mixed>>
     */
    protected function unreachableKits(int $limit): array
    {
        $offer = app(Offer::class);
        $found = [];

        foreach ($this->currentKits($limit) as $kit) {
            if ($kit->isShared()) {
                continue;
            }

            $main = Product::visible()->withCardData()->find($kit->main_product_id);

            if (! $main) {
                $found[] = $this->unreachable($kit, ['Its main product is disabled or deleted.', []]);

                continue;
            }

            if ($offer->for($kit, $main)) {
                continue;
            }

            $found[] = $this->unreachable($kit, $main->isOrderable()
                ? ['Nothing can be put in it on ":product".', ['product' => $main->t('name') ?: $main->sku]]
                : ['":product" cannot be bought right now, so the kit is not offered.', ['product' => $main->t('name') ?: $main->sku]]);

            if (count($found) >= $limit) {
                break;
            }
        }

        return $found;
    }

    /**
     * @param  array{0: string, 1: array<string, mixed>}  $why
     * @return array<string, mixed>
     */
    protected function unreachable(Kit $kit, array $why): array
    {
        return [
            'id' => 'kit'.$kit->id,
            'title' => $kit->admin_name ?: $kit->t('name'),
            'detail' => $why,
            'url' => route('admin.kits.edit', $kit->id),
        ];
    }

    /**
     * The kits on offer today, in the order a shop reads them.
     *
     * Not `Kits::current()`: that is a per-request memo for a page that is
     * drawing cards, and this runs from a scheduler where nothing else has
     * asked. Reading it here would also leave the memo holding a whole run's
     * worth of kits for whatever comes next in the same process.
     *
     * @return \Illuminate\Support\Collection<int, Kit>
     */
    protected function currentKits(int $limit): \Illuminate\Support\Collection
    {
        return Kit::with('slots')
            ->where('enabled', true)
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->filter(fn (Kit $kit) => $kit->isCurrent())
            ->values();
    }
}
