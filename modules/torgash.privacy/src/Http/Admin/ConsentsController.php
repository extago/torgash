<?php

namespace Torgash\Privacy\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Torgash\Privacy\Models\ConsentRecord;

/** The record of consents (PLAN §49): who agreed to what, when, in which words. */
class ConsentsController extends Controller
{
    public function __invoke(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $kind = (string) $request->query('kind', '');

        $records = ConsentRecord::query()
            ->with('user:id,name')
            ->when($kind !== '', fn ($query) => $query->where('kind', $kind))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->whereRaw('lower(email) like ?', ['%'.mb_strtolower($q).'%'])
                ->orWhere('phone', 'like', '%'.$q.'%')
                ->orWhere('ip', $q)))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return view('torgash.privacy::admin.consents', [
            'records' => $records,
            'kinds' => ConsentRecord::query()->distinct()->orderBy('kind')->pluck('kind'),
            'q' => $q,
            'kind' => $kind,
        ]);
    }
}
