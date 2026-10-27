<?php

namespace Torgash\Kits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Torgash\Cart\Lines;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Selections\Sources;
use Torgash\Catalog\Sorting\Sorts;
use Torgash\Catalog\Stock\AvailabilityStatuses;
use Torgash\Kits\Models\Kit;
use Torgash\Kits\Models\KitSlot;

/**
 * What a kit actually offers on THIS product's page (PLAN §33.5): which goods
 * fill its slots, what each costs inside it, and what the whole set comes to.
 *
 * The one place a slot's candidates are worked out, because three screens ask
 * the same question and must get the same answer: the card, the "choose
 * another" picker, and the check that runs when the customer presses Add — a
 * product that could not be offered may not be bought at the kit's price
 * either, which is the same rule the options module's sets live by.
 *
 * COMPATIBILITY is a characteristic, not a table of pairs: a slot may say "and
 * only the products whose value of this characteristic is the main product's
 * own", which is how a glass for the model being looked at is offered without
 * anybody maintaining a list. A main product that cannot answer that
 * characteristic offers NOTHING for that slot — a glass for an unknown model is
 * the wrong glass, and failing closed is the only answer that does not sell it.
 */
class Offer
{
    public function __construct(protected Kits $kits) {}

    /**
     * The card's own data for one kit on one product.
     *
     * @param  array<int, int>  $chosen  slot id => product id, when the customer has swapped something
     * @param  list<int>|null  $ticked  which slots are being bought; null = all of them, which is
     *                                  how the card is first drawn — an offer the customer has to
     *                                  assemble is an offer they leave
     * @return array{kit: Kit, main: Product, main_price: int, slots: list<array<string, mixed>>, full_total: int, kit_total: int, saving: int}|null
     */
    public function for(Kit $kit, Product $main, array $chosen = [], ?array $ticked = null, array $config = []): ?array
    {
        if (! $kit->isCurrent() || ! $main->isOrderable()) {
            return null;
        }

        // The main product AS CONFIGURED on the page the card is drawn on.
        //
        // A kit is shown under a product the customer may have just answered
        // questions about — an engraving, a processor — and pressing "add the
        // set" buys that product, not a bare one. Two things follow, and the
        // second is why this is not merely a nicety: the price quoted here has
        // to be the price charged, or the card lies; and a product whose
        // questions are REQUIRED cannot be bought without them at all, so
        // before this the whole kit was silently refused and nothing reached
        // the basket (measured).
        //
        // Priced through cart's own seam, so this module still knows nothing
        // about what a configuration IS — only that whoever understands it
        // says what it adds.
        // As the page spells it, then the one spelling the parts seam reads.
        $config = Lines::spelled($main, $config);
        $parts = Lines::parts($main, 1, $config);
        $money = Lines::money((int) $main->effectivePrice(), $parts, 1);
        $mainPrice = $money['unit_price'] + $money['extra_total'];
        $slots = [];
        $taken = [];
        $fullTotal = $mainPrice;
        $kitTotal = $mainPrice;

        foreach ($kit->slots as $slot) {
            if (! $slot->enabled) {
                continue;
            }

            // Unticked, and not one of the ones the kit cannot do without:
            // the customer said they do not want it, so it is not part of what
            // is being bought or of what it costs.
            if ($ticked !== null && ! $slot->required && ! in_array((int) $slot->id, array_map('intval', $ticked), true)) {
                continue;
            }

            // …and not what an earlier slot of the same kit already holds: two
            // slots drawn from one set would otherwise both take its first
            // product, and the card would offer the same glass twice (measured
            // on the live page).
            $product = $this->productFor($slot, $main, $chosen[$slot->id] ?? null, $taken);

            if (! $product) {
                // Nothing fits this slot for this product — no glass for this
                // model, the chosen one sold out. A slot that cannot be filled
                // is not drawn, and a REQUIRED one takes the kit with it: half
                // a kit at the kit's price is an offer nobody made.
                //
                // `whole_set_only` says the same thing about EVERY slot, and
                // it is a different question from `required`: that one is
                // about the customer (they may not untick it), this one about
                // the shop (do not advertise a set that cannot be assembled).
                // A card headed "Everything for the kitchen" showing two of
                // its four items is an offer nobody made either.
                if ($slot->required || $kit->whole_set_only) {
                    return null;
                }

                continue;
            }

            $taken[] = (int) $product->id;
            $full = (int) $product->effectivePrice() * max(1, $slot->qty);
            // Only an EXTENDED kit may give money away: a simple one puts
            // ordinary lines in the basket, and an ordinary line at less than
            // its price is the hole this split closed. Enforced here as well
            // as in the form, because a kit that WAS extended and has been
            // changed back still carries its slots' discount columns.
            $price = $kit->isExtended()
                ? $slot->priceFor((int) $product->effectivePrice()) * max(1, $slot->qty)
                : $full;

            $slots[] = [
                'slot' => $slot,
                'product' => $product,
                'qty' => max(1, (int) $slot->qty),
                'full' => $full,
                'price' => $price,
                'cut' => $full - $price,
                // Drawn ticked: the card is an offer, and an offer the customer
                // has to assemble is one they leave. A required slot has no tick
                // at all — it is what the kit IS.
                'required' => (bool) $slot->required,
                'swappable' => (bool) $slot->swappable && $slot->isSet(),
            ];

            $fullTotal += $full;
            $kitTotal += $price;
        }

        if ($slots === []) {
            return null;
        }

        return [
            'kit' => $kit,
            'main' => $main,
            'main_price' => $mainPrice,
            // What was inherited, in the customer's own words, so the card can
            // SAY what it is about to buy rather than quietly charging for it.
            'main_parts' => $parts,
            'config' => $config,
            'slots' => $slots,
            'full_total' => $fullTotal,
            'kit_total' => $kitTotal,
            'saving' => $fullTotal - $kitTotal,
        ];
    }

