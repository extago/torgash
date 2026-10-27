<?php

namespace Torgash\Compare\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\CategoryTree;
use Torgash\Compare\Comparison;
use Torgash\Compare\Table;

/**
 * The comparison page (PLAN §35.4, with the owner's notes of 2026-09-23): a
 * tab per group, "differences only" as ONE switch, products as the COLUMNS of
 * the table rather than a block floating above it.
 *
 * The button: one form that toggles, answered with itself in its new state,
 * the header counter out of band and, on an add, core's corner notice.
 */
class CompareController extends Controller
{
    public function index(Request $request, Comparison $comparison, Table $table, CategoryTree $tree)
    {
        // What has gone since it was added stops being compared, and counted.
        $comparison->keepOnly(Product::reachable()->whereIn('id', array_keys($comparison->ids()))->pluck('id')->map(fn ($id) => (int) $id)->all());

        $groups = $comparison->groups();
        $active = (int) $request->query('group', (string) $comparison->latestGroup());

        if (! isset($groups[$active])) {
            $active = (int) array_key_first($groups);
        }

        $ids = $groups[$active] ?? [];
        $products = Product::whereIn('id', $ids)->withCardData()->get()
            ->sortBy(fn (Product $p) => array_search($p->id, $ids, true))->values();

        // Announced like any grid: the basket's buttons, the ratings and the
        // stock of the page answer in one query each.
        hook_action('catalog.products.listed', ['products' => $products]);

        $rows = $table->rows($products);
        $diff = $request->boolean('diff') && $products->count() > 1;

        page_meta()->set('title', __('Comparison'));
        breadcrumbs()->add(__('Comparison'));

        return view('torgash.compare::index', [
            'products' => $products,
            'rows' => $diff ? $table->differencesOnly($rows) : $rows,
            'differing' => count(array_filter($rows, fn ($r) => $r['type'] === 'feature' && $r['differs'])),
            'diff' => $diff,
            'active' => $active,
            'tabs' => collect($groups)->map(fn (array $groupIds, int $id) => [
                'id' => $id,
                'name' => $id > 0 ? (string) $tree->find($id)?->t('name') : __('Other products'),
                'count' => count($groupIds),
            ])->values()->all(),
            'total' => $comparison->count(),
        ]);
    }

    public function toggle(Request $request, Product $product, Comparison $comparison)
    {
        abort_unless(Product::reachable()->whereKey($product->id)->exists(), 404);

        [$added, $pushed] = $comparison->toggle($product);

        if (! $request->header('HX-Request')) {
            return back();
        }

        return response()->view('torgash.compare::toggled', [
            'compareProduct' => $product,
            'compareLabelled' => $request->boolean('labelled'),
            'compareJust' => true,
            'compareAdded' => $added,
            'comparePushed' => $pushed,
            'compareGroup' => $comparison->groupOf($product->id),
        ]);
    }

    /** The × over a column: the page comes back without it, on the same tab. */
    public function remove(Product $product, Comparison $comparison)
    {
        $comparison->remove($product->id);

        return back();
    }

    public function clear(Comparison $comparison)
    {
        $comparison->clear();

        return redirect()->route('compare.index');
    }
}
