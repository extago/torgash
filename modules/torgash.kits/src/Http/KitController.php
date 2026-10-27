<?php

namespace Torgash\Kits\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Torgash\Cart\CartManager;
use Torgash\Catalog\Models\Product;
use Torgash\Kits\Discounts;
use Torgash\Kits\Kits;
use Torgash\Kits\Models\Kit;
use Torgash\Kits\Models\KitSlot;
use Torgash\Kits\Offer;

/**
 * The card's four answers (PLAN §33.5): what the set costs as it stands, a
 * different product for one slot, that slot again with the chosen product in
 * it, and "add the set to the cart".
 *
 * Everything is recomputed here rather than carried in the page, for the same
 * reason the options module quotes from the server: a price rule, a discount
 * and what is in a category are all the shop's data, and a second copy of them
 * in JavaScript is a second thing to keep true.
 */
class KitController extends Controller
{
    public function __construct(protected Kits $kits, protected Offer $offer) {}

    /** What the set costs as it is ticked right now. */
    public function quote(Request $request, Kit $kit)
    {
        [$main, $offer] = $this->offerFrom($request, $kit);

        abort_unless($offer, 404);

        return view('torgash.kits::storefront.total', ['offer' => $offer]);
    }

    /** The other products this slot could hold. */
    public function picker(Request $request, Kit $kit, KitSlot $slot)
    {
        $main = $this->mainFrom($request, $kit);

        abort_unless($main && (int) $slot->kit_id === (int) $kit->id, 404);

        $taken = $this->takenBy($request, $slot);

        $search = trim((string) $request->query('q', ''));

        return view('torgash.kits::storefront.picker', [
            'kit' => $kit,
            'slot' => $slot,
            'main' => $main,
            'search' => $search,
            'chosen' => $this->offer->productFor($slot, $main, (int) $request->query('product', 0) ?: null, $taken),
            // One entry per MODEL: six sizes of one t-shirt are six products
            // here, and a window listing them as six cards is a wall of the
            // same photograph. The size is chosen inside the card.
            'groups' => $this->offer->candidateGroups($slot, $main, $search, 24, $taken),
        ]);
    }

    /** One slot again, with a product in it — what the picker swaps back. */
    public function slot(Request $request, Kit $kit, KitSlot $slot)
    {
        $main = $this->mainFrom($request, $kit);

        abort_unless($main && (int) $slot->kit_id === (int) $kit->id, 404);

        $product = $this->offer->productFor($slot, $main, (int) $request->query('product', 0) ?: null, $this->takenBy($request, $slot));

        abort_unless($product, 404);

        $full = (int) $product->effectivePrice() * max(1, $slot->qty);

        return view('torgash.kits::storefront.item', [
            'kit' => $kit,
            'main' => $main,
            'slot' => $slot,
            'product' => $product,
            'qty' => max(1, (int) $slot->qty),
            'full' => $full,
            'price' => $slot->priceFor((int) $product->effectivePrice()) * max(1, $slot->qty),
            'required' => (bool) $slot->required,
            'swappable' => (bool) $slot->swappable && $slot->isSet(),
        ]);
    }

    /**
     * Everything ticked, in the cart — the main product as an ordinary line,
     * and each slot as a line that says which kit and slot it was bought in.
     *
     * The discount is NOT written onto the line: it is worked out from the kit
     * every time the cart is priced, so a kit that ends tonight charges full
     * price tomorrow for what is still in the basket (PLAN §33.5).
     */
    public function add(Request $request, Kit $kit, CartManager $cart)
    {
        [$main, $offer] = $this->offerFrom($request, $kit);

        abort_unless($offer, 404);

        // Configured exactly as the card quoted it. Without this a product
        // with a REQUIRED question refused the whole kit and nothing reached
        // the basket, and one with an optional answer was charged for a build
        // nobody had asked for.
        $config = (array) $request->input('config', []);

        // All of it or none of it: a set refused half way (the shelf has fewer
        // than the basket would hold) must not leave half a set behind, and
        // the reason goes to a corner notice beside the unchanged badge.
        try {
            return DB::transaction(fn () => $this->put($kit, $main, $offer, $config, $cart));
        } catch (ValidationException $e) {
            $cart->reload();

            return response()->view('torgash.cart::partials.refused', [
                'product' => $main,
                'message' => (string) collect($e->errors())->flatten()->first(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $offer
     * @param  array<string, mixed>  $config
     */
    protected function put(Kit $kit, Product $main, array $offer, array $config, CartManager $cart)
    {
        if ($kit->isExtended()) {
            // ONE line: the main product, with the rest of the set as parts of
            // it. The quantity of the set then moves as one, removing it
            // removes everything, and a slot cannot be raised to a hundred
            // behind a single phone — which is not a rule anywhere in the
            // code, it is what a part IS.
            $config[Discounts::KEY] = [
                'id' => (int) $kit->id,
                'slots' => collect($offer['slots'])
                    ->mapWithKeys(fn (array $line) => [(string) $line['slot']->id => (int) $line['product']->id])
                    ->all(),
            ];

            $cart->add($main->id, 1, $config);

            return view('torgash.cart::partials.changed');
        }

        // A simple set is a convenient way to put several ordinary things in
        // the basket at once, and that is all it is: every line is afterwards
        // the customer's to change or remove, and none of them carries a
        // discount (the form does not offer one for this kind).
        $cart->add($main->id, 1, $config);

        foreach ($offer['slots'] as $line) {
            $cart->add($line['product']->id, max(1, (int) $line['qty']));
        }

        return view('torgash.cart::partials.changed');
    }

    /**
     * The offer as the form describes it: which product leads it, which slots
     * are ticked, and which product fills each.
     *
     * @return array{0: Product|null, 1: array<string, mixed>|null}
     */
    protected function offerFrom(Request $request, Kit $kit): array
    {
        $main = $this->mainFrom($request, $kit);

        if (! $main) {
            return [null, null];
        }

        $slots = (array) $request->input('slots', []);
        $chosen = [];

        foreach ($slots as $slotId => $productId) {
            if ((int) $productId > 0) {
                $chosen[(int) $slotId] = (int) $productId;
            }
        }

        // The answers the customer gave ABOVE, on the same page: the kit form
        // pulls them in (`hx-include`), and they are what makes the quoted
        // price the charged price.
        return [$main, $this->offer->for($kit, $main, $chosen, array_keys($chosen), (array) $request->input('config', []))];
    }

    /**
     * What the card's OTHER slots already hold — read from the form that rode
     * along, so a slot never offers a product another one is already buying.
     * Two lines of the same case in one basket look like a mistake, and one of
     * them is.
     *
     * @return list<int>
     */
    protected function takenBy(Request $request, KitSlot $slot): array
    {
        $taken = [];

        foreach ((array) $request->input('slots', []) as $slotId => $productId) {
            if ((int) $slotId !== (int) $slot->id && (int) $productId > 0) {
                $taken[] = (int) $productId;
            }
        }

        return $taken;
    }

    /**
     * Which product this kit is being offered on. Its own, or the one the form
     * names — and only if the kit really is offered there, or a link could buy
     * a set at a price no page ever showed.
     */
    protected function mainFrom(Request $request, Kit $kit): ?Product
    {
        if ($kit->main_product_id) {
            return Product::reachable()->find($kit->main_product_id);
        }

        $id = (int) ($request->input('main') ?: $request->query('main', 0));
        $main = $id > 0 ? Product::reachable()->find($id) : null;

        return $main && $this->kits->for($main)->contains('id', $kit->id) ? $main : null;
    }
}
