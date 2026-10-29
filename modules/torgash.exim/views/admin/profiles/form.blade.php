@extends('admin.layout')

@section('title', $profile->exists ? __('Edit export profile') : __('New export profile'))
@section('own_heading', '1')

@section('content')
    @php $locale = admin_content_locale(); @endphp

    <form method="POST" class="flex flex-col gap-4"
          action="{{ $profile->exists ? route('admin.exim.profiles.update', $profile) : route('admin.exim.profiles.store') }}"
          x-data="{ mode: @js(old('mode', $profile->mode ?? 'schema')) }">
        @csrf
        @if ($profile->exists) @method('PUT') @endif

        <x-admin.form-bar>
            <a href="{{ route('admin.exim.profiles.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to export profiles') }}" aria-label="{{ __('Back to export profiles') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ $profile->exists ? __('Edit export profile') : __('New export profile') }}</div>
                <div class="font-semibold truncate leading-tight">{{ $profile->exists ? $profile->t('name', $locale) : __('New export profile') }}</div>
            </div>
            <div class="dropdown dropdown-end">
                <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}" aria-label="{{ __('More actions') }}">
                    <x-icon name="settings" size="lg" />
                </div>
                <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-60 mt-2 p-2 z-50 border border-base-300">
                    <li><button type="submit" name="after_save" value="close"><x-icon name="check" /> {{ __('Save and close') }}</button></li>
                    @if ($profile->exists)
                        <li><button type="submit" form="profile-run"><x-icon name="download" /> {{ __('Download now') }}</button></li>
                        <li><button type="submit" form="profile-rebuild"><x-icon name="refresh-cw" /> {{ __('Build again in the background') }}</button></li>
                        <li class="menu-title p-0 my-1"><span class="border-t border-base-300 block"></span></li>
                        <li><button type="submit" form="profile-delete" class="text-error"><x-icon name="trash-2" /> {{ __('Delete') }}</button></li>
                    @endif
                </ul>
            </div>
            <x-slot:actions>
                <a href="{{ route('admin.exim.profiles.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
                <button class="btn btn-primary btn-sm">{{ $profile->exists ? __('Save') : __('Create') }}</button>
            </x-slot:actions>
        </x-admin.form-bar>

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
        @endif

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem] items-start">
            <div class="flex flex-col gap-4 min-w-0">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('What is exported') }}</h3>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Name') }}</legend>
                            <input name="name" value="{{ old('name', $profile->t('name', $locale)) }}"
                                   class="input input-bordered w-full" placeholder="{{ __('e.g. Wholesale price list') }}">
                        </fieldset>

                        <div class="grid md:grid-cols-3 gap-4">
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Data') }} *</legend>
                                <select name="entity" class="select select-bordered w-full" @disabled($profile->exists)>
                                    @foreach ($entities as $key => $label)
                                        <option value="{{ $key }}" @selected(old('entity', $profile->entity ?? '') === $key)>{{ __($label) }}</option>
                                    @endforeach
                                </select>
                                @if ($profile->exists)
                                    {{-- Fixed once created: the chosen columns belong to it. --}}
                                    <input type="hidden" name="entity" value="{{ $profile->entity }}">
                                @endif
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Format') }} *</legend>
                                <select name="format" class="select select-bordered w-full">
                                    @foreach (['csv' => 'CSV', 'xml' => 'XML', 'json' => 'JSON'] as $key => $label)
                                        <option value="{{ $key }}" @selected(old('format', $profile->format ?? 'csv') === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Built from') }} *</legend>
                                <select name="mode" x-model="mode" class="select select-bordered w-full">
                                    <option value="schema">{{ __('A list of columns') }}</option>
                                    <option value="template">{{ __('Its own template') }}</option>
                                </select>
                            </fieldset>
                        </div>

                        @unless (isset($fieldDefs))
                            <p class="text-xs opacity-60">{{ __('The columns can be chosen after the profile is created.') }}</p>
                        @endunless
                    </div>
                </section>

                @isset($fieldDefs)
                    @php
                        // A key saved before names became translatable (`name_ru`)
                        // ticks its field, so an old profile opens as it exports.
                        $selectedColumns = collect(old('fields', $profile->fields ?? []))
                            ->map(fn ($key) => collect($fieldDefs)->contains('key', $key) ? $key : preg_replace('/_[a-z]{2,5}$/', '', (string) $key))
                            ->unique()->all();
                        $chosenLocales = old('locales', $profile->locales ?? []);
                        $hasTranslatable = collect($fieldDefs)->contains('translatable', true);
                        $byGroup = collect($fieldDefs)->groupBy(fn ($field) => isset($groups[$field['group']]) ? $field['group'] : 'other');
                    @endphp
                    <section class="card bg-base-100 border border-base-300" x-data="{ q: '' }">
                        <div class="card-body gap-4">
                            <div class="flex flex-wrap items-center gap-3">
                                <h3 class="font-semibold">{{ __('Columns') }}</h3>
                                <label class="input input-sm w-full sm:w-64 gap-2 ml-auto">
                                    <x-icon name="search" size="sm" class="opacity-50" />
                                    <input type="search" x-model="q" class="grow" placeholder="{{ __('Find a column…') }}">
                                </label>
                            </div>
                            <p class="text-xs opacity-60 -mt-2">{{ __('Tick the ones to export — none ticked means every column.') }}</p>

                            @foreach ($groups as $groupKey => $groupLabel)
                                @continue(! $byGroup->has($groupKey))
                                <div class="flex flex-col gap-1" x-show="!q || $el.textContent.toLowerCase().includes(q.toLowerCase())">
                                    <div class="text-xs font-semibold uppercase tracking-wide opacity-50">{{ __($groupLabel) }}</div>
                                    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-x-4 gap-y-1">
                                        @foreach ($byGroup[$groupKey] as $field)
                                            <label class="label cursor-pointer justify-start gap-2 whitespace-normal"
                                                   x-show="!q || $el.textContent.toLowerCase().includes(q.toLowerCase())">
                                                <input type="checkbox" name="fields[]" value="{{ $field['key'] }}" class="checkbox checkbox-sm"
                                                       @checked(in_array($field['key'], $selectedColumns, true))>
                                                <span class="label-text">
                                                    {{ \Torgash\Exim\SchemaRegistry::title($field) }}
                                                    @if ($field['translatable'])
                                                        <x-icon name="languages" size="sm" class="inline opacity-40" title="{{ __('A column per language') }}" />
                                                    @endif
                                                    <span class="opacity-40 font-mono text-xs">{{ $field['key'] }}</span>
                                                </span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach

                            @if ($hasTranslatable && count($languages) > 1)
                                {{-- One row for every text: a column per language is
                                     worked out from it, never ticked per field. --}}
                                <div class="flex flex-col gap-2 border-t border-base-300 pt-4">
                                    <div class="flex items-center gap-2 text-sm font-medium">
                                        <x-icon name="languages" size="sm" class="opacity-60" /> {{ __('Languages of the texts') }}
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        @foreach ($languages as $code => $nativeName)
                                            <input type="checkbox" name="locales[]" value="{{ $code }}" class="btn btn-sm"
                                                   aria-label="{{ $nativeName }} · {{ strtoupper($code) }}" @checked(in_array($code, $chosenLocales, true))>
                                        @endforeach
                                    </div>
                                    <p class="text-xs opacity-60">{{ __('None ticked means every language, including one the shop adds later. Each text marked with the languages icon becomes one column per language: name_en, name_ru, …') }}</p>
                                </div>
                            @endif
                        </div>
                    </section>
                @endisset

                <section class="card bg-base-100 border border-base-300" x-show="mode === 'template'" x-cloak
                         x-data="{ preset: '' }">
                    <div class="card-body gap-3">
                        @php
                            // Built here, not typed into the markup: Blade reads a
                            // literal pair of braces as its own echo.
                            $open = '{'.'{';
                            $close = '}'.'}';
                            $templateExample = $open.'#rows'.$close.'<offer sku="'.$open.' item.sku '.$close.'">'.$open.' item.name.'.($locale).' '.$close.'</offer>'."\n".$open.'/rows'.$close;
                            $tag = fn (string $inside) => $open.$inside.$close;
                        @endphp
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="font-semibold mr-auto">{{ __('Template') }}</h3>
                            @if ($presets)
                                {{-- A ready template replaces what is typed (Ctrl+Z brings it back). --}}
                                <select class="select select-bordered select-sm w-auto" x-model="preset" aria-label="{{ __('Start from a ready template') }}"
                                        x-on:change="if (preset) {
                                            const source = document.querySelector('[data-template-preset=' + JSON.stringify(preset) + ']');
                                            window.TorgashCodeEditor.replace(document.getElementById('template-body'), source.value);
                                            const format = $root.closest('form').querySelector('[name=format]');
                                            if (format) format.value = source.dataset.format;
                                            preset = '';
                                        }">
                                    <option value="">{{ __('Start from a ready template…') }}</option>
                                    @foreach ($presets as $templatePreset)
                                        <option value="{{ $templatePreset['key'] }}">{{ __($templatePreset['title']) }}</option>
                                    @endforeach
                                </select>
                                @foreach ($presets as $templatePreset)
                                    <textarea hidden disabled data-template-preset="{{ $templatePreset['key'] }}" data-format="{{ $templatePreset['format'] }}">{{ $templatePreset['body'] }}</textarea>
                                @endforeach
                            @endif
                            <button type="button" class="btn btn-ghost btn-sm gap-1.5" data-code-format="#template-body" title="{{ __('Indent by the tags') }}">
                                <x-icon name="indent-increase" size="sm" /> {{ __('Indent') }}
                            </button>
                            <button type="button" class="btn btn-ghost btn-sm gap-1.5" data-code-compact="#template-body" title="{{ __('Drop indentation and blank lines') }}">
                                <x-icon name="fold-vertical" size="sm" /> {{ __('Unindent') }}
                            </button>
                        </div>
                        @error('template_body')
                            <div class="alert alert-error text-sm py-2"><x-icon name="triangle-alert" size="sm" /> {{ $message }}</div>
                        @enderror
                        <textarea id="template-body" name="template_body" rows="22" data-code-editor
                                  class="textarea textarea-bordered w-full font-mono text-xs leading-5"
                                  spellcheck="false" placeholder="{{ $templateExample }}">{{ old('template_body', $profile->template_body) }}</textarea>
                        <div class="text-xs opacity-70 flex flex-col gap-1.5">
                            <p>{{ __('The file is written as typed. What stands between #rows and /rows is repeated for every row; inside it a column is item.<key>, a text in one language item.<key>.<language>, a price type\'s own price item.price_type.<CODE>, a characteristic item.feature.<code>. Outside it: profile.name, profile.generated_at and, for products, shop.name, shop.url, shop.currency, shop.currencies, shop.categories.') }}</p>
                            <p>{{ __('Values are escaped for the format — XML and JSON cannot be broken by a quote in a product name. Nothing in a template runs as code.') }}</p>
                            <details>
                                <summary class="cursor-pointer select-none">{{ __('Lists, conditions and filters') }}</summary>
                                <div class="mt-2 grid gap-x-6 gap-y-1 md:grid-cols-2">
                                    @foreach ([
                                        $tag('#item.pictures').'<picture>'.$tag(' . ').'</picture>'.$tag('/item.pictures') => 'a list: once per element, the element is .',
                                        $tag('#item.params').'<param name="'.$tag(' name ').'">'.$tag(' value ').'</param>'.$tag('/item.params') => 'a list of records: their fields by name',
                                        $tag('#item.old_price').'…'.$tag('/item.old_price') => 'printed only when there is a value',
                                        $tag('^item.brand').'…'.$tag('/item.brand') => 'printed only when there is none',
                                        $tag('! a note ') => 'a comment, not printed',
                                        $tag(' item.price | money ') => 'through a filter (several: | a | b)',
                                    ] as $syntaxExample => $syntaxMeaning)
                                        <div class="flex items-baseline gap-2 min-w-0"><code class="font-mono shrink-0">{{ $syntaxExample }}</code> <span class="opacity-70 truncate">{{ __($syntaxMeaning) }}</span></div>
                                    @endforeach
                                    <div class="md:col-span-2 font-semibold mt-2">{{ __('Filters') }}</div>
                                    @foreach (\Torgash\Exim\Template\FeedTemplate::FILTERS as $filterName => $filterMeaning)
                                        <div class="flex items-baseline gap-2 min-w-0"><code class="font-mono shrink-0">{{ $filterName }}</code> <span class="opacity-70 truncate">{{ __($filterMeaning) }}</span></div>
                                    @endforeach
                                    <div class="md:col-span-2 font-semibold mt-2">{{ __('For a product feed') }}</div>
                                    <p class="md:col-span-2 opacity-70">{{ __('item.id, item.url, item.available (true/false), item.category_id, item.price and item.old_price (minor units — | money), item.pictures, item.params (name, value, code), item.brand, item.sku — and any column of the list above. A column the template names is read even when it is not ticked.') }}</p>
                                </div>
                            </details>
                        </div>
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('Prices and rows') }}</h3>
                        <div class="grid md:grid-cols-2 gap-4 items-end">
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Price type') }}</legend>
                                <select name="price_type_id" class="select select-bordered w-full">
                                    <option value="">{{ __('The storefront\'s own prices') }}</option>
                                    @foreach ($priceTypes as $id => $code)
                                        <option value="{{ $id }}" @selected((int) old('price_type_id', $profile->price_type_id ?? 0) === (int) $id)>{{ $code }}</option>
                                    @endforeach
                                </select>
                            </fieldset>
                            <label class="label cursor-pointer justify-start gap-3 pb-2">
                                <input type="checkbox" name="enabled_only" value="1" class="toggle toggle-sm toggle-success"
                                       @checked(old('enabled_only', $profile->filters['enabled_only'] ?? true))>
                                <span class="label-text">{{ __('Only what is switched on') }}</span>
                            </label>
                        </div>
                    </div>
                </section>
            </div>

            <div class="flex flex-col gap-4">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Status') }}</h3>
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" name="enabled" value="1" class="toggle toggle-sm toggle-success"
                                   @checked(old('enabled', $profile->enabled ?? true))>
                            <span class="label-text">{{ __('Enabled') }}</span>
                        </label>
                        <p class="text-xs opacity-60">{{ __('A disabled profile does not run on its schedule, and its feed address answers "not found".') }}</p>
                    </div>
                </section>

                @php
                    $scheduleNow = (string) old('schedule', $profile->cronExpression() ?? '');
                    $scheduleNamed = \Torgash\Exim\Models\EximProfile::SCHEDULES;
                @endphp
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Updating the file') }}</h3>

                        {{-- A cron expression, typed by whoever speaks cron — or
                             written by picking from the list, which also names a
                             typed one back when it matches. --}}
                        <fieldset class="fieldset p-0" x-data="{ cron: @js($scheduleNow), named: @js(array_keys($scheduleNamed)) }">
                            <legend class="fieldset-legend">{{ __('By schedule') }}</legend>
                            <select class="select select-bordered w-full" aria-label="{{ __('Ready schedules') }}"
                                    x-on:change="if ($event.target.value === 'own') { $refs.cron.focus() } else { cron = $event.target.value; $nextTick(() => $refs.cron.dispatchEvent(new Event('input', { bubbles: true }))) }">
                                <option value="" @selected($scheduleNow === '') x-bind:selected="cron.trim() === ''">{{ __('Not by time') }}</option>
                                @foreach ($scheduleNamed as $scheduleExpression => $scheduleLabel)
                                    <option value="{{ $scheduleExpression }}" @selected($scheduleNow === $scheduleExpression)
                                            x-bind:selected="cron.trim() === @js($scheduleExpression)">{{ __($scheduleLabel) }}</option>
                                @endforeach
                                <option value="own" @selected($scheduleNow !== '' && ! isset($scheduleNamed[$scheduleNow]))
                                        x-bind:selected="cron.trim() !== '' && ! named.includes(cron.trim())">{{ __('My own (cron)') }}</option>
                            </select>
                            <input name="schedule" x-ref="cron" x-model="cron" value="{{ $scheduleNow }}"
                                   class="input input-bordered w-full font-mono mt-2" placeholder="0 3 * * *" autocomplete="off" spellcheck="false"
                                   aria-label="{{ __('Cron expression') }}"
                                   hx-get="{{ route('admin.exim.profiles.schedule') }}" hx-trigger="input changed delay:400ms"
                                   hx-target="#schedule-preview" hx-swap="innerHTML">
                            <p id="schedule-preview" class="label text-xs whitespace-normal">@include('torgash.exim::admin.profiles._schedule', ['scheduleTyped' => $scheduleNow])</p>
                        </fieldset>

                        <div class="flex flex-col gap-2">
                            <label class="label cursor-pointer justify-start gap-3 whitespace-normal">
                                <input type="checkbox" name="rebuild_on_change" value="1" class="toggle toggle-sm toggle-success"
                                       @checked(old('rebuild_on_change', $profile->rebuild_on_change))>
                                <span class="label-text">{{ __('When its data changes') }}</span>
                            </label>
                            <label class="flex flex-wrap items-center gap-2 text-sm">
                                <span class="opacity-70">{{ __('not more often than every') }}</span>
                                <select name="rebuild_every" class="select select-bordered select-sm w-auto">
                                    @foreach (\Torgash\Exim\Models\EximProfile::REBUILD_EVERY as $everyMinutes)
                                        <option value="{{ $everyMinutes }}" @selected((int) old('rebuild_every', $profile->rebuild_every ?? 30) === $everyMinutes)>{{ $everyMinutes < 60 ? __(':count min', ['count' => $everyMinutes]) : __(':count h', ['count' => intdiv($everyMinutes, 60)]) }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <p class="text-xs opacity-60">{{ __('What the export reads — products, categories, characteristics, prices, photos — is looked at every five minutes. Nothing changed, nothing is built; fifty edits in an hour make two builds, not fifty.') }}</p>
                        </div>

                        @if ($profile->exists)
                            @php
                                $buildTask = $profile->buildTask();
                                $cronSaved = $profile->cronExpression();
                                $nextScheduled = $cronSaved && \Torgash\Exim\Models\EximProfile::readable($cronSaved)
                                    ? (\Torgash\Exim\Models\EximProfile::nextRuns($cronSaved, 1)[0] ?? null) : null;
                            @endphp
                            <div class="text-sm flex flex-col gap-1 border-t border-base-300 pt-3">
                                @if ($profile->last_run_at)
                                    <div>
                                        {{ __('Built :when', ['when' => $profile->last_run_at->format('d.m.Y H:i')]) }}
                                        @if ($profile->last_rows !== null)
                                            <span class="opacity-60">· {{ trans_choice(':count row|:count rows', $profile->last_rows, ['count' => number_format($profile->last_rows, 0, ',', ' ')]) }}
                                                · {{ $profile->fileSize() }}
                                                · {{ (int) $profile->last_duration_ms < 1000 ? __('under a second') : __(':count s', ['count' => (int) round($profile->last_duration_ms / 1000)]) }}</span>
                                        @endif
                                    </div>
                                @else
                                    <div class="opacity-60">{{ __('Not built yet.') }}</div>
                                @endif
                                @if ($profile->isBuilding())
                                    <div class="text-info">{{ $buildTask->status === \App\Models\BackgroundTask::RUNNING ? __('Being built right now.') : __('Waiting in the queue to be built.') }}</div>
                                @elseif ($buildTask?->status === \App\Models\BackgroundTask::FAILED)
                                    <div class="text-error">{{ __('The last build failed: :error', ['error' => \Illuminate\Support\Str::limit((string) $buildTask->error, 200)]) }}</div>
                                @endif
                                @if ($nextScheduled)
                                    <div class="opacity-70">{{ __('Next by the schedule: :when', ['when' => $nextScheduled->format('d.m H:i')]) }}</div>
                                @endif
                                @if ($profile->rebuild_on_change)
                                    <div class="opacity-70">{{ $profile->hasRested()
                                        ? __('On a change: within five minutes of it.')
                                        : __('On a change: not before :when.', ['when' => $profile->restsUntil()->format('H:i')]) }}</div>
                                @endif
                            </div>
                            @if ($profile->rebuildsItself())
                                {{-- What makes it happen at all: the shop's scheduler,
                                     started every minute by the server; it starts a
                                     queue worker by itself when there is none. --}}
                                <div class="text-xs opacity-70 flex flex-col gap-1">
                                    <span>{{ __('Built in the background by the shop\'s scheduler — the server has to start it every minute:') }}</span>
                                    <input readonly data-select class="input input-bordered input-sm w-full font-mono"
                                           value="{{ \App\Core\Support\Php::line() }} {{ base_path('artisan') }} schedule:run">
                                </div>
                            @endif
                        @endif
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Delivery') }}</h3>

                        @if ($profile->exists)
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Feed address') }}</legend>
                                <input readonly data-select class="input input-bordered w-full font-mono text-xs"
                                       value="{{ route('exim.feed', $profile->public_token) }}">
                                <p class="label text-xs opacity-60 whitespace-normal">{{ $profile->rebuildsItself()
                                    ? __('Give it to whoever fetches the file — a CRM, a wholesaler, a marketplace. It hands out the last file built, at once, however often it is asked.')
                                    : __('Give it to whoever fetches the file — a CRM, a wholesaler, a marketplace. With no schedule and no change rule above, it builds the file afresh every time it is asked.') }}</p>
                            </fieldset>
                            @if (! $profile->isPublicData())
                                {{-- Said before it is tried: the address alone
                                     answers "forbidden" for people's data. --}}
                                <div class="alert {{ $profile->requiresBasicAuth() ? 'alert-info' : 'alert-warning' }} text-sm">
                                    {{ $profile->requiresBasicAuth()
                                        ? __("This export holds customers' data, so the feed opens only with the login below.")
                                        : __("This export holds customers' data: the feed stays closed until you set a login and password below.") }}
                                </div>
                            @endif
                        @endif

                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Login for the feed') }}</legend>
                            <input name="basic_auth_user" value="{{ old('basic_auth_user', $profile->basic_auth_user) }}" autocomplete="off"
                                   class="input input-bordered w-full">
                        </fieldset>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Password for the feed') }}</legend>
                            <input type="password" name="basic_auth_pass" autocomplete="new-password" class="input input-bordered w-full"
                                   placeholder="{{ $profile->exists && $profile->basic_auth_user ? __('saved — leave blank to keep it') : '' }}">
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('Empty login — the address alone opens the feed.') }}</p>
                        </fieldset>
                    </div>
                </section>
            </div>
        </div>
    </form>

    @if ($profile->exists)
        <form id="profile-run" method="POST" action="{{ route('admin.exim.profiles.run', $profile) }}" class="hidden">@csrf</form>
        <form id="profile-rebuild" method="POST" action="{{ route('admin.exim.profiles.rebuild', $profile) }}" class="hidden">@csrf</form>
        <form id="profile-delete" method="POST" action="{{ route('admin.exim.profiles.destroy', $profile) }}"
              data-confirm="{{ __('Delete this export profile? Its feed address stops working.') }}">
            @csrf @method('DELETE')
        </form>
    @endif
@push('scripts')
    <script nonce="{{ csp_nonce() }}" src="{{ asset_url('/assets/js/code-editor.js') }}" defer></script>
@endpush
@endsection
