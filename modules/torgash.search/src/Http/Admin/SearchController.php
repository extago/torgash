<?php

namespace Torgash\Search\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Torgash\Catalog\Models\Product;
use Torgash\Search\Dictionary;
use Torgash\Search\Engine;
use Torgash\Search\Indexer;
use Torgash\Search\Jobs\Reindex;

/**
 * The search screen: is the index complete, try a query and see what the
 * shop would answer and why, rebuild the index. Synonyms, rules, phrases and
 * the "searched and not found" list join it in later steps of §34.
 */
class SearchController extends Controller
{
    public function index(Request $request, Indexer $indexer, Engine $engine, Dictionary $dictionary)
    {
        $q = trim((string) $request->query('q', ''));
        $result = $q !== '' ? $engine->search($q) : null;
        // Which of the shop's own rules shaped this answer, in words — a rule
        // nobody can see working is a rule nobody trusts.
        $fired = array_values(array_unique(app(\Torgash\Search\Rules::class)->fired));

        return view('torgash.search::admin.index', [
            'indexed' => $indexer->count(),
            'words' => $dictionary->count(),
            'products' => Product::withTrashed()->count(),
            'q' => $q,
            'fired' => $fired,
            'result' => $result,
            'found' => $result ? Product::withTrashed()->withCardData()->whereIn('id', array_slice($result->ids, 0, 50))->get()
                ->sortBy(fn (Product $p) => array_search($p->id, $result->ids, true))->values() : collect(),
            'sources' => $engine->retrievers(),
        ]);
    }

    public function reindex()
    {
        Reindex::startIfIdle();

        return back()->with('status', __('The search index is being rebuilt in the background — see Background tasks.'));
    }
}
