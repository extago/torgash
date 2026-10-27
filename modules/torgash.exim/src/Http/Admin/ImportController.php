<?php

namespace Torgash\Exim\Http\Admin;

use App\Core\Files\FileStorage;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Torgash\Exim\Import\Columns;
use Torgash\Exim\Import\ImportFile;
use Torgash\Exim\Import\XmlFile;
use Torgash\Exim\Import\Formula;
use Torgash\Exim\Import\FormulaException;
use Torgash\Exim\ImportRunner;
use Torgash\Exim\Jobs\RunImport;
use Torgash\Exim\Models\ImportDraft;
use Torgash\Exim\Models\ImportPreset;
use Torgash\Exim\SchemaRegistry;

/**
 * Upload → match the columns → check → import (PLAN §58): the file is read
 * the way it was written, every
 * column is either one of our fields or knowingly skipped, and the way a file
 * was read is kept as a preset for the next one.
 *
 * An upload is a DRAFT (`ImportDraft`) until it is imported or deleted: the
 * file waits on the private disk, the row keeps the mapping and options as the
 * admin picks them (the screen saves every change), and the import screen
 * lists the unfinished ones. The import job reads the file itself and deletes
 * it; a draft nobody touched for a week is swept with its file.
 */
class ImportController extends Controller
{
    public function index(SchemaRegistry $schema)
    {
        return view('torgash.exim::admin.import.index', [
            'entities' => $this->entities($schema),
            'presets' => ImportPreset::query()->orderBy('entity')->orderBy('name')->get(),
            'drafts' => ImportDraft::query()->with('user')->latest('updated_at')->get(),
        ]);
    }

    public function upload(Request $request)
    {
        $validated = $request->validate([
            'entity' => 'required|string',
            // By extension, not by the browser's guess: a YML is "text/plain"
            // to one and "application/octet-stream" to another.
            'file' => ['required', 'file', function ($attribute, $file, $fail) {
                if (! in_array(strtolower((string) $file->getClientOriginalExtension()), ImportFile::EXTENSIONS, true)) {
                    $fail(__('Upload a CSV or an XML/YML file.'));
                }
            }],
        ]);

        // The scheduler does this daily; here too, for a shop whose scheduler is not running.
        ImportDraft::sweep();

        $token = Str::random(32);
        $name = $token.'.'.strtolower((string) $validated['file']->getClientOriginalExtension());
        Storage::disk('private')->putFileAs(ImportDraft::DIR, $validated['file'], $name);

        ImportDraft::create([
            'token' => $token,
            'entity' => $validated['entity'],
            'path' => ImportDraft::DIR.'/'.$name,
            'file_name' => mb_substr($validated['file']->getClientOriginalName(), 0, 255),
            'user_id' => $request->user()?->id,
        ]);

        return redirect()->route('admin.exim.import.map', ['token' => $token]);
    }

