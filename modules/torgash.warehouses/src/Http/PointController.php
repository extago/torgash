<?php

namespace Torgash\Warehouses\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Torgash\Warehouses\PointStore;

/**
 * The visitor picking (or dropping) the shop they are shopping at.
 *
 * Back to where they were, not to a page of its own: the choice is a lens on
 * the catalogue, and the whole point is to see the page they were looking at
 * through it.
 */
class PointController extends Controller
{
    public function __construct(protected PointStore $points) {}

    public function choose(Request $request)
    {
        $point = $this->points->choose((int) $request->input('point_id'));

        // Where the customer says they are, once — so the cart's delivery
        // estimate is not still asking for a town they have just named by
        // picking a shop in it. Announced rather than written: this module has
        // no business inside checkout's session, and a shop with no checkout
        // module simply has nobody listening.
        if ($point && $point->place_id) {
            hook_action('storefront.place_chosen', [
                'place_id' => $point->place_id,
                'source' => 'pickup_point',
            ]);
        }

        return back();
    }

    public function forget()
    {
        $this->points->forget();

        return back();
    }
}