    /**
     * Which product fills a slot: the one the customer picked, the slot's own,
     * or the first of its set that no other slot has taken. Null when nothing
     * fits — see for().
     *
     * A product another slot already holds is never offered here, whether it
     * was the default or the customer's own pick: a set that sells two of the
     * same case is not the set anybody described, and one of the two lines
     * would look like a mistake in the basket.
     *
     * @param  list<int>  $taken  products the kit's earlier slots already hold
     */
    public function productFor(KitSlot $slot, Product $main, ?int $chosenId = null, array $taken = []): ?Product
    {
        $candidates = $this->candidates($slot, $main);

        if ($chosenId && ! in_array((int) $chosenId, $taken, true)) {
            $chosen = $candidates->firstWhere('id', $chosenId);

            if ($chosen) {
                return $chosen;
            }

            // A product that is not on offer here is not bought here either:
            // falling back to the default would quietly sell something else,
            // and refusing outright would turn a stale link into a dead card.
        }

        return $candidates->first(fn (Product $product) => ! in_array((int) $product->id, $taken, true));
    }

    /**
     * What may fill this slot, narrowed by compatibility and (for the picker)
     * by what was typed.
     *
     * @return Collection<int, Product>
     */
    public function candidates(KitSlot $slot, Product $main, string $search = '', int $limit = 24, array $taken = []): Collection
    {
        $query = $this->query($slot, $main);

        if (! $query) {
            return collect();
        }

        if ($taken !== []) {
            $query->whereNotIn('m_catalog_products.id', array_map('intval', $taken));
        }

        if ($search !== '') {
            $query->where(fn (Builder $q) => $q
                ->where('sku', 'ilike', '%'.$search.'%')
                ->orWhereRaw('exists (select 1 from jsonb_each_text(name) as t(k, v) where t.v ilike ?)', ['%'.$search.'%']));
        }

        // Sold out is not an offer, and it is said in SQL rather than to a
        // loaded page: the list is LIMITED, so filtering afterwards answers
        // with however many of the first two dozen happened to be in stock —
        // which on a thin catalogue is none at all. The rule
        // `Filters\Flags` already states.
        app(AvailabilityStatuses::class)->applyOrderable($query);

        // In the shop's own order, whatever it has chosen — never a
        // hard-coded one. A picker that ignored the setting would be the
        // `->latest()` the catalogue's sorts were written to abolish, and
        // `Sorts::applyKey` closes every ordering with `id`, which matters
        // here: accessories tie on price constantly.
        $sorts = app(Sorts::class);
        $sorts->applyKey($query, $sorts->default());

        return $query->withCardData()->limit($limit)->get();
    }