    /** The mapping screen: every column of the file, a few of its values, and the field it goes to. */
    public function map(Request $request, string $token, ImportRunner $runner, SchemaRegistry $schema)
    {
        $draft = $this->draft($token);

        if (! $draft) {
            return $this->expired();
        }

        // The screen's own small form sends `preset` (chosen, or "none") and,
        // unless it was the preset that changed, the delimiter and encoding.
        $chosenPreset = $request->has('preset');
        $preset = $request->filled('preset')
            ? ImportPreset::where('entity', $draft->entity)->find($request->integer('preset'))
            : null;
        $reread = $chosenPreset || $request->has('delimiter') || $request->has('encoding') || $request->has('xml_record');

        return $this->withFile($draft, function (string $local) use ($request, $draft, $preset, $chosenPreset, $reread, $runner, $schema) {
            $saved = (array) ($draft->options ?? []);
            // A preset chosen just now beats what the draft had; a delimiter
            // or encoding changed just now beats both.
            $options = array_merge(ImportRunner::DEFAULT_OPTIONS, $saved, (array) ($preset?->options ?? []), $request->only(['delimiter', 'encoding', 'xml_record']));

            $file = ImportFile::read($local, $options);
            $header = $file->header();

            // Otherwise the preset saved for this header, if any — under what
            // the admin already changed in this draft.
            if (! $chosenPreset && ! $reread && ($preset = ImportPreset::forHeader($draft->entity, $header))) {
                $options = array_merge(ImportRunner::DEFAULT_OPTIONS, (array) $preset->options, $saved);
                $file = ImportFile::read($local, $options);
                $header = $file->header();
            }

            $known = $runner->fields($draft->entity);
            $fieldDefs = array_values($known);
            // The draft's mapping as the admin left it — unless the file is
            // now read differently and its columns are not the same any more.
            $asLeft = ! $reread && is_array($draft->mapping) && count($draft->mapping) === count($header);
            $mapping = $asLeft ? $draft->mapping : Columns::guess($header, $fieldDefs, $preset);
            // A preset keeps formulas by column name, a draft by position.
            $options['formulas'] = $asLeft
                ? (array) ($saved['formulas'] ?? [])
                : Columns::formulasByIndex($header, (array) ($preset?->options['formulas'] ?? []));
            $sample = $file->sample(3);

            $options = array_merge($options, $file->settings());
            $rowCount = $file->count();

            // What the screen shows IS the draft: reopened, it looks the same.
            $draft->update(['mapping' => $mapping, 'options' => $options, 'row_count' => $rowCount, 'column_count' => count($header)]);

            return view('torgash.exim::admin.import.map', [
                'token' => $draft->token,
                'entity' => $draft->entity,
                'entityLabel' => $this->entities($schema)[$draft->entity] ?? $draft->entity,
                'fileName' => $draft->file_name,
                'header' => $header,
                'sample' => $sample,
                'formulas' => $options['formulas'],
                'formulaSamples' => collect($options['formulas'])
                    ->map(fn ($text, $i) => $this->formulaSamples($header, $sample, (int) $i, (string) $text))
                    ->all(),
                'rowCount' => $rowCount,
                'mapping' => $mapping,
                // Each column's choice as the screen shows it: the field, and
                // for a translatable one the language it fills (or `*`).
                'chosen' => array_map(fn ($key) => $this->chosen((string) $key, $known), $mapping),
                'choices' => $this->choices($fieldDefs),
                'groups' => SchemaRegistry::GROUPS,
                'locales' => $schema->locales(),
                'baseLocale' => $this->defaultLocale($schema),
                'xml' => $file instanceof XmlFile,
                'delimiter' => $file->settings()['delimiter'] ?? null,
                'encoding' => $file->settings()['encoding'] ?? null,
                'xmlRecord' => $file->settings()['xml_record'] ?? null,
                'xmlRecords' => $file instanceof XmlFile ? XmlFile::candidates($file->path) : [],
                'options' => $options,
                'preset' => $preset,
                'presets' => ImportPreset::where('entity', $draft->entity)->orderBy('name')->get(),
            ]);
        });
    }

    /** The screen saving itself as the admin picks: the draft keeps the mapping without "Check". */
    public function save(Request $request, string $token, ImportRunner $runner, SchemaRegistry $schema)
    {
        $draft = $this->draft($token);

        if (! $draft) {
            return response()->noContent(410);
        }

        [$mapping, $options] = $this->fromForm($request, $draft, $runner, $schema);

        $draft->update([
            'mapping' => $draft->column_count !== null ? array_slice(array_pad($mapping, $draft->column_count, ''), 0, $draft->column_count) : $mapping,
            'options' => array_merge((array) ($draft->options ?? []), $options),
        ]);

        return response()->noContent();
    }

