{{--
    One module's settings on a page of their own (the gear on the Modules
    screen, a link from the admin search): the sticky bar every form has, the
    module introducing itself with the way to its own screens and its
    documentation, then its cards — with a table of contents beside them once
    there are enough cards to lose one's place in.
--}}
@php
    $moduleManifest = $settingsSection['module'];
    $isCore = $moduleManifest->id === \App\Core\Settings\CoreSettings::ID;
    $moduleTitle = label_text($moduleManifest->name);
    $moduleDescription = $isCore ? '' : $moduleManifest->descriptionText();
    $moduleDocs = ! $isCore && $moduleManifest->hasDocs();
    $moduleChangelog = ! $isCore && $moduleManifest->changelogFile();
    $tileIcon = $isCore ? 'store' : null;

    // The cards' titles, in order, for the table of contents — the same split
    // _section makes: a `group` field starts a card, fields before the first
    // group are the module's own.
    $cardTitles = [];
    $pending = null;
    $hasFields = false;
    foreach ($settingsSection['fields'] as $tocField) {
        if (($tocField['type'] ?? 'text') === 'group') {
            if ($hasFields) {
                $cardTitles[] = $pending ?? __('General');
            }
            $pending = label_text($tocField['label'] ?? null, null, $tocField['key'] ?? '');
            $hasFields = false;
        } else {
            $hasFields = true;
        }
    }
    if ($hasFields) {
        $cardTitles[] = $pending ?? __('General');
    }
    $withToc = count($cardTitles) >= 4;
@endphp

<x-admin.form-bar>
    <a href="{{ route('admin.settings') }}#module-{{ $moduleManifest->id }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('All settings') }}" aria-label="{{ __('All settings') }}">
        <x-icon name="arrow-left" size="lg" />
    </a>
    <x-admin.module-tile :manifest="$moduleManifest" size="sm" :icon="$tileIcon" />
    <div class="flex-1 min-w-0">
        <div class="text-xs opacity-50 leading-none">{{ __('Settings') }}</div>
        <div class="font-semibold truncate leading-tight">{{ $moduleTitle }}</div>
    </div>
    <div class="dropdown dropdown-end">
        <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}" aria-label="{{ __('More actions') }}">
            <x-icon name="settings" size="lg" />
        </div>
        <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-72 mt-2 p-2 z-50 border border-base-300">
            <li><a href="{{ route('admin.settings') }}#module-{{ $moduleManifest->id }}"><x-icon name="sliders-horizontal" /> {{ __('All settings') }}</a></li>
            @unless ($isCore)
                <li><a href="{{ route('admin.modules') }}#module-{{ str_replace('.', '-', $moduleManifest->id) }}"><x-icon name="layout-grid" /> {{ __('The module on the Modules screen') }}</a></li>
            @endunless
            @if ($moduleDocs)
                <li><a href="{{ route('admin.modules.docs', $moduleManifest->id) }}"><x-icon name="book-open" /> {{ __('Documentation') }}</a></li>
            @endif
            <li class="menu-title p-0 my-1" role="separator"><span class="block h-px bg-base-300"></span></li>
            <li>
                <button type="submit" form="settings-reset" @disabled($changed === [])
                        @class(['text-error' => $changed !== [], 'opacity-50' => $changed === []])>
                    <x-icon name="rotate-ccw" /> {{ __('Restore the defaults') }}
                </button>
            </li>
        </ul>
    </div>
    <x-slot:actions>
        <button type="submit" form="settings-form" class="btn btn-primary btn-sm">{{ __('Save') }}</button>
    </x-slot:actions>
</x-admin.form-bar>

@if (session('status'))
    <div class="alert alert-success text-sm">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="alert alert-error text-sm flex-col items-start gap-1">
        @foreach ($errors->all() as $error)
            <div>{{ $error }}</div>
        @endforeach
    </div>
@endif

{{-- The module introduces itself: what it is, and where it is USED — a
     settings page is where people come looking for "and where is the screen". --}}
@if (! $isCore)
    <section class="card bg-base-100 border border-base-300">
        <div class="card-body flex-row items-start gap-4">
            <x-admin.module-tile :manifest="$moduleManifest" size="lg" />
            <div class="min-w-0 flex-1 flex flex-col gap-2">
                <div class="flex items-center gap-2 flex-wrap">
                    <h1 class="text-lg font-semibold leading-tight">{{ $moduleTitle }}</h1>
                    @if ($moduleChangelog)
                        <a href="{{ route('admin.modules.docs', ['id' => $moduleManifest->id, 'doc' => \App\Core\Modules\ModuleDocs::CHANGELOG]) }}"
                           class="badge badge-sm badge-ghost font-mono gap-1 hover:badge-neutral" title="{{ __('What\'s new') }}">
                            {{ $moduleManifest->version }} <x-icon name="history" size="sm" />
                        </a>
                    @else
                        <span class="badge badge-sm badge-ghost font-mono">{{ $moduleManifest->version }}</span>
                    @endif
                    <span class="text-xs font-mono opacity-50">{{ $moduleManifest->id }}</span>
                </div>
                @if ($moduleDescription !== '')
                    <p class="text-sm opacity-70 max-w-prose">{{ $moduleDescription }}</p>
                @endif
                @if ($screens || $moduleDocs)
                    <div class="flex flex-wrap gap-2 mt-1">
                        @foreach ($screens as $screen)
                            <a href="{{ $screen['href'] }}" class="btn btn-sm gap-1.5">
                                @if (! empty($screen['icon']) && \App\Core\Support\Icons::exists($screen['icon']))
                                    <x-icon :name="$screen['icon']" size="sm" />
                                @endif
                                {{ $screen['title'] }}
                            </a>
                        @endforeach
                        @if ($moduleDocs)
                            <a href="{{ route('admin.modules.docs', $moduleManifest->id) }}" class="btn btn-sm btn-ghost gap-1.5">
                                <x-icon name="book-open" size="sm" /> {{ __('Documentation') }}
                            </a>
                        @endif
                    </div>
                @endif
            </div>
            @if ($changed !== [])
                <div class="hidden md:block text-xs text-primary whitespace-nowrap" title="{{ __('Settings that differ from the module\'s defaults') }}">
                    {{ trans_choice('{1} :count setting changed|[2,*] :count settings changed', count($changed), ['count' => count($changed)]) }}
                </div>
            @endif
        </div>
    </section>
@endif

<div @class(['grid gap-4 items-start', 'xl:grid-cols-[minmax(0,1fr)_15rem]' => $withToc, 'max-w-5xl' => ! $withToc])>
    <div class="min-w-0">
        @include('admin.settings._section', ['settingsSection' => $settingsSection])
    </div>

    @if ($withToc)
        <nav class="hidden xl:flex flex-col gap-0.5 sticky top-36 text-sm" aria-label="{{ __('On this page') }}">
            @foreach ($cardTitles as $cardIndex => $cardTitle)
                <a href="#settings-card-{{ $cardIndex }}" class="rounded-field px-2 py-1 hover:bg-base-200 truncate">{{ $cardTitle }}</a>
            @endforeach
        </nav>
    @endif
</div>

<form id="settings-reset" method="POST" action="{{ route('admin.settings.reset', $moduleManifest->id) }}" class="hidden"
      data-confirm="{{ __('Return every setting of :name to its default? A storefront\'s own values are kept.', ['name' => $moduleTitle]) }}">
    @csrf
</form>
