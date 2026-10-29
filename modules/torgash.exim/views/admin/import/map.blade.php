@extends('admin.layout')

@section('title', __('Match the columns'))
@section('own_heading', '1')

@section('content')
    @php
        $mappedCount = count(array_filter($mapping, fn ($key) => $key !== ''));
        // Every choice by key, for the button that shows a column's field.
        $choiceByKey = collect($choices)->flatten(1)->keyBy('key');
    @endphp

    <x-admin.form-bar>
        <a href="{{ route('admin.exim.import.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to import') }}" aria-label="{{ __('Back to import') }}">
            <x-icon name="arrow-left" size="lg" />
        </a>
        <div class="flex-1 min-w-0">
            <div class="text-xs opacity-50 leading-none">{{ __('Match the columns') }}</div>
            <div class="font-semibold truncate leading-tight">{{ __($entityLabel) }} · {{ $fileName }} · {{ trans_choice(':count row|:count rows', $rowCount, ['count' => $rowCount]) }}</div>
        </div>
        <x-slot:actions>
            <a href="{{ route('admin.exim.import.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
            <button form="import-map" class="btn btn-primary btn-sm gap-1.5"><x-icon name="eye" size="sm" /> {{ __('Check before importing') }}</button>
        </x-slot:actions>
    </x-admin.form-bar>

    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    <section class="card bg-base-100 border border-base-300">
        <div class="card-body gap-3">
            <h3 class="font-semibold">{{ __('How the file is written') }}</h3>
            {{-- Its own GET form: changing either re-reads the file at once, so
                 the columns below are always the ones these settings give. --}}
            <form id="import-reread" method="GET" action="{{ route('admin.exim.import.map', ['token' => $token]) }}" class="flex flex-wrap items-end gap-4">
                @if ($xml)
                    {{-- An XML file has no delimiter or encoding to choose (it
                         declares its own): what is chosen is which element is a row. --}}
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('One row is the element') }}</legend>
                        <select name="xml_record" class="select select-bordered" x-data x-on:change="$el.form.requestSubmit()">
                            @foreach ($xmlRecords as $recordName => $recordCount)
                                <option value="{{ $recordName }}" @selected($xmlRecord === $recordName)>&lt;{{ $recordName }}&gt; · {{ $recordCount }}</option>
                            @endforeach
                        </select>
                    </fieldset>
                @else
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Delimiter') }}</legend>
                        <select name="delimiter" class="select select-bordered" x-data x-on:change="$el.form.requestSubmit()">
                            @foreach (\Torgash\Exim\Import\CsvFile::DELIMITERS as $value => $label)
                                <option value="{{ $value }}" @selected($delimiter === $value)>{{ __($label) }}</option>
                            @endforeach
                        </select>
                    </fieldset>
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Encoding') }}</legend>
                        <select name="encoding" class="select select-bordered" x-data x-on:change="$el.form.requestSubmit()">
                            @foreach (\Torgash\Exim\Import\CsvFile::ENCODINGS as $value => $label)
                                <option value="{{ $value }}" @selected($encoding === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </fieldset>
                @endif
                @if ($presets->isNotEmpty())
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Import preset') }}</legend>
                        {{-- A preset carries its own delimiter and encoding: the two
                             above stay out of the request when it is the preset that changed. --}}
                        <select name="preset" class="select select-bordered" x-data x-on:change="$el.form.querySelectorAll('[name=delimiter], [name=encoding], [name=xml_record]').forEach(el => el.disabled = true); $el.form.requestSubmit()">
                            <option value="">{{ __('— none —') }}</option>
                            @foreach ($presets as $presetOption)
                                <option value="{{ $presetOption->id }}" @selected($preset?->id === $presetOption->id)>{{ $presetOption->name }}</option>
                            @endforeach
                        </select>
                    </fieldset>
                @endif
                <p class="text-xs opacity-60 pb-2 max-w-md">{{ $xml
                    ? __('Worked out from the file: the element repeated most. A tag of it is a column, a <param name="…"> a column named after the characteristic.')
                    : __('Worked out from the file. If the columns below look wrong — one column with everything, or broken letters — change these.') }}</p>
            </form>
        </div>
    </section>

    <div x-data="importMap">
    {{-- Every change is saved into the draft as it is made (`save()`), so the
         list of unfinished imports reopens it as it was left. --}}
    <form id="import-map" method="POST" action="{{ route('admin.exim.import.preview') }}" class="flex flex-col gap-4"
          data-save="{{ route('admin.exim.import.save', ['token' => $token]) }}" x-on:change.debounce.300ms="save()">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        @if ($xml)
            <input type="hidden" name="xml_record" value="{{ $xmlRecord }}">
        @else
            <input type="hidden" name="delimiter" value="{{ $delimiter }}">
            <input type="hidden" name="encoding" value="{{ $encoding }}">
        @endif
        @if ($preset)
            <input type="hidden" name="preset_id" value="{{ $preset->id }}">
        @endif

        <section class="card bg-base-100 border border-base-300">
            <div class="card-body gap-3">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="font-semibold">{{ __('Columns of the file') }}</h3>
                    <span class="flex items-baseline gap-3">
                        <span class="text-xs opacity-60" x-show="saveState" x-cloak
                              x-text="saveState === 'saving' ? @js(__('Saving…')) : (saveState === 'saved' ? @js(__('Draft saved')) : @js(__('Not saved — check the connection')))"
                              x-bind:class="saveState === 'failed' && 'text-error opacity-100'"></span>
                        <span class="text-sm opacity-70" data-map-count data-template="{{ __(':mapped of :count columns will be imported', ['mapped' => '#', 'count' => count($header)]) }}">{{ __(':mapped of :count columns will be imported', ['mapped' => $mappedCount, 'count' => count($header)]) }}</span>
                    </span>
                </div>
                @if ($preset)
                    <div class="alert alert-info text-sm py-2"><x-icon name="bookmark" size="sm" /> {{ __('Read with the preset ":name" — what you change here is saved into it.', ['name' => $preset->name]) }}</div>
                @endif
                <details class="text-sm">
                    <summary class="cursor-pointer select-none opacity-70 hover:opacity-100 inline-flex items-center gap-1.5">
                        <x-icon name="square-function" size="sm" /> {{ __('Formulas: change a value on the way in') }}
                    </summary>
                    <div class="mt-2 grid gap-x-6 gap-y-1 md:grid-cols-2 text-xs">
                        <p class="md:col-span-2 opacity-70 mb-1">{{ __('The button beside a field opens its formula. $value is the cell; the values on the left show the result as you type. A formula that cannot be worked out for a row leaves that field of the row untouched and says so in the task.') }}</p>
                        @foreach ([
                            ['$value * 1.25', 'markup of 25%'],
                            ['ending($value * 1.2, 90)', 'markup, the price ends in 90: 1 234 → 1 290'],
                            ['round($value, -1)', 'to tens; roundup / rounddown round up or down'],
                            ['if($value > 10000, $value * 1.1, $value * 1.3)', 'one markup or another'],
                            ['col("Purchase") * 1.5', 'from another column of the row'],
                            ['"SUP-" & $value', '& joins text'],
                            ['replace($value, "Apple ", "")', 'remove or swap words'],
                            ['if($value = "Yes", 10, 0)', 'compare with = != < > <= >='],
                        ] as [$formulaExample, $formulaMeaning])
                            <div class="flex items-baseline gap-2 min-w-0"><code class="font-mono shrink-0">{{ $formulaExample }}</code> <span class="opacity-60 truncate">{{ __($formulaMeaning) }}</span></div>
                        @endforeach
                        <p class="md:col-span-2 opacity-60 mt-1">{{ __('Also: min, max, abs, empty, contains, lower, upper, trim, left, right, concat, sum, sub, mul, div.') }}</p>
                    </div>
                </details>
                {{-- One grid for the head and every row, so the pickers stand in one line. --}}
                <div class="border border-base-300 rounded-box divide-y divide-base-300">
                    <div class="hidden md:grid grid-cols-[minmax(0,13rem)_minmax(0,1fr)_27rem] gap-4 px-4 py-2 bg-base-200 text-xs font-semibold opacity-70">
                        <div>{{ __('Column in the file') }}</div>
                        <div>{{ __('Values') }}</div>
                        <div>{{ __('Field') }}</div>
                    </div>
                    @foreach ($header as $columnIndex => $columnName)
                        @php
                            [$choiceKey, $choiceLocale] = $chosen[$columnIndex] ?? ['', null];
                            $choice = $choiceByKey->get($choiceKey);
                        @endphp
                        <div data-map-row="{{ $columnIndex }}" @class(['grid md:grid-cols-[minmax(0,13rem)_minmax(0,1fr)_27rem] gap-x-4 gap-y-2 items-center px-4 py-2.5', 'opacity-60' => $choiceKey === ''])>
                            <div class="min-w-0">
                                <div class="font-medium truncate" title="{{ $columnName }}" data-map-column>{{ $columnName !== '' ? $columnName : __('(no name)') }}</div>
                                <div class="text-xs opacity-50">{{ __('Column :n', ['n' => $columnIndex + 1]) }}</div>
                            </div>
                            @include('torgash.exim::admin.import._values', ['formulaSample' => $formulaSamples[$columnIndex] ?? null])
                            @php $columnFormula = (string) ($formulas[$columnIndex] ?? ''); @endphp
                            <div class="min-w-0 flex flex-col gap-1.5" x-data="{ formula: {{ $columnFormula !== '' ? 'true' : 'false' }} }">
                            <div class="flex items-center gap-2 min-w-0">
                                <input type="hidden" name="map[{{ $columnIndex }}]" value="{{ $choice ? $choiceKey : '' }}" data-map-key>
                                <button type="button" id="map-pick-{{ $columnIndex }}" style="anchor-name: --map-pick-{{ $columnIndex }}"
                                        class="select select-bordered select-sm flex-1 min-w-0 text-left font-normal"
                                        x-on:click="open({{ $columnIndex }})">
                                    <span class="truncate" data-map-title>{{ $choice ? $choice['title'] : __('— skip this column —') }}</span>
                                    <span class="ml-1 text-xs opacity-50 shrink-0" data-map-hint>{{ $choice['hint'] ?? '' }}</span>
                                </button>
                                {{-- The language a text fills: one, or every language of the shop. --}}
                                <select name="lang[{{ $columnIndex }}]" aria-label="{{ __('Language') }}" title="{{ __('Which language the column fills') }}"
                                        @class(['select select-bordered select-sm w-28 shrink-0', 'hidden' => ! ($choice['translatable'] ?? false)]) data-map-lang>
                                    @foreach ($locales as $localeCode)
                                        <option value="{{ $localeCode }}" @selected(($choiceLocale ?? $baseLocale) === $localeCode)>{{ strtoupper($localeCode) }}</option>
                                    @endforeach
                                    @if (count($locales) > 1)
                                        <option value="{{ \Torgash\Exim\Import\Columns::ALL }}" @selected($choiceLocale === \Torgash\Exim\Import\Columns::ALL)>{{ __('All languages') }}</option>
                                    @endif
                                </select>
                                <button type="button" class="btn btn-ghost btn-sm btn-square shrink-0" x-bind:class="formula && 'btn-active text-primary'"
                                        title="{{ __('Formula') }}" aria-label="{{ __('Formula') }}"
                                        x-on:click="formula = ! formula; formula && $nextTick(() => $refs.formula.focus())">
                                    <x-icon name="square-function" />
                                </button>
                            </div>
                            {{-- What the cell becomes on the way in; the values beside it are
                                 worked out as it is typed. Emptied, it does nothing. --}}
                            <input type="text" name="formula[{{ $columnIndex }}]" value="{{ $columnFormula }}" x-ref="formula" x-show="formula" x-cloak
                                   class="input input-bordered input-sm w-full font-mono" placeholder="$value * 1.2" maxlength="{{ \Torgash\Exim\Import\Formula::MAX_LENGTH }}"
                                   aria-label="{{ __('Formula') }}" autocomplete="off" spellcheck="false"
                                   hx-post="{{ route('admin.exim.import.formula', ['token' => $token]) }}" hx-vals='@json(['column' => $columnIndex])'
                                   hx-trigger="input changed delay:400ms" hx-target="#map-values-{{ $columnIndex }}" hx-swap="outerHTML"
                                   x-on:keydown.enter.prevent>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="card bg-base-100 border border-base-300">
            <div class="card-body gap-4">
                <h3 class="font-semibold">{{ __('Settings of this import') }}</h3>
                <div class="grid gap-4 md:grid-cols-2">
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="hidden" name="create_new" value="0">
                        <input type="checkbox" name="create_new" value="1" class="checkbox checkbox-sm mt-0.5" @checked($options['create_new'] ?? true)>
                        <span>
                            <span class="block">{{ __('Create what is not found') }}</span>
                            <span class="block text-xs opacity-60">{{ __('Off — only existing records are updated, and rows that match nothing are skipped (a supplier\'s price list).') }}</span>
                        </span>
                    </label>
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Category levels are separated by') }}</legend>
                        <input type="text" name="category_delimiter" value="{{ $options['category_delimiter'] ?? '///' }}" class="input input-bordered w-32 font-mono" maxlength="8">
                        <p class="label text-xs whitespace-normal">{{ __('"Phones///Smartphones" — the category is found by its path, and created if there is none.') }}</p>
                    </fieldset>
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Photos from the file') }}</legend>
                        <select name="images_mode" class="select select-bordered w-full">
                            @foreach (['empty' => 'Only for products without photos', 'missing' => 'Add the ones not added yet', 'replace' => 'Replace the product\'s photos with the file\'s'] as $value => $label)
                                <option value="{{ $value }}" @selected(($options['images_mode'] ?? 'empty') === $value)>{{ __($label) }}</option>
                            @endforeach
                        </select>
                        <p class="label text-xs whitespace-normal">{{ __('Photos are downloaded after the rows are imported, as a background task of their own. A link already added is never downloaded twice.') }}</p>
                    </fieldset>
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Address in front of photo file names') }}</legend>
                        <input type="url" name="images_base" value="{{ $options['images_base'] ?? '' }}" class="input input-bordered w-full" placeholder="https://old-shop.example/images/">
                        <p class="label text-xs whitespace-normal">{{ __('For a file that names its photos without an address ("detailed/12/phone.jpg").') }}</p>
                    </fieldset>
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ $preset ? __('Rename the preset') : __('Save as an import preset') }}</legend>
                        <input type="text" name="preset_name" value="" class="input input-bordered w-full" maxlength="120" placeholder="{{ $preset?->name ?? __('e.g. Supplier price list') }}">
                        <p class="label text-xs whitespace-normal">{{ __('The next file with these columns is read this way without setting anything up.') }}</p>
                    </fieldset>
                </div>
            </div>
        </section>
    </form>

    {{-- ONE field picker for every column, opened beside the column's button:
         the list is printed once, not once per column (a file of 80 columns
         against 300 fields would be 24 000 options). Outside the form — its
         search box is not a field of the import. --}}
    <div id="map-picker" popover x-ref="picker" data-align="end"
         class="torgash-popover bg-base-100 text-base-content border border-base-300 rounded-box shadow-lg w-[27rem] p-0"
         x-on:toggle="if ($event.newState === 'closed') row = null">
        <div class="p-2 border-b border-base-300">
            <label class="input input-bordered input-sm w-full">
                <x-icon name="search" size="sm" class="opacity-50" />
                <input type="search" x-ref="search" x-model="query" x-on:input="filter()" x-on:keydown="keys($event)"
                       placeholder="{{ __('Find a field: name, key or #id') }}" autocomplete="off">
            </label>
        </div>
        <ul class="max-h-[60vh] overflow-y-auto py-1 text-sm" x-ref="list">
            <li><button type="button" data-choice data-key="" data-title="{{ __('— skip this column —') }}" data-search="" x-on:click="choose($el)"
                        class="w-full text-left px-3 py-1.5 hover:bg-base-200 opacity-70">{{ __('— skip this column —') }}</button></li>
            @foreach ($choices as $groupKey => $groupChoices)
                <li data-choice-group>
                    <div class="sticky top-0 z-10 bg-base-100 px-3 pt-2 pb-1 text-xs font-semibold uppercase tracking-wide opacity-50">{{ __($groups[$groupKey]) }}</div>
                    <ul>
                        @foreach ($groupChoices as $item)
                            <li>
                                <button type="button" data-choice data-key="{{ $item['key'] }}" data-title="{{ $item['title'] }}" data-hint="{{ $item['hint'] }}"
                                        data-search="{{ $item['search'] }}" @if ($item['translatable']) data-translatable @endif x-on:click="choose($el)"
                                        class="w-full text-left px-3 py-1.5 hover:bg-base-200 flex flex-col">
                                    <span class="flex items-baseline gap-2 w-full">
                                        <span class="truncate">{{ $item['title'] }}</span>
                                        @if ($item['hint'])
                                            <span class="text-xs opacity-50 shrink-0">{{ $item['hint'] }}</span>
                                        @endif
                                        @if ($item['translatable'])
                                            <span class="text-xs opacity-50 shrink-0">{{ __('by language') }}</span>
                                        @endif
                                        <span class="ml-auto text-xs text-warning shrink-0 hidden" data-used></span>
                                    </span>
                                    @if ($item['help'])
                                        <span class="text-xs opacity-50 line-clamp-2">{{ $item['help'] }}</span>
                                    @endif
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </li>
            @endforeach
            <li class="px-3 py-3 text-sm opacity-60 hidden" x-ref="nothing">{{ __('No field matches.') }}</li>
        </ul>
    </div>
    </div>

    @pushOnce('scripts', 'popover-anchor')
        <script nonce="{{ csp_nonce() }}" src="{{ asset_url('/assets/js/popover-anchor.js') }}" defer></script>
    @endPushOnce
    @push('scripts')
        <script nonce="{{ csp_nonce() }}">
            document.addEventListener('alpine:init', () => {
                Alpine.data('importMap', () => ({
                    row: null,      // the column whose field is being picked
                    query: '',
                    active: -1,     // the highlighted choice, for the arrow keys

                    rowEl(i) { return this.$root.querySelector('[data-map-row="' + i + '"]'); },
                    choices() { return [...this.$refs.list.querySelectorAll('[data-choice]')]; },
                    visible() { return this.choices().filter(el => ! el.closest('li').hidden); },

                    open(i) {
                        const panel = this.$refs.picker;
                        if (panel.matches(':popover-open') && this.row === i) { panel.hidePopover(); return; }
                        this.row = i;
                        this.query = '';
                        // Hung on this column's button (popover-anchor.js reads
                        // data-anchor where the browser cannot anchor by CSS).
                        panel.dataset.anchor = '#map-pick-' + i;
                        panel.style.positionAnchor = '--map-pick-' + i;
                        this.markUsed(i);
                        this.filter();
                        panel.showPopover();
                        // The current field highlighted and in view, the search box ready.
                        const current = this.rowEl(i).querySelector('[data-map-key]').value;
                        this.active = this.visible().findIndex(el => el.dataset.key === current);
                        this.highlight(true);
                        this.$refs.search.focus();
                    },

                    // A field another column already fills says which: of two
                    // columns on one field the second wins.
                    markUsed(i) {
                        const taken = {};
                        this.$root.querySelectorAll('[data-map-row]').forEach(row => {
                            const key = row.querySelector('[data-map-key]').value;
                            if (key !== '' && row.dataset.mapRow !== String(i)) taken[key] = row.querySelector('[data-map-column]').textContent.trim();
                        });
                        this.choices().forEach(el => {
                            const used = el.querySelector('[data-used]');
                            if (! used) return;
                            used.textContent = taken[el.dataset.key] ? '← ' + taken[el.dataset.key] : '';
                            used.classList.toggle('hidden', ! taken[el.dataset.key]);
                        });
                    },

                    // Every typed word somewhere in the field's name, key, id or
                    // other systems' spellings of it.
                    filter() {
                        const words = this.query.trim().toLowerCase().split(/\s+/).filter(Boolean);
                        this.choices().forEach(el => {
                            el.closest('li').hidden = words.length > 0 && (el.dataset.key === '' || ! words.every(w => el.dataset.search.includes(w)));
                        });
                        this.$refs.list.querySelectorAll('[data-choice-group]').forEach(group => {
                            group.hidden = ! group.querySelector(':scope > ul > li:not([hidden])');
                        });
                        const shown = this.visible().length;
                        this.$refs.nothing.classList.toggle('hidden', shown > 0);
                        this.active = words.length > 0 && shown > 0 ? 0 : -1;
                        this.highlight(false);
                    },

                    highlight(center) {
                        this.choices().forEach(el => el.classList.remove('bg-base-200'));
                        const el = this.visible()[this.active];
                        if (el) {
                            el.classList.add('bg-base-200');
                            el.scrollIntoView({ block: center ? 'center' : 'nearest' });
                        }
                    },

                    keys(event) {
                        const count = this.visible().length;
                        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                            event.preventDefault();
                            this.active = Math.min(Math.max(this.active + (event.key === 'ArrowDown' ? 1 : -1), 0), count - 1);
                            this.highlight(false);
                        } else if (event.key === 'Enter') {
                            // Never the form's submit: Enter picks the highlighted field.
                            event.preventDefault();
                            const el = this.visible()[this.active];
                            if (el) this.choose(el);
                        }
                    },

                    choose(el) {
                        const row = this.rowEl(this.row);
                        if (! row) return;
                        row.querySelector('[data-map-key]').value = el.dataset.key;
                        row.querySelector('[data-map-title]').textContent = el.dataset.title;
                        row.querySelector('[data-map-hint]').textContent = el.dataset.hint || '';
                        row.querySelector('[data-map-lang]').classList.toggle('hidden', ! el.hasAttribute('data-translatable'));
                        row.classList.toggle('opacity-60', el.dataset.key === '');
                        this.count();
                        this.save();
                        this.$refs.picker.hidePopover();
                        document.getElementById('map-pick-' + row.dataset.mapRow)?.focus();
                    },

                    saveState: null,

                    // The draft keeps what the screen shows; the last change wins.
                    save() {
                        const form = document.getElementById('import-map');
                        const sent = (this.saveSeq = (this.saveSeq || 0) + 1);
                        this.saveState = 'saving';
                        fetch(form.dataset.save, { method: 'POST', body: new FormData(form), headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                            .then(response => { if (sent === this.saveSeq) this.saveState = response.ok ? 'saved' : 'failed'; })
                            .catch(() => { if (sent === this.saveSeq) this.saveState = 'failed'; });
                    },

                    count() {
                        const label = this.$root.querySelector('[data-map-count]');
                        const mapped = [...this.$root.querySelectorAll('[data-map-key]')].filter(input => input.value !== '').length;
                        label.textContent = label.dataset.template.replace('#', mapped);
                    },
                }));
            });
        </script>
    @endpush
@endsection
