<?php

namespace Torgash\NovaPoshta\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Torgash\NovaPoshta\Models\Warehouse;

/**
 * The branch list inside the checkout picker, searched on the server.
 *
 * Kyiv has well over a thousand branches. Shipping all of them into the page so
 * the browser can filter them would put a few hundred kilobytes into every
 * quote — and a quote happens on every keystroke in the town field. So the page
 * carries the first pageful and this endpoint answers the typing, which is what
 * Nova Poshta's own plug-ins do (their picker pages and searches in SQL).
 *
 * Public on purpose: branch addresses are the carrier's published directory,
 * and the cart this belongs to has no account behind it.
 */
class BranchesController extends Controller
{
    public const PER_PAGE = 25;

    /**
     * What the picker's search box is called, in one place.
     *
     * The box lives inside the checkout form, so it cannot be a bare `q` that
     * rides along with the order — and the name and this endpoint have to agree
     * or typing silently does nothing: HTMX faithfully sends a parameter nobody
     * reads and the unfiltered first page comes back. Which is exactly what
     * happened. The view reads it from here so they cannot drift again.
     */
    public const QUERY = 'branch_query';

    public function index(Request $request)
    {
        $settlement = (string) $request->query('settlement', '');
        $query = trim((string) $request->query(self::QUERY, ''));

        return view('torgash.novaposhta::checkout.branch-list', [
            'settlement' => $settlement,
            'query' => $query,
            'warehouses' => $this->search($settlement, $query, $request),
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, Warehouse> */
    protected function search(string $settlement, string $query, Request $request)
    {
        if ($settlement === '') {
            return collect();
        }

        return Warehouse::offered(
            $settlement,
            // Every kind of pickup point; which ones this method actually
            // offers is the type list below, carried in the URL.
            ['branch', 'cargo', 'locker'],
            // Whatever the method's config allowed, carried in the URL so a
            // search cannot widen what the quote decided to offer.
            array_values(array_filter(explode(',', (string) $request->query('types', '')))),
            max(0, (int) $request->query('weight', 0)),
        )
            ->when($query !== '', fn ($builder) => $builder->where(fn ($sub) => $sub
                ->where('number', $query)
                ->orWhereRaw('lower(name_uk) like ?', ['%'.mb_strtolower($query).'%'])
                ->orWhereRaw('lower(name_ru) like ?', ['%'.mb_strtolower($query).'%'])
                ->orWhereRaw('lower(short_uk) like ?', ['%'.mb_strtolower($query).'%'])
                ->orWhereRaw('lower(short_ru) like ?', ['%'.mb_strtolower($query).'%'])))
            // "№ 5" first when 5 was typed, then by number: someone who knows
            // the number wants exactly it, someone who knows the street scans.
            // The same ordering the carrier's own picker uses.
            ->when($query !== '', fn ($builder) => $builder->orderByRaw('number = ? desc', [$query]))
            ->inBranchOrder()
            ->limit(self::PER_PAGE)
            ->get();
    }
}
