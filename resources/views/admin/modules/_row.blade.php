@php
    $installed = $moduleState !== 'available';
    $enabled = $moduleState === 'enabled';
    $target = '#module-'.str_replace('.', '-', $manifest->id);
    $description = $manifest->descriptionText();
    $moduleName = label_text($manifest->name);
    $moduleAuthor = $manifest->author();
    $moduleLinks = $manifest->links();
    // What it stands on and what stands on it, by the names the reader knows —
    // the reason an Uninstall is refused is usually on this line.
    $nameOf = fn (string $id) => label_text($modules->find($id)?->name ?? $id);
    $requires = array_map($nameOf, $manifest->requires['modules'] ?? []);
    $neededBy = array_map($nameOf, array_values(array_filter($modules->dependents($manifest->id), fn ($other) => $modules->isInstalled($other))));
    $sectionHeading = $sections[$moduleSection] ?? null;
@endphp
{{--
    Swapped standalone by HTMX (hx-swap="outerHTML"), so the id has to stay on
    this root element — see ModulesController::row(). The data-* attributes are
    what the screen's filter reads, so a swapped row filters like the others.

    Stacks below lg: the identity block sits above a full-width action row.
    From lg up the two sit side by side with the actions right-aligned.
--}}
<li id="module-{{ str_replace('.', '-', $manifest->id) }}"
    data-module-row data-state="{{ $moduleState }}" data-section="{{ $moduleSection }}" data-regions="{{ implode(' ', $manifest->regions) }}"
    data-search="{{ mb_strtolower($moduleName.' '.$manifest->name.' '.$manifest->id.' '.$description.' '.($moduleAuthor['name'] ?? '')) }}"
    class="p-4 flex flex-col gap-3 lg:flex-row lg:items-start lg:gap-6">

    <div class="min-w-0 flex-1 flex items-start gap-4">
        <x-admin.module-tile :manifest="$manifest" />

        <div class="min-w-0 flex-1">
            <div class="flex items-center gap-2 flex-wrap">
                <span class="font-semibold leading-tight">{{ $moduleName }}</span>
                {{-- The version opens what changed in it, when the module says. --}}
                @if ($manifest->changelogFile())
                    <a href="{{ route('admin.modules.docs', ['id' => $manifest->id, 'doc' => \App\Core\Modules\ModuleDocs::CHANGELOG]) }}"
                       class="badge badge-sm badge-ghost font-mono gap-1 hover:badge-neutral" title="{{ __('What\'s new') }}">
                        {{ $manifest->version }} <x-icon name="history" size="sm" />
                    </a>
                @else
                    <span class="badge badge-sm badge-ghost font-mono">{{ $manifest->version }}</span>
                @endif
                @if (! $installed)
                    <span class="badge badge-sm badge-neutral">{{ __('not installed') }}</span>
                @elseif ($enabled)
                    <span class="badge badge-sm badge-success">{{ __('enabled') }}</span>
                @else
                    <span class="badge badge-sm badge-warning">{{ __('disabled') }}</span>
                @endif
                @unless ($manifest->isBundled())
                    <span class="badge badge-sm badge-info">{{ __('Third-party') }}</span>
                @endunless
                {{-- Made for one country (a carrier, a gateway): `regions` in its manifest. --}}
                @foreach ($manifest->regions as $moduleRegion)
                    <span class="badge badge-sm badge-ghost font-mono" title="{{ __('For shops in this country') }}">{{ $moduleRegion }}</span>
                @endforeach
            </div>

            <div class="flex flex-wrap items-center gap-x-3 text-xs opacity-50 mt-1">
                <span class="font-mono">{{ $manifest->id }}</span>
                @if ($sectionHeading)
                    <span class="inline-flex items-center gap-1"><x-icon :name="$sectionHeading['icon']" size="sm" /> {{ $sectionHeading['label'] }}</span>
                @endif
            </div>

            {{-- max-w-prose: the description is the one long-form field here, and
                 full-width it ran to ~140 characters a line. --}}
            @if ($description !== '')
                <p class="text-sm opacity-70 mt-1.5 max-w-prose">{{ $description }}</p>
            @endif

            @if ($requires || $neededBy)
                <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs mt-1.5 opacity-60">
                    @if ($requires)
                        <span class="inline-flex items-center gap-1"><x-icon name="link" size="sm" /> {{ __('Requires') }}: {{ implode(', ', $requires) }}</span>
                    @endif
                    @if ($neededBy)
                        <span class="inline-flex items-center gap-1"><x-icon name="git-fork" size="sm" /> {{ __('Needed by') }}: {{ implode(', ', $neededBy) }}</span>
                    @endif
                </div>
            @endif

            {{-- Who made it and where to find them. Nothing at all for the engine's
                 own modules, which name no author; a third party's manifest fills
                 it (ModuleManifest::author() / links(), web and mail addresses only). --}}
            @if ($moduleAuthor || $moduleLinks)
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs mt-2 opacity-70">
                    @if ($moduleAuthor)
                        <span class="inline-flex items-center gap-1">
                            <x-icon name="user-round" size="sm" />
                            {{ __('Author') }}:
                            @if ($moduleAuthor['url'])
                                <a href="{{ $moduleAuthor['url'] }}" target="_blank" rel="noopener noreferrer" class="link">{{ $moduleAuthor['name'] ?? parse_url($moduleAuthor['url'], PHP_URL_HOST) }}</a>
                            @else
                                <span>{{ $moduleAuthor['name'] ?? $moduleAuthor['email'] }}</span>
                            @endif
                            @if ($moduleAuthor['url'] && ($host = parse_url($moduleAuthor['url'], PHP_URL_HOST)) && $moduleAuthor['name'])
                                <span class="opacity-60">({{ $host }})</span>
                            @endif
                        </span>
                        @if ($moduleAuthor['email'])
                            <a href="mailto:{{ $moduleAuthor['email'] }}" class="link inline-flex items-center gap-1">
                                <x-icon name="mail" size="sm" /> {{ $moduleAuthor['email'] }}
                            </a>
                        @endif
                    @endif
                    @foreach ($moduleLinks as $moduleLink)
                        <a href="{{ $moduleLink['url'] }}" target="_blank" rel="noopener noreferrer" class="link inline-flex items-center gap-1">
                            <x-icon :name="$moduleLink['icon']" size="sm" /> {{ $moduleLink['label'] }}
                        </a>
                    @endforeach
                </div>
            @endif

            {{-- Switched on, but held back: this core is not one it runs on, or
                 something it stands on is held back (PLAN §45.5). --}}
            @if ($moduleHeld = $modules->blocked()[$manifest->id] ?? null)
                <div class="alert alert-warning text-sm mt-2 py-2">
                    <x-icon name="triangle-alert" size="sm" />
                    <span>{{ match ($moduleHeld[0]) {
                        'core' => __('Not running: it needs Torgash :wanted, this is :core.', ['wanted' => $moduleHeld[1], 'core' => config('torgash.version')]),
                        'provider' => __('Not running: its files have no :class, which its manifest names. Upload the module again.', ['class' => $moduleHeld[1]]),
                        default => __('Not running: :module it needs is not running.', ['module' => $nameOf($moduleHeld[1])]),
                    } }}</span>
                </div>
            @endif

            @if ($error ?? null)
                <div class="alert alert-error text-sm mt-2 py-2">
                    <x-icon name="triangle-alert" size="sm" />
                    <span>{{ $error }}</span>
                </div>
            @endif
        </div>
    </div>

    {{-- Icons for what opens something, a switch for the state, the red one
         apart: uninstalling takes the module's tables with it. --}}
    <div class="flex flex-nowrap items-center gap-1 shrink-0 lg:justify-end pl-15 lg:pl-0">
        @if ($manifest->hasDocs())
            <a href="{{ route('admin.modules.docs', $manifest->id) }}"
               class="btn btn-ghost btn-sm btn-square" title="{{ __('Documentation') }}" aria-label="{{ __('Documentation') }}">
                <x-icon name="book-open" />
            </a>
        @endif

        @cannot('modules.manage')
            {{-- Nothing to take: the state is in the badge beside the name. --}}
        @elseif (! $installed)
            <button class="btn btn-primary btn-sm ml-1"
                    hx-post="{{ route('admin.modules.install', $manifest->id) }}"
                    hx-target="{{ $target }}" hx-swap="outerHTML">{{ __('Install') }}</button>
        @else
            @if ($manifest->hasSettings() && $enabled && auth()->user()?->can('settings.manage'))
                <a href="{{ route('admin.settings.edit', $manifest->id) }}"
                   class="btn btn-ghost btn-sm btn-square" title="{{ __('Settings') }}" aria-label="{{ __('Settings') }}">
                    <x-icon name="sliders-horizontal" />
                </a>
            @endif
            <label class="flex items-center gap-2 cursor-pointer px-2" title="{{ $enabled ? __('Disable') : __('Enable') }}">
                <input type="checkbox" class="toggle toggle-success toggle-sm" @checked($enabled)
                       hx-post="{{ route('admin.modules.toggle', $manifest->id) }}"
                       hx-target="{{ $target }}" hx-swap="outerHTML" aria-label="{{ $enabled ? __('Disable') : __('Enable') }}">
            </label>
            <span class="w-px h-5 shrink-0 bg-base-300 mx-1" aria-hidden="true"></span>
            <button class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Uninstall') }}" aria-label="{{ __('Uninstall') }}"
                    hx-post="{{ route('admin.modules.uninstall', $manifest->id) }}"
                    hx-confirm="{{ __('Uninstall :module? Its tables and settings will be removed.', ['module' => $moduleName]) }}"
                    hx-target="{{ $target }}" hx-swap="outerHTML">
                <x-icon name="trash-2" />
            </button>
        @endcannot
    </div>
</li>