    /** The mapping chosen: kept with the draft (and as a preset, if named), then what it would do. */
    public function preview(Request $request, ImportRunner $runner, SchemaRegistry $schema)
    {
        $request->validate(['token' => 'required|string']);
        $draft = $this->draft((string) $request->input('token'));

        if (! $draft) {
            return $this->expired();
        }

        [$mapping, $options, $validated] = $this->fromForm($request, $draft, $runner, $schema);

        if (! in_array(true, array_map(fn ($key) => $key !== '', $mapping), true)) {
            return redirect()->route('admin.exim.import.map', ['token' => $draft->token])
                ->withErrors(['map' => __('Choose a field for at least one column.')]);
        }

        // A formula that cannot be read is never imported as if it were not
        // there: the bare purchase price would go in as the price.
        foreach ($options['formulas'] as $i => $text) {
            try {
                Formula::parse($text);
            } catch (FormulaException $e) {
                $draft->update(['mapping' => $mapping, 'options' => array_merge((array) ($draft->options ?? []), $options)]);

                return redirect()->route('admin.exim.import.map', ['token' => $draft->token])
                    ->withErrors(['map' => __('The formula of column :n: :problem', ['n' => $i + 1, 'problem' => $e->describe()])]);
            }
        }

        return $this->withFile($draft, function (string $local) use ($draft, $mapping, $options, $validated, $runner, $schema) {
            $file = ImportFile::read($local, $options);
            $header = $file->header();
            // The guess made concrete: what the job reads with is what was shown.
            $options = array_merge($options, $file->settings());

            $mapping = array_slice(array_pad($mapping, count($header), ''), 0, count($header));

            $preset = $this->savePreset($draft->entity, $header, $mapping, $options, $validated);

            // Back from the check shows this mapping again, and Import runs exactly it.
            $draft->update(['mapping' => $mapping, 'options' => $options]);

            $result = $runner->preview($file, $draft->entity, $mapping, $options);

            return view('torgash.exim::admin.import.preview', [
                'token' => $draft->token,
                'entity' => $draft->entity,
                'entityLabel' => $this->entities($schema)[$draft->entity] ?? $draft->entity,
                'fieldDefs' => $result['fieldDefs'],
                'rows' => $result['rows'],
                'counts' => $result['counts'],
                'fallbacks' => $result['fallbacks'],
                'total' => $result['total'],
                'problems' => $result['problems'],
                'preset' => $preset,
            ]);
        });
    }

    /**
     * A column's values worked out by the formula being typed — the mapping
     * screen's "Values" cell, redrawn as the admin types (HTMX).
     */
    public function formula(Request $request, string $token)
    {
        $draft = $this->draft($token);
        $column = $request->integer('column');

        if (! $draft) {
            return response()->noContent(410);
        }

        return $this->withFile($draft, function (string $local) use ($request, $draft, $column) {
            $options = (array) ($draft->options ?? []);
            $file = ImportFile::read($local, $options);
            $sample = $file->sample(3);
            $text = (string) ($request->input('formula.'.$column) ?? '');

            return view('torgash.exim::admin.import._values', [
                'columnIndex' => $column,
                'sample' => $sample,
                'formulaSample' => trim($text) === '' ? null : $this->formulaSamples($file->header(), $sample, $column, $text),
            ]);
        });
    }

    public function commit(Request $request)
    {
        $draft = $this->draft((string) $request->input('token'));

        if (! $draft || $draft->mappedCount() === 0) {
            return $this->expired();
        }

        RunImport::start($draft->entity, $draft->path, $draft->mapping, $draft->options ?? []);
        // The file now belongs to the job, which deletes it when it is done.
        $draft->delete();

        return redirect()->route('admin.exim.import.index')
            ->with('status', __('Import queued — follow it under Background tasks.'));
    }

    public function destroy(ImportDraft $draft)
    {
        $draft->discard();

        return redirect()->route('admin.exim.import.index')->with('status', __('Unfinished import deleted.'));
    }

    public function destroyPreset(ImportPreset $preset)
    {
        $preset->delete();

        return redirect()->route('admin.exim.import.index')->with('status', __('Import preset deleted.'));
    }

