<?php

namespace Torgash\Reviews\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Torgash\Catalog\Support\CategoryTree;
use Torgash\Reviews\Criteria;
use Torgash\Reviews\Models\Criterion;

/**
 * What the shop asks people to rate.
 *
 * One screen with the list and the form on it: a criterion is a name, a place
 * in the order, and the categories it is asked in — three fields, which is not
 * a page of its own per row.
 *
 * The category picker is the indented tree, and attaching a criterion to a
 * branch covers everything under it: in this engine a category always means
 * itself and its subcategories, so there is no "include subcategories" box to
 * tick (and no way for two screens to disagree about what one is).
 */
class CriteriaController extends Controller
{
    public function __construct(protected Criteria $criteria) {}

    public function index()
    {
        return view('torgash.reviews::admin.criteria', [
            'criteria' => Criterion::with('categories')->orderBy('sort')->orderBy('id')->get(),
            'categories' => app(CategoryTree::class)->options(),
            // How many marks each has been given — what deleting it would
            // leave without a heading (the scores keep their numbers).
            'scoreCounts' => DB::table('m_reviews_ratings')->selectRaw('criterion_id, count(*) as n')
                ->groupBy('criterion_id')->pluck('n', 'criterion_id')->map(fn ($n) => (int) $n)->all(),
        ]);
    }

    public function create()
    {
        return $this->form(new Criterion(['enabled' => true, 'sort' => (int) Criterion::max('sort') + 10]));
    }

    public function edit(Criterion $criterion)
    {
        return $this->form($criterion->load('categories'));
    }

    protected function form(Criterion $criterion)
    {
        return view('torgash.reviews::admin.criterion-form', [
            'criterion' => $criterion,
            'categories' => app(CategoryTree::class)->options(),
        ]);
    }

    /** The list's in-place switch: answers with the cell. */
    public function setStatus(Request $request, Criterion $criterion)
    {
        $request->validate(['enabled' => 'required|boolean']);
        $criterion->update(['enabled' => $request->boolean('enabled')]);
        $this->criteria->flush();

        return view('torgash.reviews::admin._criterion-status', ['criterion' => $criterion]);
    }

    /** Back to the form, as every edit form here does; "Save and close" is the way out. */
    protected function afterSave(Request $request, Criterion $criterion, string $message)
    {
        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.reviews.criteria.index')
            : redirect()->route('admin.reviews.criteria.edit', $criterion))->with('status', $message);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, null);

        $criterion = Criterion::create([
            'code' => $data['code'],
            'name' => [admin_content_locale() => $data['name']],
            'sort' => $data['sort'],
            'enabled' => $request->boolean('enabled'),
        ]);

        $this->syncCategories($criterion, $request);

        return $this->afterSave($request, $criterion, __('Criterion added.'));
    }

    public function update(Request $request, Criterion $criterion)
    {
        $data = $this->validated($request, $criterion);

        $criterion->update([
            'code' => $data['code'],
            // Merged, never replaced: this screen edits ONE language at a time
            // (the admin's content language), and saving in English must not
            // wipe the Russian label somebody wrote last month.
            'name' => [...(array) $criterion->name, admin_content_locale() => $data['name']],
            'sort' => $data['sort'],
            'enabled' => $request->boolean('enabled'),
        ]);

        $this->syncCategories($criterion, $request);

        return $this->afterSave($request, $criterion, __('Criterion updated.'));
    }

    /**
     * Deleting a criterion leaves the SCORES people gave it (no foreign key on
     * that table): they did give them, and tidying a list must not rewrite
     * what somebody said. The criterion simply stops being asked and stops
     * being printed.
     */
    public function destroy(Criterion $criterion)
    {
        $criterion->delete();
        $this->criteria->flush();

        return redirect()->route('admin.reviews.criteria.index')->with('status', __('Criterion deleted.'));
    }

    /** @return array{code: string, name: string, sort: int} */
    protected function validated(Request $request, ?Criterion $criterion): array
    {
        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'code' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('m_reviews_criteria', 'code')->ignore($criterion?->id)],
            'sort' => 'nullable|integer|min:0',
        ]);

        return [
            // Derived from the name when not typed, the way every other slug
            // in this engine is — and a name in Russian gives a Latin code,
            // because the code is a form field name, not a label.
            'code' => ($validated['code'] ?? null) ?: $this->freeCode($validated['name'], $criterion),
            'name' => $validated['name'],
            'sort' => (int) ($validated['sort'] ?? 0),
        ];
    }

    protected function freeCode(string $name, ?Criterion $criterion): string
    {
        $base = Str::slug($name, '_') ?: 'criterion';
        $code = $base;
        $n = 2;

        while (Criterion::where('code', $code)->when($criterion, fn ($q) => $q->whereKeyNot($criterion->id))->exists()) {
            $code = $base.'_'.$n++;
        }

        return $code;
    }

    protected function syncCategories(Criterion $criterion, Request $request): void
    {
        $ids = collect((array) $request->input('categories', []))
            ->map('intval')->filter()->unique()->values();

        $criterion->categories()->delete();

        foreach ($ids as $id) {
            $criterion->categories()->create(['category_id' => $id]);
        }

        $this->criteria->flush();
    }
}
