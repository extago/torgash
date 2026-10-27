<?php

namespace Torgash\Cdek\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Torgash\Cdek\Models\Point;

/**
 * The point list inside the checkout picker, searched on the server — Moscow
 * has thousands of CDEK points, and a quote runs on every keystroke in the
 * town field, so the page carries the first pageful and this answers the
 * typing.
 *
 * Public on purpose: point addresses are the carrier's published directory,
 * and the cart this belongs to has no account behind it.
 */
class PointsController extends Controller
{
    public const PER_PAGE = 25;

    /**
     * What the picker's search box is called. It lives inside the checkout
     * form, so it cannot be a bare `q`; the view reads the name from here so
     * the box and this endpoint cannot drift apart.
     */
    public const QUERY = 'point_query';

    public function index(Request $request)
    {
        $city = (int) $request->query('city', 0);
        $query = trim((string) $request->query(self::QUERY, ''));

        return view('torgash.cdek::checkout.point-list', [
            'query' => $query,
            'points' => $this->search($city, $query, $request),
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, Point> */
    protected function search(int $city, string $query, Request $request)
    {
        if ($city <= 0) {
            return collect();
        }

        $like = '%'.mb_strtolower($query).'%';

        return Point::offered(
            $city,
            // What the method's config allowed, carried in the URL so a search
            // cannot widen what the quote decided to offer.
            array_values(array_filter(explode(',', (string) $request->query('types', '')))),
            max(0, (int) $request->query('weight', 0)),
            max(0, (int) $request->query('volume', 0)),
        )
            ->when($query !== '', fn ($builder) => $builder->where(fn ($sub) => $sub
                ->whereRaw('lower(code) = ?', [mb_strtolower($query)])
                ->orWhereRaw('lower(address) like ?', [$like])
                ->orWhereRaw('lower(name) like ?', [$like])
                ->orWhereRaw('lower(coalesce(nearest_metro, \'\')) like ?', [$like])))
            ->inListOrder()
            ->limit(self::PER_PAGE)
            ->get();
    }
}