    /**
     * The mapping screen's form, read: [mapping, options, validated]. The
     * screen's field + language are turned back into keys, and a key nobody
     * declares is dropped.
     */
    protected function fromForm(Request $request, ImportDraft $draft, ImportRunner $runner, SchemaRegistry $schema): array
    {
        $validated = $request->validate([
            'map' => 'array',
            'map.*' => 'nullable|string',
            'lang' => 'array',
            'lang.*' => 'nullable|string|max:16',
            'delimiter' => 'nullable|string|max:2',
            'encoding' => 'nullable|string|max:32',
            'xml_record' => 'nullable|string|max:190',
            'category_delimiter' => 'nullable|string|max:8',
            'images_mode' => 'nullable|in:empty,missing,replace',
            'images_base' => 'nullable|string|max:500',
            'create_new' => 'nullable|boolean',
            'preset_name' => 'nullable|string|max:120',
            'preset_id' => 'nullable|integer',
            'formula' => 'array',
            'formula.*' => 'nullable|string|max:'.Formula::MAX_LENGTH,
        ]);

        $known = $runner->fields($draft->entity);
        $languages = (array) ($validated['lang'] ?? []);
        $mapping = [];
        foreach (array_values((array) ($validated['map'] ?? [])) as $i => $choice) {
            $key = $this->composed((string) $choice, $languages[$i] ?? null, $known, $schema);
            $mapping[] = Columns::targets($key, $known) !== [] ? $key : '';
        }

        $options = [
            'delimiter' => $validated['delimiter'] ?? null,
            'encoding' => $validated['encoding'] ?? null,
            'xml_record' => $validated['xml_record'] ?? null,
            'category_delimiter' => ($validated['category_delimiter'] ?? '') !== '' ? $validated['category_delimiter'] : ImportRunner::DEFAULT_OPTIONS['category_delimiter'],
            'images_mode' => $validated['images_mode'] ?? ImportRunner::DEFAULT_OPTIONS['images_mode'],
            'images_base' => trim((string) ($validated['images_base'] ?? '')),
            'create_new' => $request->boolean('create_new'),
            // Only the columns that are imported: a skipped column's formula does nothing.
            'formulas' => array_filter(
                array_map(fn ($text) => trim((string) $text), (array) ($validated['formula'] ?? [])),
                fn ($text, $i) => $text !== '' && ($mapping[(int) $i] ?? '') !== '',
                ARRAY_FILTER_USE_BOTH,
            ),
        ];

        return [$mapping, $options, $validated];
    }

    /** @return array<string, string> key => English label */
    protected function entities(SchemaRegistry $schema): array
    {
        return $schema->entities() ?: ['product' => 'Product', 'category' => 'Category', 'feature' => 'Feature'];
    }

    /**
     * What the field picker lists: a translatable field ONCE ("Name" — its
     * language is the row's second choice), every other field as it is; each
     * with the words it is found by (its key, its spellings elsewhere, a
     * characteristic's id).
     *
     * @return array<string, list<array{key: string, title: string, hint: ?string, help: ?string, translatable: bool, search: string}>>  by group
     */
    protected function choices(array $fieldDefs): array
    {
        $choices = [];

        foreach ($fieldDefs as $field) {
            $base = $field['base_key'] ?? null;
            $key = $base ?? $field['key'];
            $words = [$field['key'], $field['label'], ...(array) ($field['aliases'] ?? [])];

            if (isset($choices[$key])) {
                $choices[$key]['words'] = [...$choices[$key]['words'], ...$words];

                continue;
            }

            $title = $base !== null ? __($field['base_label']) : SchemaRegistry::title($field);
            $choices[$key] = [
                'key' => $key,
                'group' => isset(SchemaRegistry::GROUPS[$field['group'] ?? 'main']) ? ($field['group'] ?? 'main') : 'other',
                'title' => $title,
                'hint' => $field['hint'] ?? null,
                'help' => ($field['help'] ?? null) ? __($field['help']) : null,
                'translatable' => $base !== null,
                'words' => [$title, $field['hint'] ?? '', ...$words],
            ];
        }

        $grouped = [];
        foreach ($choices as $choice) {
            $choice['search'] = mb_strtolower(implode(' ', array_unique(array_filter(array_map('strval', $choice['words'])))));
            unset($choice['words']);
            $grouped[$choice['group']][] = $choice;
        }

        // In the order of the profile form's sections.
        return array_filter(array_map(fn ($group) => $grouped[$group] ?? [], array_combine(array_keys(SchemaRegistry::GROUPS), array_keys(SchemaRegistry::GROUPS))));
    }

