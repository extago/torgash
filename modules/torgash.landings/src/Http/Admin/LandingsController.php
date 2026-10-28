<?php

namespace Torgash\Landings\Http\Admin;

use App\Core\Display\DisplayRules;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;
use Torgash\Catalog\Models\Product;
use Torgash\Landings\Files;
use Torgash\Landings\Models\Landing;
use Torgash\Landings\PreviewProduct;
use Torgash\Landings\Zip;

/**
 * Catalogue → Product landings: the list (switched in place, several at
 * once), one landing's form (its words per language, CSS, files, where and
 * for whom), copying, a look at it on a product, and a landing as a ZIP.
 */
class LandingsController extends Controller
{
    public function index(Request $request)
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'state' => array_key_exists((string) $request->query('state'), Landing::stateLabels()) ? (string) $request->query('state') : '',
        ];

        // A shop has dozens of these, not thousands: read once, counted per
        // state for the tabs (a state moves with the clock, not a column).
        $searched = Landing::query()
            ->when($filters['q'] !== '', fn ($q) => $q->where('name', 'ilike', '%'.addcslashes($filters['q'], '%_\\').'%'))
            ->orderBy('placement')->orderBy('sort')->orderBy('id')
            ->get();
        $landings = $filters['state'] === '' ? $searched : $searched->filter(fn (Landing $landing) => $landing->state() === $filters['state'])->values();

        $counts = DB::table('m_landing_products')->selectRaw('landing_id, excluded, count(*) as n')
            ->groupBy('landing_id', 'excluded')->get()
            ->groupBy('landing_id')->map(fn ($rows) => $rows->pluck('n', 'excluded')->mapWithKeys(fn ($n, $ex) => [$ex ? 'excluded' : 'picked' => (int) $n]));

        return view('torgash.landings::admin.index', [
            'landings' => $landings,
            'counts' => $counts,
            'filters' => $filters,
            'stateCounts' => ['' => $searched->count()] + collect(Landing::stateLabels())
                ->map(fn ($label, $state) => $searched->filter(fn (Landing $landing) => $landing->state() === $state)->count())->all(),
        ]);
    }

    /** Several at once from the list: switched on, off, or deleted. */
    public function bulk(Request $request)
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['enable', 'disable', 'delete'])],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        // Model by model: a landing's files go with it only through its
        // model, and the audit hears Eloquent events.
        $landings = Landing::query()->whereIn('id', $validated['ids'])->get();

        if ($validated['action'] === 'delete') {
            $landings->each->delete();

            return back()->with('status', trans_choice(':count landing deleted.|:count landings deleted.', $landings->count(), ['count' => $landings->count()]));
        }

        $landings->each(fn (Landing $landing) => $landing->update(['enabled' => $validated['action'] === 'enable']));

        return back()->with('status', trans_choice(':count landing updated.|:count landings updated.', $landings->count(), ['count' => $landings->count()]));
    }

    /**
     * The landing as saved, on a product's page — whatever its switch, dates
     * and conditions say (`?landing_preview=`, staff only). On the product
     * asked for, or on one it is for (PreviewProduct).
     */
    public function preview(Request $request, Landing $landing, PreviewProduct $previews)
    {
        $product = $request->filled('product')
            ? Product::query()->reachable()->find((int) $request->query('product'))
            : $previews->for($landing)['product'];

        if ($product === null) {
            return redirect()->route('admin.landings.edit', $landing)->withErrors([
                'product' => $request->filled('product')
                    ? __('That product has no page on the storefront: it is switched off or deleted.')
                    : __('There is no product to look at it on yet.'),
            ]);
        }

        return redirect()->to(route('catalog.product', $product->slug).'?landing_preview='.$landing->id);
    }

    public function create()
    {
        return view('torgash.landings::admin.form', $this->formData(new Landing(['name' => '', 'html' => []])));
    }

    public function store(Request $request)
    {
        $landing = new Landing;
        $this->fill($landing, $request);
        $landing->save();
        $this->syncProducts($landing, $request);

        return redirect()->route('admin.landings.edit', $landing)->with('status', __('The landing is saved. Its pictures go in "Files".'));
    }

    /** The product pickers' answer to what the admin types. */
    public function products(Request $request, \Torgash\Catalog\Admin\ProductLookup $lookup)
    {
        return response()->json($lookup->search((string) $request->query('q', '')));
    }

    public function edit(Landing $landing)
    {
        return view('torgash.landings::admin.form', $this->formData($landing));
    }

    public function update(Request $request, Landing $landing)
    {
        $this->fill($landing, $request);
        $landing->save();
        $this->syncProducts($landing, $request);

        return $request->input('after_save') === 'close'
            ? redirect()->route('admin.landings.index')->with('status', __('Saved.'))
            : redirect()->route('admin.landings.edit', $landing)->with('status', __('Saved.'));
    }

    public function destroy(Landing $landing)
    {
        $landing->delete();

        return redirect()->route('admin.landings.index')->with('status', __('The landing is deleted.'));
    }

    /** A copy, switched OFF: a second live copy of a landing is never what was meant. */
    public function copy(Landing $landing, Files $files)
    {
        $copy = $landing->replicate();
        $copy->name = __(':name (copy)', ['name' => $landing->name]);
        $copy->enabled = false;
        $copy->save();

        DB::table('m_landing_products')->insertUsing(
            ['landing_id', 'product_id', 'excluded'],
            DB::table('m_landing_products')->where('landing_id', $landing->id)->selectRaw('?, product_id, excluded', [$copy->id]),
        );
        $files->copy($landing, $copy);

        return redirect()->route('admin.landings.edit', $copy)->with('status', __('The landing is copied. The copy is switched off.'));
    }

    /** The list's in-place switch: answers with the cell. */
    public function status(Request $request, Landing $landing)
    {
        $request->validate(['enabled' => ['required', 'boolean']]);
        $landing->update(['enabled' => $request->boolean('enabled')]);

        return view('torgash.landings::admin._status', ['landing' => $landing]);
    }

    public function upload(Request $request, Landing $landing, Files $files)
    {
        $request->validate([
            'files' => ['required', 'array', 'max:50'],
            'files.*' => ['file', 'max:'.(Files::MAX_SIZE / 1024)],
            'folder' => ['nullable', 'string', 'max:100'],
        ]);

        $stored = [];
        $refused = [];

        foreach ($request->file('files') as $file) {
            $path = $files->put($landing, $file->getClientOriginalName(), $file->getRealPath(), (string) $request->input('folder', ''));
            $path === null ? $refused[] = $file->getClientOriginalName() : $stored[] = $path;
        }

        $redirect = redirect()->to(route('admin.landings.edit', $landing).'#files')
            ->with('status', trans_choice(':count file uploaded.|:count files uploaded.', count($stored)));

        return $refused === [] ? $redirect : $redirect->withErrors([
            'files' => __('Not taken (a type a landing does not show, or not what its name says): :names', ['names' => implode(', ', $refused)]),
        ]);
    }

    public function deleteFile(Request $request, Landing $landing, Files $files)
    {
        $files->delete($landing, (string) $request->input('path'));

        return redirect()->to(route('admin.landings.edit', $landing).'#files');
    }

    /** A ZIP into this landing — or, with no landing, into a new one named after the archive. */
    public function import(Request $request, Zip $zip, ?Landing $landing = null)
    {
        $request->validate(['archive' => ['required', 'file', 'max:'.(Zip::MAX_TOTAL / 1024)]]);

        $target = $landing ?? new Landing([
            'name' => pathinfo($request->file('archive')->getClientOriginalName(), PATHINFO_FILENAME),
            'enabled' => false,
            'html' => [],
        ]);
        $isNew = ! $target->exists;
        $target->save();

        try {
            $report = $zip->import($target, $request->file('archive')->getRealPath(), content_base_locale());
        } catch (RuntimeException $e) {
            if ($isNew) {
                $target->delete();
            }

            return back()->withErrors(['archive' => $e->getMessage()]);
        }

        $notes = [trans_choice(':count file taken.|:count files taken.', $report['files'])];
        if ($report['moved_styles'] > 0) {
            $notes[] = __('Styles moved from the markup to the CSS: :n.', ['n' => $report['moved_styles']]);
        }
        if ($report['removed'] !== []) {
            $notes[] = __('Left out of the HTML: :items.', ['items' => collect($report['removed'])->map(fn ($n, $tag) => '<'.$tag.'> × '.$n)->implode(', ')]);
        }
        if ($report['skipped'] !== []) {
            $notes[] = __('Files not taken: :names.', ['names' => implode(', ', array_slice($report['skipped'], 0, 10))]);
        }

        return redirect()->route('admin.landings.edit', $target)->with('status', ($isNew ? __('The landing is made from the archive; it is switched off until you look at it.') : __('The landing is replaced from the archive.')).' '.implode(' ', $notes));
    }

    public function export(Landing $landing, Zip $zip)
    {
        $file = $zip->export($landing, content_base_locale());

        return response()->download($file, 'landing-'.$landing->id.'.zip')->deleteFileAfterSend();
    }

    protected function fill(Landing $landing, Request $request): void
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'placement' => ['required', Rule::in(Landing::PLACEMENTS)],
            'tab_title' => ['nullable', 'string', 'max:190'],
            'sort' => ['nullable', 'integer', 'min:-9999', 'max:9999'],
            'space_top' => ['required', Rule::in(Landing::SPACES)],
            'space_bottom' => ['required', Rule::in(Landing::SPACES)],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'html' => ['nullable', 'string', 'max:1000000'],
            'css' => ['nullable', 'string', 'max:500000'],
            'picked' => ['array'],
            'picked.*' => ['integer'],
            'excluded' => ['array'],
            'excluded.*' => ['integer'],
        ]);

        $landing->fill([
            'name' => $data['name'],
            'enabled' => $request->boolean('enabled'),
            'placement' => $data['placement'],
            // ONE language per save — the content language picked at the top.
            'tab_title' => put_translation($landing->tab_title, (string) ($data['tab_title'] ?? '')),
            'html' => put_translation($landing->html, (string) ($data['html'] ?? '')),
            'sort' => (int) ($data['sort'] ?? 0),
            'space_top' => $data['space_top'],
            'space_bottom' => $data['space_bottom'],
            'fold' => $request->boolean('fold'),
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'css' => $data['css'] ?? null,
            'rules' => app(DisplayRules::class)->normalise($request->input('rules', [])),
            'rules_match' => $request->input('rules_match') === DisplayRules::MATCH_ANY ? DisplayRules::MATCH_ANY : DisplayRules::MATCH_ALL,
        ]);
    }

    /** Picked and left-out products — one row per product, "left out" winning a product in both lists. */
    protected function syncProducts(Landing $landing, Request $request): void
    {
        $excluded = array_values(array_unique(array_map('intval', (array) $request->input('excluded', []))));
        $picked = array_values(array_diff(array_unique(array_map('intval', (array) $request->input('picked', []))), $excluded));
        $known = Product::withTrashed()->whereIn('id', [...$picked, ...$excluded])->pluck('id')->flip();

        DB::table('m_landing_products')->where('landing_id', $landing->id)->delete();

        $rows = [];
        foreach ([[$picked, false], [$excluded, true]] as [$ids, $isExcluded]) {
            foreach ($ids as $id) {
                if (isset($known[$id])) {
                    $rows[] = ['landing_id' => $landing->id, 'product_id' => $id, 'excluded' => $isExcluded];
                }
            }
        }

        if ($rows !== []) {
            DB::table('m_landing_products')->insert($rows);
        }
    }

    protected function formData(Landing $landing): array
    {
        $locale = admin_content_locale();
        $links = $landing->exists
            ? DB::table('m_landing_products')->where('landing_id', $landing->id)->get(['product_id', 'excluded'])
            : collect();

        return [
            'landing' => $landing,
            'locale' => $locale,
            'picked' => $links->where('excluded', false)->pluck('product_id')->map(fn ($id) => (int) $id)->values()->all(),
            'excluded' => $links->where('excluded', true)->pluck('product_id')->map(fn ($id) => (int) $id)->values()->all(),
            'files' => $landing->exists ? app(Files::class)->of($landing) : [],
            // Named on the form: where "Look at it" opens is never a guess.
            'landingPreview' => $landing->exists ? app(PreviewProduct::class)->for($landing) : ['product' => null, 'for' => false],
        ];
    }
}
