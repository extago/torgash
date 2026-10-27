<?php

namespace App\Http\Controllers\Admin;

use App\Core\Admin\AdminSearch;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * The header's search box. HTMX asks for the list alone as the person types;
 * the same address without HTMX (Enter with scripts off, a shared link) is a
 * page of its own, so the box works as a plain GET form too.
 */
class SearchController extends Controller
{
    public function index(Request $request, AdminSearch $search)
    {
        $query = trim((string) $request->query('q', ''));
        $results = mb_strlen($query) >= 2 ? $search->search($query) : [];

        return view($request->header('HX-Request') ? 'admin.search.results' : 'admin.search.index', [
            'query' => $query,
            'results' => $results,
        ]);
    }
}
