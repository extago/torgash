<?php

namespace Torgash\Pages\Http\Admin;

use App\Core\Display\DisplayRules;
use App\Core\Seo\MetaFields;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Torgash\Pages\Models\Page;

class PagesController extends Controller
{
    public function index(Request $request)
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => in_array($request->query('status'), ['published', 'draft'], true) ? (string) $request->query('status') : '',
        ];

        // Ordered by ADDRESS, which puts a section's pages under it and in the
        // order a visitor meets them. A shop has dozens of pages, so the search
        // runs in PHP over the title in every language and the address.
        $all = Page::orderBy('path')->get();
        $found = $filters['q'] === '' ? $all : $all->filter(fn (Page $page) => collect((array) $page->title)
            ->push($page->path)
            ->contains(fn ($text) => mb_stripos((string) $text, $filters['q']) !== false));

        return view('torgash.pages::admin.index', [
            'pages' => (match ($filters['status']) {
                'published' => $found->where('enabled', true),
                'draft' => $found->where('enabled', false),
                default => $found,
            })->values(),
            'filters' => $filters,
            'statusCounts' => [
                '' => $found->count(),
                'published' => $found->where('enabled', true)->count(),
                'draft' => $found->where('enabled', false)->count(),
            ],
        ]);
    }

    /** The list's in-place switch: answers with the status cell. */
    public function setStatus(Request $request, Page $page)
    {
        $request->validate(['enabled' => 'required|boolean']);
        $page->update(['enabled' => $request->boolean('enabled')]);

        return view('torgash.pages::admin._status', ['page' => $page]);
    }

    /**
     * Several at once, through the models: publishing is audited, and a
     * deleted section lifts its children into its place (Page::booted) — so
     * each is re-read before it goes, in case an earlier delete just moved it.
     */
    public function bulk(Request $request)
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['publish', 'unpublish', 'delete'])],
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $count = 0;
        foreach (array_unique($validated['ids']) as $id) {
            if (! $page = Page::find($id)) {
                continue;
            }

            $validated['action'] === 'delete'
                ? $page->delete()
                : $page->update(['enabled' => $validated['action'] === 'publish']);
            $count++;
        }

        return back()->with('status', $validated['action'] === 'delete'
            ? trans_choice(':count page deleted.|:count pages deleted.', $count, ['count' => $count])
            : trans_choice(':count page updated.|:count pages updated.', $count, ['count' => $count]));
    }

    public function create()
    {
        return view('torgash.pages::admin.form', [
            'page' => new Page(['enabled' => true, 'in_sitemap' => true, 'show_section_nav' => true]),
            'parents' => $this->parentOptions(null),
        ]);
    }

    public function store(Request $request)
    {
        $page = Page::create($this->data($request));
        hook_action('pages.page.saved', ['record' => $page, 'request' => $request]);

        return $this->saved($request, $page, __('Page created.'));
    }

    public function edit(Page $page)
    {
        return view('torgash.pages::admin.form', [
            'page' => $page,
            'parents' => $this->parentOptions($page),
        ]);
    }

    /**
     * Pages this one may be put under: everything except itself and its own
     * descendants. Offering those would be offering a cycle, and the model
     * refuses one anyway — better not to draw the trap.
     *
     * @return array<int, string>
     */
    protected function parentOptions(?Page $page): array
    {
        $excluded = [];

        if ($page?->exists) {
            $excluded = $this->subtreeIds($page);
        }

        return Page::orderBy('path')->get()
            ->reject(fn (Page $candidate) => in_array($candidate->id, $excluded, true))
            ->mapWithKeys(fn (Page $candidate) => [
                $candidate->id => str_repeat('— ', substr_count($candidate->path, '/'))
                    .($candidate->t('title', admin_content_locale()) ?: $candidate->slug),
            ])
            ->all();
    }

    /** @return list<int> */
    protected function subtreeIds(Page $page): array
    {
        $ids = [$page->id];

        foreach ($page->children as $child) {
            $ids = [...$ids, ...$this->subtreeIds($child)];
        }

        return $ids;
    }

    public function update(Request $request, Page $page)
    {
        // The 301 is not written here: a rename moves every DESCENDANT's
        // address too, and only the model sees those. See Page::booted().
        $page->update($this->data($request, $page));
        // What another module drew on the form (pages.page.form_sections) is read back here.
        hook_action('pages.page.saved', ['record' => $page, 'request' => $request]);

        return $this->saved($request, $page, __('Page saved.'));
    }

    /** Saving stays on the form; "Save and close" in the gear goes back to the list. */
    protected function saved(Request $request, Page $page, string $message)
    {
        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.pages.index')
            : redirect()->route('admin.pages.edit', $page))->with('status', $message);
    }

    public function destroy(Page $page)
    {
        $page->delete();

        return redirect()->route('admin.pages.index')->with('status', __('Page deleted.'));
    }

    /**
     * The SEO card posts `page_title`; the column has been called that since
     * pages existed, and renaming it would rewrite every page a shop wrote.
     */
    protected const META_COLUMNS = [
        'page_title' => 'page_title',
        'meta_description' => 'meta_description',
        'h1' => 'h1',
    ];

    /** @return array<string, mixed> */
    protected function data(Request $request, ?Page $page = null): array
    {
        $locale = admin_content_locale();

        $validated = $request->validate([
            'title' => 'nullable|string|max:190',
            'slug' => 'nullable|string|max:190',
            'body' => 'nullable|string',
            'position' => 'nullable|integer',
            'parent_id' => 'nullable|integer|exists:m_pages_pages,id',
            // The SEO card's own fields, under this module's column names.
        ] + MetaFields::rules(self::META_COLUMNS));

        $title = $this->translatable($page?->title, $validated['title'] ?? '', $locale);

        $parentId = ($validated['parent_id'] ?? null) ?: null;

        return [
            'title' => $title,
            // The section this page sits in. `path` is derived from it on save
            // (Page::booted), never typed.
            'parent_id' => $parentId,
            'slug' => $this->slug($validated['slug'] ?? '', $title, $page, $parentId),
            'body' => $this->translatable($page?->body, $validated['body'] ?? '', $locale),
            'enabled' => $request->boolean('enabled'),
            'in_sitemap' => $request->boolean('in_sitemap'),
            'show_section_nav' => $request->boolean('show_section_nav'),
            'position' => (int) ($validated['position'] ?? 0),
            // Where it is shown, through core's own engine — normalised there
            // too, because a rule the engine cannot evaluate would silently
            // hide the page everywhere.
            'rules' => app(DisplayRules::class)->normalise($request->input('rules', [])),
            'rules_match' => $request->input('rules_match') === DisplayRules::MATCH_ANY
                ? DisplayRules::MATCH_ANY
                : DisplayRules::MATCH_ALL,
        ] + MetaFields::attributes($request, $page, self::META_COLUMNS);
    }

    /**
     * A translated string edited one locale at a time — blank means "not
     * touched in this language", never "cleared everywhere", which is what a
     * plain assignment would do the moment someone saves the form with the
     * content switcher on RU.
     *
     * @param  array<string, string>|null  $current
     * @return array<string, string>
     */
    protected function translatable(?array $current, string $value, string $locale): array
    {
        // Core's one rule: blank removes this language, the others stay.
        return put_translation($current, $value, $locale);
    }

    /**
     * The page's own SEGMENT of the address (the full one is built from the
     * parent — see Page::booted). Derived from the title when left empty, and
     * made unique among its siblings by suffix rather than by refusing the
     * save.
     *
     * @param  array<string, string>  $title
     */
    protected function slug(string $typed, array $title, ?Page $page, ?int $parentId): string
    {
        $base = $typed !== '' ? $typed : ($page?->slug ?? '');

        if ($base === '') {
            $baseLocale = content_base_locale();
            $base = $title[$baseLocale] ?? (reset($title) ?: '');
        }

        // A title in an alphabet the transliterator does not know leaves
        // nothing to build an address from; the row still needs one, so it
        // gets a stable stand-in the merchant can overwrite.
        $slug = Str::slug($base) ?: 'page-'.($page?->id ?? (Page::max('id') + 1));

        // Uniqueness is per SIBLING now, not shop-wide: two sections may each
        // have a "delivery" page, and what must not collide is the full
        // address — which is what the `path` column carries a unique index on.
        $candidate = $slug;
        for ($n = 2; Page::where('slug', $candidate)
            ->where('parent_id', $parentId)
            ->where('id', '!=', $page?->id)->exists(); $n++) {
            $candidate = $slug.'-'.$n;
        }

        return $candidate;
    }
}