    /** A mapping key as the screen shows it: [field, language|"*"|null]. */
    protected function chosen(string $key, array $known): array
    {
        if ($key === '') {
            return ['', null];
        }

        if (isset($known[$key]['base_key'])) {
            return [$known[$key]['base_key'], $known[$key]['locale']];
        }

        if (! isset($known[$key]) && Columns::targets($key, $known) !== []) {
            return [substr($key, 0, -strlen('_'.Columns::ALL)), Columns::ALL];
        }

        return [$key, null];
    }

    /** The screen's field and language back into a mapping key: `name` + `ru` → `name_ru`, + `*` → `name_*`. */
    protected function composed(string $choice, ?string $language, array $known, SchemaRegistry $schema): string
    {
        if ($choice === '' || isset($known[$choice])) {
            return $choice;
        }

        if ($language === Columns::ALL) {
            return $choice.'_'.Columns::ALL;
        }

        $language = in_array($language, $schema->locales(), true) ? $language : $this->defaultLocale($schema);

        return $choice.'_'.$language;
    }

    /** The language a translatable column fills unless told otherwise: the content's own. */
    protected function defaultLocale(SchemaRegistry $schema): string
    {
        $locales = $schema->locales();

        return in_array(content_base_locale(), $locales, true) ? content_base_locale() : $locales[0];
    }

    /**
     * What a formula makes of a column's first values: [cell, result, why
     * not] each, or the reason it cannot be read at all.
     *
     * @return array{error: ?string, rows: list<array{0: string, 1: ?string, 2: ?string}>}
     */
    protected function formulaSamples(array $header, array $sample, int $i, string $text): array
    {
        try {
            $formula = Formula::parse($text);
        } catch (FormulaException $e) {
            return ['error' => $e->describe(), 'rows' => []];
        }

        $rows = [];
        foreach ($sample as $cells) {
            $cell = ImportRunner::unguard((string) ($cells[$i] ?? ''));
            try {
                $rows[] = [$cell, $formula->apply($cell, Columns::byName($header, $cells)), null];
            } catch (FormulaException $e) {
                $rows[] = [$cell, null, $e->describe()];
            }
        }

        return ['error' => null, 'rows' => $rows];
    }

    protected function savePreset(string $entity, array $header, array $mapping, array $options, array $validated): ?ImportPreset
    {
        $byHeader = [];
        foreach ($header as $i => $column) {
            $byHeader[$column] = $mapping[$i] ?? '';
        }
        $options['formulas'] = Columns::formulasByName($header, (array) ($options['formulas'] ?? []));

        $attributes = ['columns' => $header, 'mapping' => $byHeader, 'options' => $options, 'last_used_at' => now()];

        if (! empty($validated['preset_id']) && ($preset = ImportPreset::where('entity', $entity)->find($validated['preset_id']))) {
            $preset->update($attributes + (trim((string) ($validated['preset_name'] ?? '')) !== '' ? ['name' => trim($validated['preset_name'])] : []));

            return $preset;
        }

        if (trim((string) ($validated['preset_name'] ?? '')) !== '') {
            return ImportPreset::create($attributes + ['name' => trim($validated['preset_name']), 'entity' => $entity]);
        }

        return null;
    }

    protected function draft(string $token): ?ImportDraft
    {
        return preg_match('/^[A-Za-z0-9]{32}$/', $token) ? ImportDraft::where('token', $token)->first() : null;
    }

    /** Runs `$work` with a local copy of the upload (the disk may be a bucket). */
    protected function withFile(ImportDraft $draft, callable $work)
    {
        $storage = app(FileStorage::class);

        if (! Storage::disk('private')->exists($draft->path)) {
            // A draft without its file is nothing to come back to.
            $draft->delete();

            return $this->expired();
        }

        $local = $storage->localCopy('private', $draft->path);

        try {
            return $work($local);
        } finally {
            $storage->release($local);
        }
    }

    protected function expired()
    {
        return redirect()->route('admin.exim.import.index')
            ->with('status', __('That import is not there any more — imported, deleted or left untouched too long. Upload the file again.'));
    }
}
