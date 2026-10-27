<?php

namespace Torgash\Search\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\CategoryTree;
use Torgash\Search\Models\Exclusion;
use Torgash\Search\Models\Phrase;
use Torgash\Search\Models\Synonym;
use Torgash\Search\Rules;

/**
 * The three screens where the shop steers its search by hand — synonyms,
 * exclusions, pinned phrases. One controller because they are one shape (a
 * list with a form above it, the same form to edit a row); what differs —
 * the model, the fields, how admin input becomes stored words — is `kinds()`.
 * Everything typed is normalised exactly as a query is (Rules::entries()), so
 * a rule can only ever be written in the words it will be compared with.
 */
class RulesController extends Controller
{
    /** @return array<string, class-string<Model>> */
    protected static function kinds(): array
    {
        return ['synonyms' => Synonym::class, 'exclusions' => Exclusion::class, 'phrases' => Phrase::class];
    }

    public function index(Request $request, string $kind)
    {
        return $this->screen($request, $kind, null);
    }

    public function edit(Request $request, string $kind, int $id)
    {
        return $this->screen($request, $kind, $this->model($kind)::findOrFail($id));
    }

    /** The list's in-place switch: answers with the cell. */
    public function setStatus(Request $request, string $kind, int $id)
    {
        $request->validate(['enabled' => 'required|boolean']);
        $row = $this->model($kind)::findOrFail($id);
        $row->update(['enabled' => $request->boolean('enabled')]);

        return view('torgash.search::admin.rules._status', ['kind' => $kind, 'rulesRow' => $row]);
    }

