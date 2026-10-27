<?php

namespace Torgash\Seo\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Torgash\Seo\Models\Template;
use Torgash\Seo\Subjects;
use Torgash\Seo\Templates;

/**
 * One screen answering "what is still empty, and what would the template say
 * about it" — and filling it in, either by hand or in bulk.
 *
 * This is the half of the usual SEO add-ons that was a screen: a custom-H1
 * filled/not-filled list and bulk metadata editing. One
 * screen rather than two because it is one question — a shop looking at empty
 * H1s is looking for empty titles in the same breath.
 *
 * What it writes goes into the RECORD's own columns, never into a table of this
 * module's: filling in from a template is a decision taken once, and the answer
 * has to survive the module being removed (PLAN §30.3).
 */
class AuditController extends Controller
{
    public function __construct(protected Subjects $subjects, protected Templates $templates) {}

    public function index(Request $request)
    {
        $kind = (string) $request->input('subject', array_key_first($this->subjects->all()) ?? '');
        $definition = $this->subjects->find($kind);

        if (! $definition || ! $definition['query']) {
            return view('torgash.seo::admin.audit', [
                'subjects' => $this->subjects,
                'kind' => $kind,
                'rows' => null,
            ]);
        }

        $query = ($definition['query'])();
        $columns = $definition['columns'];
        $locale = admin_content_locale();

        $term = trim((string) $request->input('q', ''));

        if ($term !== '') {
            // Whatever the owner calls a row, the column behind it is jsonb, so
            // the search is over the text of the current content locale.
            // Grouped: the kind's own query may already carry conditions (a
            // brand is a value WITH a slug), and a bare orWhere would walk
            // straight past them.
            $query->where(fn ($q) => $q
                ->whereRaw("coalesce({$columns['title']}->>?, '') ilike ?", [$locale, '%'.$term.'%'])
                ->orWhereRaw('cast(id as text) = ?', [$term]));
        }

        $emptyTitle = fn ($q, string $operator) => $q->whereRaw("coalesce({$columns['title']}->>?, '') {$operator} ''", [$locale]);

        // The tabs count what the search leaves, so a number never promises
        // rows the table below will not show.
        $counts = [
            '' => (clone $query)->toBase()->getCountForPagination(),
            'empty' => $emptyTitle(clone $query, '=')->toBase()->getCountForPagination(),
        ];
        $counts['filled'] = $counts[''] - $counts['empty'];

        // "Empty" is the state the screen exists for, so the tab says how many
        // are left; "All" stays the default, because a shop also opens this
        // to read what it has.
        $filter = in_array($request->input('filled'), ['empty', 'filled'], true) ? (string) $request->input('filled') : '';

        if ($filter !== '') {
            $emptyTitle($query, $filter === 'empty' ? '=' : '<>');
        }

        $rows = $query->paginate(30)->withQueryString();

        // How many titles are still missing on each kind of page — the reason
        // somebody opens the screen, answered before they pick a tab.
        $emptyPerKind = [];
        foreach ($this->subjects->all() as $otherKind => $other) {
            if ($other['query']) {
                $emptyPerKind[$otherKind] = ($other['query'])()
                    ->whereRaw("coalesce({$other['columns']['title']}->>?, '') = ''", [$locale])
                    ->toBase()->getCountForPagination();
            }
        }

        return view('torgash.seo::admin.audit', [
            'subjects' => $this->subjects,
            'templates' => $this->templates,
            'kind' => $kind,
            'definition' => $definition,
            'rows' => $rows,
            'locale' => $locale,
            'filter' => $filter,
            'term' => $term,
            'counts' => $counts,
            'emptyPerKind' => $emptyPerKind,
            'descriptionLimit' => (int) setting('torgash.seo', 'description_length'),
        ]);
    }

    /**
     * Save what was typed, and — when the button asked for it — fill the ticked
     * rows in from the template that governs each of them.
     *
     * Both on one submit because they are one act on one screen: a manager
     * fixes three titles by hand and lets the template do the other twenty-
     * seven. The typed values win over the template for the same row, which is
     * the only order that cannot lose a keystroke.
     */
    public function save(Request $request)
    {
        $kind = (string) $request->input('subject', '');
        $definition = $this->subjects->find($kind);

        abort_unless($definition && $definition['query'], 404);

        $locale = admin_content_locale();
        $columns = $definition['columns'];
        $typed = (array) $request->input('rows', []);
        $selected = array_map('intval', (array) $request->input('selected', []));
        $fillFromTemplate = $request->input('action') === 'template';
        $changed = 0;

        $models = ($definition['query'])()
            ->whereIn('id', array_unique([...array_map('intval', array_keys($typed)), ...$selected]))
            ->get();

        foreach ($models as $model) {
            $values = [];

            if ($fillFromTemplate && in_array((int) $model->getKey(), $selected, true)) {
                $values = $this->fromTemplate($model);
            }

            foreach ((array) ($typed[$model->getKey()] ?? []) as $field => $text) {
                $values[$field] = trim((string) $text);
            }

            if ($this->write($model, $values, $columns, $locale)) {
                $changed++;
            }
        }

        return redirect()->back()->with('status', trans_choice(
            '{0}Nothing to change.|{1}One record updated.|[2,*]:count records updated.',
            $changed,
            ['count' => $changed],
        ));
    }

    /**
     * What the template governing this record would say — the same call the
     * storefront makes, so the screen cannot promise a title the page will not
     * print.
     *
     * @return array<string, string>
     */
    protected function fromTemplate(object $model): array
    {
        $template = $this->templates->for($model);

        if ($template === null || $template->mode === Template::MODE_SKIP) {
            return [];
        }

        return array_filter($this->templates->render($template, $model));
    }

    /**
     * @param  array<string, string>  $values
     * @param  array<string, string>  $columns
     */
    protected function write(object $model, array $values, array $columns, string $locale): bool
    {
        $attributes = [];

        foreach ($values as $field => $text) {
            if (! isset($columns[$field])) {
                continue;
            }

            $column = $columns[$field];
            $current = (array) ($model->{$column} ?? []);

            // An empty box CLEARS this language rather than storing "" — the
            // same rule as the form, and the reason a cleared description stops
            // being printed instead of being printed empty.
            if ($text === '') {
                unset($current[$locale]);
            } else {
                $current[$locale] = $text;
            }

            $attributes[$column] = $current === [] ? null : $current;
        }

        if ($attributes === []) {
            return false;
        }

        $model->fill($attributes);

        return $model->isDirty() && $model->save();
    }
}
