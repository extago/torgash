@extends('admin.layout')

@section('title', __('Modules'))
@section('title_hint', __('What the engine does beyond its core: each module is switched on, set up and, when no longer needed, removed without a trace. A module made for one country shows for the shops in it.'))

@section('content')
    {{--
        A list, not a <table>. The other admin index screens are genuinely
        tabular (short, comparable cells), but a module row is one long
        description plus three actions — as a table that meant a ~900px
        description column, an Actions cell too narrow for its own buttons,
        and nothing but horizontal scrolling on a phone. A flex list reflows.

        Filtered in the page, not by the server: every module on disk is
        already here (a few dozen rows), and an install or a switch swaps one
        row by HTMX — a filter that reloaded would forget where it was.
    --}}
    @php
        $stateTabs = [
            'all' => [__('All'), 'layout-grid'],
            'enabled' => [__('Enabled'), 'circle-check'],
            'disabled' => [__('Disabled'), 'circle-pause'],
            'available' => [__('Not installed'), 'download'],
        ];
    @endphp

    {{-- Folders under modules/ that cannot be read: left out so the shop
         keeps running, said here so somebody fixes them (ModuleManager::broken()). --}}
    @foreach ($modules->broken() as $brokenFolder => $brokenWhy)
        <div class="alert alert-warning text-sm">
            <x-icon name="triangle-alert" size="sm" />
            <span>{{ __('The folder modules/:folder is left out: :why. Fix the file or upload the module again; the rest of the shop runs without it.', ['folder' => $brokenFolder, 'why' => $brokenWhy]) }}</span>
        </div>
    @endforeach
    @foreach ($modules->blocked() as $missingId => $missingWhy)
        @if ($missingWhy[0] === 'missing' && ! isset($modules->broken()[$missingId]))
            <div class="alert alert-warning text-sm">
                <x-icon name="triangle-alert" size="sm" />
                <span>{{ __(':module is switched on, but its folder is not in modules/ — it is not running. Upload it again, or uninstall it.', ['module' => $missingId]) }}</span>
            </div>
        @endif
    @endforeach
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    {{-- The states as one row of tabs standing on the card (app.css,
         `admin-tabs`); switched by the script below. --}}
    <div class="admin-tabs-row">
        <nav class="admin-tabs" data-modules-tabs aria-label="{{ __('Modules') }}">
            @foreach ($stateTabs as $stateKey => [$stateLabel, $stateIcon])
                <button type="button" data-state-tab="{{ $stateKey }}" @class(['admin-tab', 'is-active' => $stateKey === 'all'])>
                    <x-icon :name="$stateIcon" size="sm" /> {{ $stateLabel }}
                    <span class="admin-tab-count" data-state-count="{{ $stateKey }}">{{ $counts[$stateKey] ?? 0 }}</span>
                </button>
            @endforeach
        </nav>
        {{-- Putting code on the server is the built-in group's alone
             (ModulesController::upload); everybody else is told how a
             module arrives. --}}
        @if ($canUpload)
            <div class="admin-tabs-actions">
                <button type="button" class="btn btn-primary gap-1.5" data-open-dialog="modal-module-upload">
                    <x-icon name="upload" size="sm" /> {{ __('Upload a module') }}
                </button>
            </div>
        @endif
    </div>

    <div class="card bg-base-100 border border-base-300" data-modules-screen>
        <div class="card-body gap-4 p-4">
            <div class="flex flex-wrap items-center gap-2">
                <label class="input input-sm grow min-w-48 gap-2">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" class="grow" data-modules-filter autocomplete="off" placeholder="{{ __('Name, code, author…') }}">
                </label>
                <select class="select select-sm w-auto" data-modules-section aria-label="{{ __('Section') }}">
                    <option value="">{{ __('Every section') }}</option>
                    @foreach ($sections as $sectionKey => $sectionHeading)
                        <option value="{{ $sectionKey }}">{{ $sectionHeading['label'] }}</option>
                    @endforeach
                </select>
                {{-- Every country's modules ship in one distribution; this hides
                     the ones made for other countries. A module for any country
                     always stays. --}}
                @if ($regions !== [])
                    <select class="select select-sm w-auto" data-modules-region aria-label="{{ __('Country') }}">
                        <option value="">{{ __('Every country') }}</option>
                        @foreach ($regions as $regionCode)
                            <option value="{{ $regionCode }}" @selected($regionCode === $shopCountry)>{{ $regionCode }}</option>
                        @endforeach
                    </select>
                @endif
                @unless ($canUpload)
                    <p class="text-xs opacity-50 ms-auto">
                        {{ __('A module is added by putting its folder into modules/ — it then appears here to be installed.') }}
                    </p>
                @endunless
            </div>

            <ul class="divide-y divide-base-300 -mx-4 border-t border-base-300">
                @foreach ($rows as $row)
                    @include('admin.modules._row', [
                        'modules' => $modules,
                        'manifest' => $row['manifest'],
                        'moduleState' => $row['state'],
                        'moduleSection' => $row['section'],
                        'sections' => $sections,
                    ])
                @endforeach
            </ul>
            <p class="text-sm opacity-60 py-6 text-center hidden" data-modules-empty>{{ __('No module matches this search.') }}</p>
        </div>
    </div>

    @if ($canUpload)
        <x-modal name="module-upload" :title="__('Upload a module')" icon="upload">
            <form method="POST" action="{{ route('admin.modules.upload') }}" enctype="multipart/form-data" class="flex flex-col gap-4">
                @csrf
                <fieldset class="fieldset p-0">
                    <legend class="fieldset-legend">{{ __('Module archive (.zip)') }}</legend>
                    <input type="file" name="archive" accept=".zip,application/zip" required class="file-input file-input-bordered w-full">
                    <p class="label whitespace-normal">{{ __('The module\'s folder, zipped: manifest.json at the root of the archive or in one folder. Up to 20 MB.') }}</p>
                </fieldset>
                <ul class="text-xs opacity-70 list-disc pl-5 flex flex-col gap-1">
                    <li>{{ __('A new module is put into modules/ and waits here to be installed — nothing touches the database yet.') }}</li>
                    <li>{{ __('A newer version of a module already here replaces it; an installed one gets its new migrations. The old folder is kept in storage/app/module-backups/ and put back if anything fails.') }}</li>
                    <li>{{ __('A module is code that runs with the shop\'s full rights. Upload only what you got from its author.') }}</li>
                </ul>
                <div>
                    <button class="btn btn-primary btn-sm gap-1.5"><x-icon name="upload" size="sm" /> {{ __('Upload') }}</button>
                </div>
            </form>
        </x-modal>
    @endif

    <script nonce="{{ csp_nonce() }}">
        (function () {
            var screen = document.querySelector('[data-modules-screen]');
            if (!screen) return;

            var input = screen.querySelector('[data-modules-filter]');
            var section = screen.querySelector('[data-modules-section]');
            var region = screen.querySelector('[data-modules-region]');
            var tabs = document.querySelectorAll('[data-state-tab]');
            var state = 'all';

            function apply() {
                var q = input.value.trim().toLowerCase();
                var shown = 0;
                var counts = { all: 0, enabled: 0, disabled: 0, available: 0 };

                // Rows are read afresh each time: an install swaps a row out.
                screen.querySelectorAll('[data-module-row]').forEach(function (row) {
                    var matches = (q === '' || row.dataset.search.indexOf(q) !== -1)
                        && (section.value === '' || row.dataset.section === section.value)
                        && (!region || region.value === '' || row.dataset.regions === '' || row.dataset.regions.split(' ').indexOf(region.value) !== -1);

                    // The tabs count what the search and the section left, so
                    // "Not installed 3" is about what is being looked at.
                    if (matches) { counts.all++; counts[row.dataset.state]++; }

                    row.hidden = !(matches && (state === 'all' || row.dataset.state === state));
                    if (!row.hidden) shown++;
                });

                document.querySelectorAll('[data-state-count]').forEach(function (badge) {
                    badge.textContent = counts[badge.dataset.stateCount] || 0;
                });
                screen.querySelector('[data-modules-empty]').classList.toggle('hidden', shown > 0);
            }

            tabs.forEach(function (tab) {
                tab.addEventListener('click', function () {
                    state = tab.dataset.stateTab;
                    tabs.forEach(function (t) { t.classList.toggle('is-active', t === tab); });
                    apply();
                });
            });
            input.addEventListener('input', apply);
            section.addEventListener('change', apply);
            if (region) region.addEventListener('change', apply);
            // After an install, a switch or an uninstall: the row changed state.
            document.body.addEventListener('htmx:afterSwap', apply);
            if (input.value || (region && region.value)) apply();
        })();
    </script>
@endsection
