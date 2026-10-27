<?php

namespace Torgash\Seo\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Torgash\Seo\Models\Template;
use Torgash\Seo\Subjects;
use Torgash\Seo\Templates;

class TemplatesController extends Controller
{
    public function __construct(protected Subjects $subjects) {}

    public function index(Request $request)
    {
        $locale = admin_content_locale();
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'subject' => array_key_exists((string) $request->query('subject'), $this->subjects->all()) ? (string) $request->query('subject') : '',
        ];

        // A shop has a handful of these: read once, searched in every text the
        // template writes (what the manager remembers is a phrase in it, not
        // an id), counted per kind of page for the tabs.
        $all = Template::orderBy('subject')->orderBy('position')->orderBy('id')->get();
        $searched = $filters['q'] === '' ? $all : $all->filter(function (Template $template) use ($filters, $locale) {
            foreach (['title', 'description', 'h1', 'image_alt', 'image_title'] as $field) {
                if (mb_stripos((string) $template->t($field, $locale), $filters['q']) !== false) {
                    return true;
                }
            }

            return false;
        });

        $subjectCounts = ['' => $searched->count()];
        foreach (array_keys($this->subjects->options()) as $kind) {
            $subjectCounts[$kind] = $searched->where('subject', $kind)->count();
        }

        return view('torgash.seo::admin.index', [
            'templates' => ($filters['subject'] === '' ? $searched : $searched->where('subject', $filters['subject']))->values(),
            'subjects' => $this->subjects,
            'filters' => $filters,
            'subjectCounts' => $subjectCounts,
            // The category's own name for the list — the picker's indent
            // means nothing outside the tree.
            'scopeNames' => array_map(
                fn (string $label) => preg_replace('/^(?:—\s*)+/u', '', $label),
                $this->categoryOptions(),
            ),
        ]);
    }

    public function create(Request $request)
    {
        // Opened from a tab of the list: the kind that tab was showing.
        $subject = array_key_exists((string) $request->query('subject'), $this->subjects->all()) ? (string) $request->query('subject') : null;

        return $this->form(new Template(['subject' => $subject, 'mode' => Template::MODE_FILL_EMPTY, 'scope' => 'global', 'enabled' => true]));
    }

    public function edit(Template $template)
    {
        return $this->form($template);
    }

    public function store(Request $request)
    {
        $template = Template::create($this->data($request));

        return $this->afterSave($request, $template, __('Template created.'));
    }

    public function update(Request $request, Template $template)
    {
        $template->update($this->data($request, $template));

        return $this->afterSave($request, $template, __('Template saved.'));
    }

    public function destroy(Template $template)
    {
        $template->delete();
        app(Templates::class)->flush();

        return redirect()->route('admin.seo.index')->with('status', __('Template deleted.'));
    }

    /**
     * A copy arrives SWITCHED OFF: two live templates for the same pages and
     * the same scope means the first by position silently wins, and a copy is
     * usually the start of a variant for another category.
     */
    public function duplicate(Template $template)
    {
        $copy = $template->replicate(['created_at', 'updated_at']);
        $copy->enabled = false;
        $copy->save();

        return redirect()->route('admin.seo.edit', $copy)
            ->with('status', __('Template copied. The copy is off until you switch it on.'));
    }

    /** The list's in-place switch: answers with the cell. */
    public function setStatus(Request $request, Template $template)
    {
        $request->validate(['enabled' => 'required|boolean']);
        $template->update(['enabled' => $request->boolean('enabled')]);
        app(Templates::class)->flush();

        return view('torgash.seo::admin._status', ['template' => $template]);
    }

    public function bulk(Request $request)
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['enable', 'disable', 'delete'])],
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        // Model by model, never a query update: the guard's audit hears
        // Eloquent events.
        $templates = Template::whereIn('id', $validated['ids'])->get();

        if ($validated['action'] === 'delete') {
            $templates->each->delete();
            $message = trans_choice(':count template deleted.|:count templates deleted.', $templates->count(), ['count' => $templates->count()]);
        } else {
            $templates->each(fn (Template $template) => $template->update(['enabled' => $validated['action'] === 'enable']));
            $message = trans_choice(':count template updated.|:count templates updated.', $templates->count(), ['count' => $templates->count()]);
        }

        app(Templates::class)->flush();

        return back()->with('status', $message);
    }

    /**
     * What the template being typed would print on one real page it covers —
     * the form's own values, unsaved, through the very call the storefront
     * makes, so the preview cannot promise a title the page will not print.
     */
    public function preview(Request $request)
    {
        $kind = (string) $request->input('subject', '');
        $definition = $this->subjects->find($kind);
        $locale = admin_content_locale();
        $template = new Template([
            'subject' => $kind,
            'scope' => (string) $request->input('scope', 'global'),
            'mode' => (string) $request->input('mode', Template::MODE_FILL_EMPTY),
        ]);

        foreach (['title', 'description', 'h1'] as $field) {
            $template->{$field} = [$locale => (string) $request->input($field, '')];
        }

        $examples = $definition && $definition['query'] ? $this->examples($definition, $template) : collect();
        $index = $examples->isEmpty() ? 0 : max(0, (int) $request->input('example', 0)) % $examples->count();
        $example = $examples->get($index);
        $rendered = null;
        $exampleTitle = null;

        if ($example) {
            // The text of the language being edited, expanded in that language
            // — a Russian template previewed with English category names reads
            // as a bug that is not there. The page's own name too, or the
            // label above the preview names a different product than it.
            $interface = app()->getLocale();
            app()->setLocale($locale);

            try {
                $exampleTitle = ($definition['title'])($example);

                if ($template->mode !== Template::MODE_SKIP) {
                    $rendered = app(Templates::class)->render($template, $example);
                }
            } finally {
                app()->setLocale($interface);
            }
        }

        return view('torgash.seo::admin._preview', [
            'template' => $template,
            'example' => $example,
            'exampleTitle' => $exampleTitle,
            'exampleUrl' => $example ? ($definition['url'])($example) : null,
            'rendered' => $rendered,
            'index' => $index,
            'count' => $examples->count(),
            'descriptionLimit' => (int) setting('torgash.seo', 'description_length'),
        ]);
    }

    /**
     * A few pages the template covers: inside its category when it has one
     * (asked of the record's own relation, the way Templates::categoryChain()
     * asks — this module names no catalogue class), else simply the first.
     */
    protected function examples(array $definition, Template $template)
    {
        $query = ($definition['query'])();
        $categoryId = $template->categoryId();

        if ($categoryId !== null) {
            $model = $query->getModel();

            if ($template->subject === 'category') {
                $query->whereKey($categoryId);
            } elseif (method_exists($model, 'categories')) {
                $query->whereHas('categories', fn ($q) => $q->whereKey($categoryId));
            } elseif (method_exists($model, 'category')) {
                $query->whereHas('category', fn ($q) => $q->whereKey($categoryId));
            }
        }

        return $query->limit(10)->get()->values();
    }

    /** Back to the form, as every edit form here does; "Save and close" is the way out. */
    protected function afterSave(Request $request, Template $template, string $message)
    {
        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.seo.index')
            : redirect()->route('admin.seo.edit', $template))->with('status', $message);
    }

    protected function form(Template $template)
    {
        return view('torgash.seo::admin.form', [
            'template' => $template,
            'subjects' => $this->subjects,
            'categories' => $this->categoryOptions(),
        ]);
    }

    /**
     * The categories a template may be scoped to.
     *
     * Read off the SEO subject registry rather than by naming the catalogue's
     * model: with no catalogue installed there are no categories, and the
     * scope select is simply the shop-wide option on its own.
     *
     * @return array<string, string>
     */
    protected function categoryOptions(): array
    {
        $definition = $this->subjects->find('category');

        if (! $definition) {
            return [];
        }

        $options = [];

        // The kind's own picker list when it offers one — the catalogue answers
        // with its tree, indented and in tree order, because a flat list of
        // forty categories is one in which nobody can find the right "Coffee".
        if ($definition['options'] ?? null) {
            foreach (($definition['options'])() as $id => $label) {
                $options['category:'.$id] = $label;
            }

            return $options;
        }

        if (! $definition['query']) {
            return [];
        }

        foreach (($definition['query'])()->get() as $category) {
            $options['category:'.$category->getKey()] = ($definition['title'])($category);
        }

        return $options;
    }

    /** @return array<string, mixed> */
    protected function data(Request $request, ?Template $template = null): array
    {
        $locale = admin_content_locale();

        $validated = $request->validate([
            'subject' => ['required', 'string', Rule::in(array_keys($this->subjects->all()))],
            'scope' => ['required', 'string', 'max:64'],
            'mode' => ['required', Rule::in(Template::MODES)],
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:500',
            'h1' => 'nullable|string|max:255',
            'image_alt' => 'nullable|string|max:255',
            'image_title' => 'nullable|string|max:255',
            'position' => 'nullable|integer',
        ]);

        $data = [
            'subject' => $validated['subject'],
            'scope' => $validated['scope'],
            'mode' => $validated['mode'],
            'position' => (int) ($validated['position'] ?? 0),
            'enabled' => $request->boolean('enabled'),
        ];

        // Each text is one language of a jsonb column, merged rather than
        // replaced — the same rule every translatable field in the admin
        // follows, and the reason a Russian edit does not wipe the English.
        foreach (['title', 'description', 'h1', 'image_alt', 'image_title'] as $field) {
            $values = (array) ($template?->{$field} ?? []);
            $typed = trim($validated[$field] ?? '');

            if ($typed === '') {
                unset($values[$locale]);
            } else {
                $values[$locale] = $typed;
            }

            $data[$field] = $values === [] ? null : $values;
        }

        // The memo is a snapshot of the table as it was read; a screen that has
        // just saved a template must not answer the next request from it.
        app(Templates::class)->flush();

        return $data;
    }
}
