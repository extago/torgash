<?php

namespace Torgash\ShopReviews\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Torgash\ShopReviews\Models\Criterion;
use Torgash\ShopReviews\Stats;

/** What the shop asks its customers to rate it by. */
class CriteriaController extends Controller
{
    public function index()
    {
        return view('torgash.shopreviews::admin.criteria', [
            'criteria' => Criterion::orderBy('sort')->orderBy('id')->get(),
            'scoreCounts' => DB::table('m_shopreviews_ratings')->selectRaw('criterion_id, count(*) as n')
                ->groupBy('criterion_id')->pluck('n', 'criterion_id')->map(fn ($n) => (int) $n)->all(),
        ]);
    }

    public function create()
    {
        return view('torgash.shopreviews::admin.criterion-form', [
            'criterion' => new Criterion(['enabled' => true, 'sort' => (int) Criterion::max('sort') + 10]),
        ]);
    }

    public function edit(Criterion $criterion)
    {
        return view('torgash.shopreviews::admin.criterion-form', ['criterion' => $criterion]);
    }

    public function setStatus(Request $request, Criterion $criterion)
    {
        $request->validate(['enabled' => 'required|boolean']);
        $criterion->update(['enabled' => $request->boolean('enabled')]);

        return view('torgash.shopreviews::admin._criterion-status', ['criterion' => $criterion]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, null);

        $criterion = Criterion::create([
            'code' => $data['code'],
            'name' => put_translation([], $data['name']),
            'sort' => $data['sort'],
            'enabled' => $request->boolean('enabled'),
        ]);

        return $this->afterSave($request, $criterion, __('Criterion added.'));
    }

    public function update(Request $request, Criterion $criterion)
    {
        $data = $this->validated($request, $criterion);

        $criterion->update([
            'code' => $data['code'],
            'name' => put_translation((array) $criterion->name, $data['name']),
            'sort' => $data['sort'],
            'enabled' => $request->boolean('enabled'),
        ]);

        return $this->afterSave($request, $criterion, __('Criterion updated.'));
    }

    /** The scores already given stay (no FK): tidying a list must not rewrite what people said. */
    public function destroy(Criterion $criterion)
    {
        $criterion->delete();
        Stats::forget();

        return redirect()->route('admin.shopreviews.criteria.index')->with('status', __('Criterion deleted.'));
    }

    protected function afterSave(Request $request, Criterion $criterion, string $message)
    {
        Stats::forget();

        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.shopreviews.criteria.index')
            : redirect()->route('admin.shopreviews.criteria.edit', $criterion))->with('status', $message);
    }

    /** @return array{code: string, name: ?string, sort: int} */
    protected function validated(Request $request, ?Criterion $criterion): array
    {
        $validated = $request->validate([
            'name' => [content_locale_is_base() ? 'required' : 'nullable', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('m_shopreviews_criteria', 'code')->ignore($criterion?->id)],
            'sort' => 'nullable|integer|min:0',
        ]);

        return [
            'code' => ($validated['code'] ?? null) ?: ($criterion?->code ?? $this->freeCode((string) ($validated['name'] ?? ''))),
            'name' => $validated['name'] ?? null,
            'sort' => (int) ($validated['sort'] ?? 0),
        ];
    }

    protected function freeCode(string $name): string
    {
        $base = Str::slug($name, '_') ?: 'criterion';
        $code = $base;
        $n = 2;

        while (Criterion::where('code', $code)->exists()) {
            $code = $base.'_'.$n++;
        }

        return $code;
    }
}