    /**
     * What to call one variation of a model, in a list of its siblings: the
     * values of whatever actually differs between them ("M", "M / black"),
     * and the product's own name when nothing does.
     *
     * Named here rather than in the view because the picker and the slot both
     * print it, and a size that reads one way in the window and another on the
     * card is two answers to "what did I choose".
     *
     * @param  array{axes: list<array{feature: mixed}>, values: array<int, array<int, array{label: string}>>}  $variations
     *         as Support\Variations answers for the model
     */
    public static function variationLabel(Product $product, array $variations): string
    {
        $words = [];

        foreach ($variations['axes'] as $axis) {
            $label = $variations['values'][$product->id][$axis['feature']->id]['label'] ?? null;
            if ($label !== null && $label !== '') {
                $words[] = $label;
            }
        }

        return $words === [] ? (string) $product->t('name') : implode(' / ', $words);
    }

    /**
     * The same set, but one entry per MODEL rather than per variation.
     *
     * A t-shirt in six sizes is six products in this engine (a variation is a
     * separate product sharing a `variation_group`), and a picker that listed
     * them as six cards would be a wall of the same photograph. So the picker
     * shows the model once and lets the customer choose the size inside the
     * card — which is also the only place they CAN choose it, the kit card
     * itself having no room for it.
     *
     * Grouped after the query rather than in it: `variation_group` is
     * nullable and most products have none, so "one row per group" is not a
     * GROUP BY anybody would want to write, and the page is two dozen rows.
     * The limit is applied to the GROUPS, so a picker of six models shows six
     * whatever their sizes.
     *
     * @return Collection<int, Collection<int, Product>>
     */
    public function candidateGroups(KitSlot $slot, Product $main, string $search = '', int $limit = 24, array $taken = []): Collection
    {
        return $this->candidates($slot, $main, $search, $limit * 8, $taken)
            ->groupBy(fn (Product $product) => $product->variation_group ?: 'p'.$product->id)
            ->take($limit);
    }

    /** The slot's set as a query, or null when it stands for nothing. */
    protected function query(KitSlot $slot, Product $main): ?Builder
    {
        if (! $slot->isSet()) {
            return $slot->product_id
                ? Product::listed()->whereKey($slot->product_id)
                : null;
        }

        $resolved = app(Sources::class)->resolve((string) $slot->source);

        if (! $resolved) {
            return null;
        }

        [$definition, $arg] = $resolved;
        $query = ($definition['apply'])(Product::listed(), $arg, []);

        // Never the main product itself: "buy this phone with this phone" is
        // not an offer, and a source as wide as a category happily contains it.
        $query->whereKeyNot($main->id);

        return $slot->matchFeatureIds() !== [] ? $this->compatible($query, $slot, $main) : $query;
    }

    /**
     * "…and only what fits the main product": the same value of EVERY
     * characteristic the slot names.
     *
     * All of them, never any: an outfit is the same colour AND the same
     * fabric, and "or" describes a wider set than the person picking it
     * means. The narrow reading is also the one a shop can widen, by deleting
     * a condition.
     *
     * Fails closed per condition: a main product that cannot answer one of
     * them fits NOTHING here, rather than quietly being matched on the rest.
     * A jacket with no fabric recorded is a jacket nobody can pick trousers
     * for, and offering every colour instead would be the engine inventing an
     * answer the catalogue does not hold.
     */
    protected function compatible(Builder $query, KitSlot $slot, Product $main): Builder
    {
        foreach ($slot->matchFeatureIds() as $featureId) {
            $wanted = DB::table('m_catalog_product_features')
                ->where('product_id', $main->id)
                ->where('feature_id', $featureId)
                ->get(['feature_value_id', 'value_text']);

            $valueIds = $wanted->pluck('feature_value_id')->filter()->map('intval')->all();
            $texts = $wanted->pluck('value_text')->filter()->map('strval')->all();

            if ($valueIds === [] && $texts === []) {
                return $query->whereRaw('1 = 0');
            }

            $query->whereExists(fn ($sub) => $sub
                ->from('m_catalog_product_features as kf'.$featureId)
                ->whereColumn('kf'.$featureId.'.product_id', 'm_catalog_products.id')
                ->where('kf'.$featureId.'.feature_id', $featureId)
                ->where(function ($either) use ($valueIds, $texts, $featureId) {
                    if ($valueIds !== []) {
                        $either->orWhereIn('kf'.$featureId.'.feature_value_id', $valueIds);
                    }

                    if ($texts !== []) {
                        $either->orWhereIn('kf'.$featureId.'.value_text', $texts);
                    }
                }));
        }

        return $query;
    }
}
