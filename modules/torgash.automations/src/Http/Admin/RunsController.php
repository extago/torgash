<?php

namespace Torgash\Automations\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Torgash\Automations\Models\Rule;
use Torgash\Automations\Models\RuleRun;
use Torgash\Automations\Subjects\Subjects;

/**
 * What actually happened. Without this screen a rule that quietly stopped
 * getting through looks exactly like a rule nothing triggered.
 */
class RunsController extends Controller
{
    public function index(Request $request)
    {
        $runs = RuleRun::query()
            ->when($request->filled('rule'), fn ($q) => $q->where('rule_id', (int) $request->get('rule')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->get('status')))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        // "TG-000123" / "#5 · Callback" with a link, per subject, one query each
        // for the whole page — a run row names what it ran against.
        $refs = [];
        foreach (collect($runs->items())->groupBy('subject') as $key => $rows) {
            $ids = $rows->map(fn ($run) => (int) ($run->subject_id ?? $run->order_id))->filter()->unique()->values()->all();
            $refs[$key] = $ids !== [] ? (Subjects::get((string) $key)?->refs($ids) ?? []) : [];
        }

        return view('torgash.automations::admin.runs.index', [
            'runs' => $runs,
            'refs' => $refs,
            'rules' => Rule::orderBy('name')->get(['id', 'name']),
        ]);
    }
}
