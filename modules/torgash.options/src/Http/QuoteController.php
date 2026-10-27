<?php

namespace Torgash\Options\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Torgash\Catalog\Models\Product;
use Torgash\Options\Configurator;
use Torgash\Options\Groups;

/**
 * What the build costs, answered by the server on every change (PLAN §33.4).
 *
 * A price map in the markup was the alternative, and it is the thing this
 * engine refuses on principle: it would be a second implementation of every
 * price rule, in JavaScript, going stale the moment a rate moves or a linked
 * product is repriced. Here the same `Configurator` that will price the cart
 * line answers the page, so the number the customer reads and the number they
 * are charged come from one place.
 *
 * The configuration is NOT validated here — a half-answered form is the normal
 * state of a page somebody is still filling in, and refusing to price it would
 * mean the total disappears while they think. What cannot be answered is
 * refused when it is added to the cart, and again at placement.
 */
class QuoteController extends Controller
{
    public function __invoke(Request $request, Configurator $configurator, Groups $groups, Product $product)
    {
        abort_unless($product->exists, 404);

        $qty = max(1, (int) $request->input('qty', 1));
        $config = $configurator->normalise($product, (array) $request->input('config', []));

        // A product whose questions hang off one another gets the whole form
        // back, because answering one may have taken another off the page —
        // and put its answer off the bill with it (`Groups::asked`). The page
        // asks the same class the same question to decide which half of itself
        // it is replacing, so the two cannot disagree about what comes back.
        if ($groups->hasDependencies($product)) {
            return view('torgash.options::storefront.fields', [
                'product' => $product,
                'groups' => $groups->asked($product, (array) ($config[Configurator::KEY] ?? [])),
                'config' => $config,
                'errors' => [],
            ]);
        }

        return view('torgash.options::storefront.total', [
            'product' => $product,
            'qty' => $qty,
            'config' => $config,
        ]);
    }
}
