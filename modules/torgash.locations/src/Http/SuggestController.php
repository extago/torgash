<?php

namespace Torgash\Locations\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Torgash\Locations\Models\Place;

/**
 * Type-ahead over the registry, used by the city field in the cart and by the
 * zone editor in the admin.
 *
 * One controller for both on purpose: two different endpoints would drift, and
 * a customer being offered a town the admin cannot put in a zone (or the other
 * way round) is a confusing bug to chase.
 */
class SuggestController extends Controller
{
    public function __invoke(Request $request)
    {
        $term = trim((string) $request->get('q', ''));

        if (mb_strlen($term) < 2) {
            // One letter matches thousands of rows and answers nothing useful.
            return response()->json(['more' => false, 'items' => []]);
        }

        // A page size, not a ceiling: the box loads the next page as it is
        // scrolled. Ten used to be the whole answer, which quietly hid every
        // namesake past the tenth — and namesakes are the reason this list
        // shows a district at all.
        $limit = max(1, min(25, (int) setting('torgash.locations', 'autocomplete_limit', 10)));
        $page = max(1, (int) $request->get('page', 1));

        $places = Place::query()
            ->with('state')
            // The storefront may only offer what the shop actually serves; the
            // admin picks from everything, because a zone can be built for a
            // place that is currently switched off.
            ->when($this->storefront($request), fn ($query) => $query->available())
            ->when($request->get('country'), fn ($query, $country) => $query->where('country', $country))
            ->search($term)
            // One row past the page, so "is there more" needs no second query.
            ->limit($limit + 1)
            ->offset(($page - 1) * $limit)
            ->get();

        $more = $places->count() > $limit;

        return response()->json(['more' => $more, 'items' => $places->take($limit)->map(fn (Place $place) => [
            'id' => $place->id,
            'name' => $place->t('name') ?? '',
            // What tells two of the eighty Ivanivkas apart. Both halves, not
            // the district alone: plenty of people cannot name their district
            // but know their region instantly, and a list that offers only the
            // half they do not know is a list they cannot choose from.
            'context' => collect([$place->t('district'), $place->state?->t('name')])
                ->filter()->unique()->implode(' · '),
            'country' => $place->country,
            'label' => $place->label(),
        ])->values()->all()]);
    }

    /** Admin requests come through the admin prefix; everything else is a shop visitor. */
    protected function storefront(Request $request): bool
    {
        return ! $request->routeIs('admin.*');
    }
}
