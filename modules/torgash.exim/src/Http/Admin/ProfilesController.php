<?php

namespace Torgash\Exim\Http\Admin;

use App\Http\Controllers\Controller;
use App\Models\Language;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Torgash\Exim\ExportRunner;
use Torgash\Exim\Models\EximProfile;
use Torgash\Exim\Rebuilds;
use Torgash\Exim\SchemaRegistry;
use Torgash\Exim\Template\FeedTemplate;
use Torgash\Exim\Template\Presets;
use Torgash\Exim\Template\TemplateException;

class ProfilesController extends Controller
{
    public function index(Request $request, SchemaRegistry $schema)
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => in_array($request->query('status'), ['enabled', 'disabled', 'scheduled'], true) ? (string) $request->query('status') : '',
        ];

        // A shop has a handful: searched in PHP over the name in every language.
        $all = EximProfile::where('type', 'export')->orderByDesc('id')->get();
        $found = $filters['q'] === '' ? $all : $all->filter(fn (EximProfile $profile) => collect((array) $profile->name)
            ->contains(fn ($name) => mb_stripos((string) $name, $filters['q']) !== false));

        return view('torgash.exim::admin.profiles.index', [
            'profiles' => (match ($filters['status']) {
                'enabled' => $found->where('enabled', true),
                'disabled' => $found->where('enabled', false),
                'scheduled' => $found->filter(fn (EximProfile $profile) => $profile->rebuildsItself()),
                default => $found,
            })->values(),
            'filters' => $filters,
            'statusCounts' => [
                '' => $found->count(),
                'enabled' => $found->where('enabled', true)->count(),
                'disabled' => $found->where('enabled', false)->count(),
                'scheduled' => $found->filter(fn (EximProfile $profile) => $profile->rebuildsItself())->count(),
            ],
            'entities' => $this->entityOptions($schema),
        ]);
    }

    /** The list's in-place switch: answers with the status cell. */
    public function setStatus(Request $request, EximProfile $profile)
    {
        $request->validate(['enabled' => 'required|boolean']);
        $profile->update(['enabled' => $request->boolean('enabled')]);

        return view('torgash.exim::admin.profiles._status', ['profile' => $profile]);
    }

    /** Several at once, through the models so the audit hears each one. */
    public function bulk(Request $request)
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['enable', 'disable', 'delete'])],
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $profiles = EximProfile::where('type', 'export')->whereIn('id', $validated['ids'])->get();

        if ($validated['action'] === 'delete') {
            $profiles->each->delete();

            return back()->with('status', trans_choice(':count export profile deleted.|:count export profiles deleted.', $profiles->count(), ['count' => $profiles->count()]));
        }

        $profiles->each(fn (EximProfile $profile) => $profile->update(['enabled' => $validated['action'] === 'enable']));

        return back()->with('status', trans_choice(':count export profile updated.|:count export profiles updated.', $profiles->count(), ['count' => $profiles->count()]));
    }

    public function create(SchemaRegistry $schema)
    {
        return view('torgash.exim::admin.profiles.form', [
            'profile' => new EximProfile(['mode' => 'schema', 'format' => 'csv']),
            'entities' => $this->entityOptions($schema),
            'priceTypes' => hook_filter('exim.price_types', [], []),
            'presets' => Presets::all(),
        ]);
    }

    public function store(Request $request)
    {
        $profile = EximProfile::create($this->data($request, null) + [
            'type' => 'export',
            'public_token' => Str::random(40),
        ]);

        return $this->saved($request, $profile, __('Export profile created.'));
    }

    public function edit(EximProfile $profile, SchemaRegistry $schema)
    {
        return view('torgash.exim::admin.profiles.form', [
            'profile' => $profile,
            'entities' => $this->entityOptions($schema),
            'fieldDefs' => $schema->exportable($profile->entity),
            'groups' => SchemaRegistry::GROUPS,
            'languages' => Language::query()->where('enabled', true)->orderBy('sort')->orderBy('id')->pluck('native_name', 'code')->all(),
            'priceTypes' => hook_filter('exim.price_types', [], []),
            'presets' => Presets::all(),
        ]);
    }

    public function update(Request $request, EximProfile $profile)
    {
        $profile->update($this->data($request, $profile));

        return $this->saved($request, $profile, __('Export profile saved.'));
    }

    /** Saving stays on the form; "Save and close" in the gear goes back to the list. */
    protected function saved(Request $request, EximProfile $profile, string $message)
    {
        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.exim.profiles.index')
            : redirect()->route('admin.exim.profiles.edit', $profile))->with('status', $message);
    }

    public function destroy(EximProfile $profile)
    {
        $profile->delete();

        return redirect()->route('admin.exim.profiles.index')->with('status', __('Export profile deleted.'));
    }

    public function run(EximProfile $profile, ExportRunner $runner)
    {
        // A build of this file already running (the schedule's, a fetcher's)
        // is waited for briefly; past that, the admin is told rather than
        // left looking at a spinner for as long as the build takes.
        try {
            $path = $runner->run($profile, wait: 15);
        } catch (LockTimeoutException) {
            return back()->withErrors(['run' => __('This file is being built right now. Try again in a minute.')]);
        }

        $contentType = $runner->rendererFor($profile->format)->contentType();

        return Storage::disk('local')->response($path, null, ['Content-Type' => $contentType]);
    }

    /** Builds the file again in the background — for a feed too big to wait for in the browser. */
    public function rebuild(EximProfile $profile, Rebuilds $rebuilds)
    {
        return back()->with('status', $rebuilds->start($profile)
            ? __('The file is being built in the background. Its address hands out the new one once it is ready.')
            : __('The file is already being built.'));
    }

    /** The next times a typed schedule names — the line under the field, as it is typed. */
    public function schedule(Request $request)
    {
        return view('torgash.exim::admin.profiles._schedule', ['scheduleTyped' => (string) $request->query('schedule', '')]);
    }

    protected function data(Request $request, ?EximProfile $profile): array
    {
        $locale = admin_content_locale();

        $validated = $request->validate([
            'name' => 'nullable|string',
            'entity' => 'required|string',
            'format' => 'required|in:csv,xml,json',
            'mode' => 'required|in:schema,template',
            'fields' => 'nullable|array',
            'locales' => 'nullable|array',
            'locales.*' => 'string|max:5',
            'template_body' => 'nullable|string',
            'price_type_id' => 'nullable|integer',
            'schedule' => ['nullable', 'string', 'max:120', function (string $attribute, mixed $value, \Closure $fail) {
                if (! EximProfile::readable((string) $value)) {
                    $fail(__('Cron cannot read this schedule. It is five fields: minute, hour, day of the month, month, day of the week — "0 3 * * *" is every day at 03:00.'));
                }
            }],
            'rebuild_on_change' => 'nullable|boolean',
            'rebuild_every' => ['nullable', 'integer', Rule::in(EximProfile::REBUILD_EVERY)],
            'enabled_only' => 'nullable|boolean',
            'basic_auth_user' => 'nullable|string|max:191',
            'basic_auth_pass' => 'nullable|string|max:191',
            'enabled' => 'nullable|boolean',
        ]);

        // A template that cannot be read is said so here, not as an empty feed later.
        if ($validated['mode'] === 'template') {
            try {
                new FeedTemplate((string) ($validated['template_body'] ?? ''));
            } catch (TemplateException $e) {
                throw ValidationException::withMessages(['template_body' => $e->describe()]);
            }
        }

        $name = $profile?->name ?? [];
        if (trim($validated['name'] ?? '') !== '') {
            $name[$locale] = $validated['name'];
        }

        $attrs = [
            'name' => $name ?: [$locale => $validated['entity'].' export'],
            'entity' => $validated['entity'],
            'format' => $validated['format'],
            'mode' => $validated['mode'],
            // Ticked columns narrow a template as much as a table: the
            // template names what it prints, the columns decide what is read.
            'fields' => array_values($validated['fields'] ?? []),
            // Every language ticked is the same as none: "all", which then
            // includes a language the shop adds next year.
            'locales' => $this->locales($validated['locales'] ?? []),
            'template_body' => $validated['mode'] === 'template' ? ($validated['template_body'] ?? null) : null,
            'price_type_id' => ($validated['price_type_id'] ?? null) ?: null,
            'schedule' => EximProfile::cron($validated['schedule'] ?? null),
            'rebuild_on_change' => $request->boolean('rebuild_on_change'),
            'rebuild_every' => (int) ($validated['rebuild_every'] ?? $profile?->rebuild_every ?? 30),
            'filters' => ['enabled_only' => $request->boolean('enabled_only')],
            'basic_auth_user' => ($validated['basic_auth_user'] ?? null) ?: null,
            'enabled' => $request->boolean('enabled'),
        ];

        if (filled($validated['basic_auth_pass'] ?? null)) {
            $attrs['basic_auth_pass'] = $validated['basic_auth_pass'];
        }

        return $attrs;
    }

    /** @return list<string>|null  null = every language the shop has */
    protected function locales(array $chosen): ?array
    {
        $all = app(SchemaRegistry::class)->locales();
        $chosen = array_values(array_intersect($all, $chosen));

        return ($chosen === [] || count($chosen) === count($all)) ? null : $chosen;
    }

    protected function entityOptions(SchemaRegistry $schema): array
    {
        $entities = $schema->entities();

        return $entities ?: ['product' => 'Product', 'category' => 'Category', 'feature' => 'Feature'];
    }
}
