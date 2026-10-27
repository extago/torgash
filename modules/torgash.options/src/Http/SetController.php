<?php

namespace Torgash\Options\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Torgash\Options\Configurator;
use Torgash\Options\Models\OptionChoice;
use Torgash\Options\Models\OptionGroup;

/**
 * The two fragments a set-of-products answer needs (PLAN §33.4).
 *
 * A set can hold two hundred processors, and a dropdown of two hundred
 * processors is not a choice — it is a scroll. So the list is narrowed by a
 * SEARCH, answered by the server: the set is a catalogue query
 * (`catalog.product_sources`), and only the server can narrow a query. The
 * alternative was shipping the whole set into the page and filtering it in
 * JavaScript, which is the same set plus a second implementation of "what is
 * in this category".
 *
 * A row is the other half: "Add…" asks for one more row of the form, rendered
 * by the same view that renders the rows already there — so a row added by
 * hand and a row restored from a cart line cannot look or behave differently.
 * With no JavaScript at all the first row is still there and still buys.
 */
class SetController extends Controller
{
    /** The `<option>`s of one set, narrowed by what was typed. */
    public function set(Request $request, Configurator $configurator, OptionChoice $choice)
    {
        abort_unless($choice->isSet() && $choice->enabled, 404);

        $group = $choice->group;

        return view('torgash.options::storefront.set-options', [
            'choice' => $choice,
            'group' => $group,
            // Whose page this is — a shared group has no product of its own,
            // and every price rule is measured against the product's price.
            'product' => $group?->product ?: \Torgash\Catalog\Models\Product::reachable()
                ->where('slug', (string) $request->query('product', ''))
                ->first(),
            'products' => $configurator->setProducts($choice, trim((string) $request->query('q', ''))),
            // Nothing is selected in a freshly narrowed list: the customer is
            // choosing, and pre-selecting the first hit would answer for them.
            'chosen' => (string) $request->query('chosen', ''),
        ]);
    }

    /** One more row of a `rows` group. */
    public function row(Request $request, OptionGroup $group)
    {
        abort_unless($group->type === 'rows' && $group->enabled, 404);

        // WHICH product the question is being asked about: a shared group has
        // none of its own, and every price rule is measured against the
        // product's price — so the page says which page it is.
        $product = $group->product ?: \Torgash\Catalog\Models\Product::reachable()
            ->where('slug', (string) $request->query('product', ''))
            ->first();

        return view('torgash.options::storefront.row', [
            'group' => $group,
            'product' => $product,
            // Any unique index will do — the group's answers are read as a
            // list of rows, and the key is only how the browser tells its own
            // inputs apart. A timestamp is unique without the server having to
            // know how many rows the page already holds.
            'index' => preg_replace('/\D/', '', (string) $request->query('index', '')) ?: (string) now()->getTimestampMs(),
            'entry' => null,
        ]);
    }
}