    public function bulk(Request $request, string $kind)
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['enable', 'disable', 'delete'])],
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        // Row by row, so the guard's audit hears every one.
        $rows = $this->model($kind)::whereIn('id', $validated['ids'])->get();

        if ($validated['action'] === 'delete') {
            $rows->each->delete();

            return back()->with('status', trans_choice(':count rule deleted.|:count rules deleted.', $rows->count(), ['count' => $rows->count()]));
        }

        $rows->each(fn (Model $row) => $row->update(['enabled' => $validated['action'] === 'enable']));

        return back()->with('status', trans_choice(':count rule updated.|:count rules updated.', $rows->count(), ['count' => $rows->count()]));
    }

    public function store(Request $request, string $kind)
    {
        $class = $this->model($kind);
        $class::create($this->validated($request, $kind, null));

        return redirect()->route('admin.search-engine.rules.index', $kind)->with('status', __('Saved.'));
    }

    public function update(Request $request, string $kind, int $id)
    {
        $row = $this->model($kind)::findOrFail($id);
        $row->update($this->validated($request, $kind, $row));

        return redirect()->route('admin.search-engine.rules.index', $kind)->with('status', __('Saved.'));
    }

    public function destroy(string $kind, int $id)
    {
        $this->model($kind)::findOrFail($id)->delete();

        return redirect()->route('admin.search-engine.rules.index', $kind)->with('status', __('Deleted.'));
    }

    protected function screen(Request $request, string $kind, ?Model $editing)
    {
        $class = $this->model($kind);
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'state' => in_array($request->query('state'), ['on', 'off'], true) ? $request->query('state') : '',
        ];

        // Hundreds at most: read once, searched over everything the row holds
        // (its words, its note), counted per state for the tabs.
        $all = $class::query()->orderByDesc($kind === 'phrases' ? 'priority' : 'id')->orderBy('id')->get();
        $needle = mb_strtolower($filters['q']);
        $searched = $needle === '' ? $all : $all->filter(fn (Model $row) => str_contains(
            mb_strtolower(json_encode($row->only(['phrases', 'target', 'when_words', 'exclude_words', 'note', 'phrase']), JSON_UNESCAPED_UNICODE)),
            $needle,
        ));

        return view('torgash.search::admin.rules.'.$kind, [
            'kind' => $kind,
            'rows' => $searched->filter(fn (Model $row) => match ($filters['state']) {
                'on' => (bool) $row->enabled,
                'off' => ! $row->enabled,
                default => true,
            })->values(),
            'filters' => $filters,
            'stateCounts' => [
                '' => $searched->count(),
                'on' => $searched->where('enabled', true)->count(),
                'off' => $searched->where('enabled', false)->count(),
            ],
            'editing' => $editing,
            'categoryOptions' => $kind === 'exclusions' ? app(CategoryTree::class)->options() : [],
            'productOptions' => $kind === 'phrases' ? $this->productOptions() : [],
            'productNames' => $kind === 'phrases' ? $this->productNames($all) : [],
        ]);
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, string $kind, ?Model $row): array
    {
        return match ($kind) {
            'synonyms' => $this->synonym($request),
            'exclusions' => $this->exclusion($request),
            'phrases' => $this->phrase($request, $row),
        };
    }

    protected function synonym(Request $request): array
    {
        $data = $request->validate([
            'phrases' => ['required', 'string', 'max:2000'],
            'target' => ['required', 'string', 'max:255'],
            'mode' => ['required', Rule::in(['also', 'instead'])],
        ]);

        $phrases = Rules::entries($data['phrases']);
        $target = Rules::entries([$data['target']])[0] ?? '';

        if ($phrases === [] || $target === '') {
            throw \Illuminate\Validation\ValidationException::withMessages(['phrases' => __('Write at least one word — letters or digits.')]);
        }

        return ['phrases' => $phrases, 'target' => $target, 'mode' => $data['mode'], 'enabled' => $request->boolean('enabled')];
    }

    protected function exclusion(Request $request): array
    {
        $data = $request->validate([
            'when_words' => ['required', 'string', 'max:2000'],
            'exclude_words' => ['nullable', 'string', 'max:2000'],
            'exclude_category_id' => ['nullable', 'integer', 'exists:m_catalog_categories,id'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $when = Rules::entries($data['when_words']);
        // A target word is matched against the start of a word of the name, so
        // each entry is one word.
        $exclude = [];

        foreach (Rules::entries($data['exclude_words'] ?? '') as $entry) {
            foreach (explode(' ', $entry) as $word) {
                $exclude[$word] = true;
            }
        }

        $exclude = array_map('strval', array_keys($exclude));

        if ($when === []) {
            throw \Illuminate\Validation\ValidationException::withMessages(['when_words' => __('Write at least one word — letters or digits.')]);
        }

        if ($exclude === [] && empty($data['exclude_category_id'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['exclude_words' => __('Say what not to show: words of a name, a category, or both.')]);
        }

        return [
            'when_words' => $when,
            'exclude_words' => $exclude,
            'exclude_category_id' => $data['exclude_category_id'] ?? null,
            'note' => $data['note'] ?? null,
            'enabled' => $request->boolean('enabled'),
        ];
    }

    protected function phrase(Request $request, ?Model $row): array
    {
        $data = $request->validate([
            'phrase' => ['required', 'string', 'max:255'],
            'priority' => ['nullable', 'integer', 'between:-1000,1000'],
            'product_ids' => ['nullable', 'array', 'max:50'],
            'product_ids.*' => ['integer', 'exists:m_catalog_products,id'],
        ]);

        $phrase = Rules::entries([$data['phrase']])[0] ?? '';

        if ($phrase === '') {
            throw \Illuminate\Validation\ValidationException::withMessages(['phrase' => __('Write at least one word — letters or digits.')]);
        }

        if (Phrase::where('phrase', $phrase)->when($row, fn ($q) => $q->whereKeyNot($row->getKey()))->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['phrase' => __('This phrase is already there.')]);
        }

        return [
            'phrase' => $phrase,
            'priority' => (int) ($data['priority'] ?? 0),
            'product_ids' => array_values(array_unique(array_map('intval', $data['product_ids'] ?? []))),
            'enabled' => $request->boolean('enabled'),
        ];
    }

    /** @return class-string<Model> */
    protected function model(string $kind): string
    {
        abort_unless(isset(self::kinds()[$kind]), 404);

        return self::kinds()[$kind];
    }

    /** @return list<array{id: int, label: string}> */
    protected function productOptions(): array
    {
        return Product::query()->orderBy('id')->get(['id', 'sku', 'name'])
            ->map(fn (Product $p) => ['id' => $p->id, 'label' => $p->sku.' — '.$p->t('name')])->all();
    }

    /** @return array<int, string> */
    protected function productNames($rows): array
    {
        $ids = $rows->flatMap(fn (Phrase $p) => (array) $p->product_ids)->unique()->all();

        return Product::whereIn('id', $ids)->get(['id', 'name'])->mapWithKeys(fn (Product $p) => [$p->id => $p->t('name')])->all();
    }
}
