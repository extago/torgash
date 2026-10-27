{{--
    The theme configurator (PLAN §37): a screen of its own, the whole window —
    the settings in a column on the left, the storefront in a frame beside
    them, and over both a bar with the page in the frame, the screen it is
    drawn on (computer / tablet / phone) and Save.

    Every change is sent as a DRAFT (themes.preview → the admin's session) and
    the frame reloads the page it is on, at the place it was scrolled to; the
    storefront draws that draft only for a request the browser marks as a
    frame, from somebody allowed to manage themes — so a customer, or this
    same storefront in another tab, keeps seeing what is saved until "Save".
    Save goes in place (JSON), so the frame stays where it was.

    The fields are the theme's own (theme.json `settings`), drawn by type:
    segmented (a row of buttons — radios, so it works with no script), color
    (the theme's swatches, or any colour), checkbox (a switch), select, image,
    code. ONE form holds every field of every section — a section only shows
    some of them — so a save never loses what another section holds.
    The ready-made configurations, the reset and the import are forms of their
    own BESIDE it, never inside it.

    A section may say in theme.json's `setting_section_meta` which icon it
    wears and which screen it is best looked at on (`device`: "phone" for the
    phone's settings — opening it turns the frame into a phone).
--}}
@php
    $customizeLabel = fn ($label) => label_text($label);
    // Sections in the order the theme names them, then any it did not name.
    $customizeSectionKeys = array_values(array_unique([
        ...array_values(array_intersect(array_keys($sectionLabels), array_keys($sections))),
        ...array_keys($sections),
    ]));
    // A field's default as the form would send it, for "changed" and "reset".
    $customizeDefault = function (array $field): string {
        $default = $field['default'] ?? '';

        return ($field['type'] ?? '') === 'checkbox' ? (filter_var($default, FILTER_VALIDATE_BOOLEAN) ? '1' : '0') : (string) $default;
    };
    $customizeDefaults = [];
    $customizeFieldSection = [];
    foreach ($sections as $customizeSectionKey => $customizeFields) {
        foreach ($customizeFields as $customizeField) {
            $customizeDefaults[$customizeField['key']] = $customizeDefault($customizeField);
            $customizeFieldSection[$customizeField['key']] = $customizeSectionKey;
            // A colour's dark twin: drawn inside its field, empty = automatic.
            if (($customizeField['type'] ?? '') === 'color' && array_key_exists('dark', $customizeField)) {
                $customizeDefaults[$customizeField['key'].\App\Core\Themes\ThemeSettings::DARK] = '';
            }
        }
    }
    // Colour keys (and their dark twins) — what a ready-made combination sets
    // or puts back; and the section the combinations are shown in: the one
    // holding the first colour.
    $customizeColourKeys = [];
    $customizeSchemeSection = null;
    foreach ($sections as $customizeSectionKey => $customizeFields) {
        foreach ($customizeFields as $customizeField) {
            // A field that belongs with the colours (`scheme`, the dark tone)
            // is set and put back by a combination too.
            if (! empty($customizeField['scheme'])) {
                $customizeColourKeys[] = $customizeField['key'];
            }
            if (($customizeField['type'] ?? '') === 'color') {
                $customizeSchemeSection ??= $customizeSectionKey;
                $customizeColourKeys[] = $customizeField['key'];
                if (array_key_exists('dark', $customizeField)) {
                    $customizeColourKeys[] = $customizeField['key'].\App\Core\Themes\ThemeSettings::DARK;
                }
            }
        }
    }
    $customizeSectionInfo = collect($customizeSectionKeys)->mapWithKeys(fn ($key) => [$key => [
        'label' => $sectionLabels[$key] ?? ucfirst($key),
        'icon' => (string) ($sectionMeta[$key]['icon'] ?? 'sliders-horizontal'),
        'device' => in_array($sectionMeta[$key]['device'] ?? null, ['desktop', 'tablet', 'phone'], true) ? $sectionMeta[$key]['device'] : null,
    ]])->all();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('Configure the theme') }} · {{ $themeName }} · Torgash</title>
    <script nonce="{{ csp_nonce() }}">
        (function () {
            try {
                var saved = localStorage.getItem('torgash-theme');
                if (saved === 'torgash-dark' || saved === 'torgash-light') {
                    document.documentElement.setAttribute('data-theme', saved);
                }
            } catch (e) {}
        })();
    </script>
    <link rel="stylesheet" href="{{ asset_url('/assets/css/app.css') }}">
    <script nonce="{{ csp_nonce() }}" src="{{ asset_url('/assets/js/vendor/alpine.min.js') }}" defer></script>
    {{-- A screen of its own, so it loads what the admin layout would: the
         <x-modal> windows ("Share the settings") open through this. --}}
    <script nonce="{{ csp_nonce() }}" src="{{ asset_url('/assets/js/actions.js') }}" defer></script>
</head>
<body class="torgash-admin bg-base-200 h-dvh overflow-hidden">
<div class="h-dvh flex flex-col"
     x-data="themeCustomizer({
        previewUrl: @js(route('admin.themes.preview', $storefront)),
        saveUrl: @js(route('admin.themes.save', $storefront)),
        defaults: @js($customizeDefaults),
        colourKeys: @js($customizeColourKeys),
        fieldSection: @js($customizeFieldSection),
        sections: @js($customizeSectionInfo),
        pages: @js($previewPages),
        startUrl: @js($previewUrl),
        flash: @js(session('status') ?: ($errors->any() ? $errors->first() : '')),
        flashError: @js($errors->any()),
        texts: {
            saved: @js(__('Saved. Customers see it now.')),
            failed: @js(__('Not saved — try again.')),
        },
     })"
     x-on:keydown.window.ctrl.s.prevent="save()" x-on:keydown.window.meta.s.prevent="save()"
     x-on:beforeunload.window="if (dirty) { $event.preventDefault(); $event.returnValue = ''; }">

    {{-- ===== The bar ===== --}}
    <header class="h-14 shrink-0 bg-base-100 border-b border-base-300 flex items-center gap-2 px-2 sm:px-3">
        <a href="{{ route('admin.themes.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Themes') }}" aria-label="{{ __('Themes') }}"><x-icon name="arrow-left" /></a>
        <div class="min-w-0 leading-tight lg:w-[17.5rem] shrink-0">
            <div class="font-semibold truncate">{{ $themeName }}</div>
            <div class="text-xs opacity-60 truncate">{{ $storefront->name }}</div>
        </div>

        {{-- The page in the frame and the screen it is drawn on. --}}
        <div class="hidden lg:flex items-center gap-2 flex-1 min-w-0">
            <label class="select select-sm w-64 shrink min-w-0">
                <x-icon name="eye" size="sm" class="opacity-60" />
                <select x-model="page" x-on:change="go(page)" aria-label="{{ __('The page in the frame') }}">
                    <template x-for="p in pages" :key="p.url"><option :value="p.url" :selected="p.url === page" x-text="p.label"></option></template>
                    <option value="" :selected="page === ''" x-show="page === ''" disabled>{{ __('Another page') }}</option>
                </select>
            </label>
            <div class="join" role="group" aria-label="{{ __('Screen') }}">
                @foreach (['desktop' => ['monitor', __('Computer')], 'tablet' => ['tablet', __('Tablet')], 'phone' => ['smartphone', __('Phone')]] as $customizeDevice => [$customizeDeviceIcon, $customizeDeviceLabel])
                    <button type="button" class="btn btn-sm join-item" :class="device === @js($customizeDevice) ? 'btn-active btn-neutral' : 'btn-ghost border-base-300'"
                            x-on:click="setDevice(@js($customizeDevice))" title="{{ $customizeDeviceLabel }}" aria-label="{{ $customizeDeviceLabel }}"
                            :aria-pressed="device === @js($customizeDevice)"><x-icon :name="$customizeDeviceIcon" size="sm" /></button>
                @endforeach
            </div>
            <a :href="frameUrl" target="_blank" rel="noopener" class="btn btn-ghost btn-sm btn-square" title="{{ __('Open the page in a new tab') }}" aria-label="{{ __('Open the page in a new tab') }}"><x-icon name="external-link" size="sm" /></a>
            <span class="loading loading-spinner loading-xs opacity-60" x-show="loading" x-cloak></span>
        </div>

        {{-- On a narrow screen: the settings OR the frame. --}}
        <div class="lg:hidden flex-1 flex justify-center">
            <div class="join">
                <button type="button" class="btn btn-sm join-item" :class="pane === 'settings' ? 'btn-neutral' : 'btn-ghost border-base-300'" x-on:click="pane = 'settings'">{{ __('Settings') }}</button>
                <button type="button" class="btn btn-sm join-item" :class="pane === 'preview' ? 'btn-neutral' : 'btn-ghost border-base-300'" x-on:click="pane = 'preview'">{{ __('Preview') }}</button>
            </div>
        </div>

        <div class="flex items-center gap-2 shrink-0">
            <span class="hidden xl:inline text-xs opacity-60" x-show="dirty" x-cloak>{{ __('Not saved — only you see it in the frame.') }}</span>
            <button type="button" class="btn btn-ghost btn-sm" x-show="dirty" x-cloak x-on:click="discard()" title="{{ __('Back to what is saved') }}">
                <x-icon name="undo-2" size="sm" /> <span class="hidden sm:inline">{{ __('Discard') }}</span>
            </button>
            <button type="submit" form="theme-settings" class="btn btn-primary btn-sm min-w-28" :disabled="saving" x-on:click.prevent="save()">
                <span class="loading loading-spinner loading-xs" x-show="saving" x-cloak></span>
                <x-icon name="check" size="sm" x-show="! saving" /> {{ __('Save') }}
            </button>
        </div>
    </header>

    <div class="flex-1 min-h-0 flex">
        {{-- ===== The settings ===== --}}
        <aside class="w-full lg:w-[22rem] shrink-0 bg-base-100 border-r border-base-300 flex-col min-h-0"
               :class="pane === 'settings' ? 'flex' : 'hidden lg:flex'">

            {{-- Above the list: search; inside a section: the way back. --}}
            <div class="shrink-0 border-b border-base-300 px-3 h-14 flex items-center gap-2">
                <template x-if="view !== 'home'">
                    <div class="flex items-center gap-2 min-w-0 flex-1">
                        <button type="button" class="btn btn-ghost btn-sm btn-square" x-on:click="home()" aria-label="{{ __('Back') }}"><x-icon name="chevron-left" /></button>
                        <span class="font-semibold truncate" x-text="view === 'presets' ? @js(__('Ready-made configurations')) : sections[view]?.label"></span>
                    </div>
                </template>
                <template x-if="view === 'home'">
                    <label class="input input-sm w-full">
                        <x-icon name="search" size="sm" class="opacity-50" />
                        <input type="search" x-model="query" placeholder="{{ __('Find a setting') }}" x-ref="search">
                    </label>
                </template>
            </div>

            <div class="flex-1 min-h-0 overflow-y-auto" x-ref="scroller">
                {{-- The list of sections. --}}
                <nav x-show="view === 'home' && query.trim() === ''" class="p-2 flex flex-col gap-0.5">
                    {{-- Always: even a theme with no presets of its own has
                         the shop's saved styles, and the place to save one. --}}
                    <button type="button" class="flex items-center gap-3 rounded-field px-3 h-12 hover:bg-base-200 text-left" x-on:click="open('presets')">
                        <span class="w-8 h-8 rounded-field bg-primary/10 text-primary grid place-items-center shrink-0"><x-icon name="sparkles" size="sm" /></span>
                        <span class="flex-1 font-medium">{{ __('Ready-made configurations') }}</span>
                        <x-icon name="chevron-right" size="sm" class="opacity-40" />
                    </button>
                    <div class="h-px bg-base-300 my-1.5 mx-3"></div>
                    @foreach ($customizeSectionInfo as $customizeSectionKey => $customizeSection)
                        <button type="button" class="flex items-center gap-3 rounded-field px-3 h-12 hover:bg-base-200 text-left" x-on:click="open(@js($customizeSectionKey))">
                            <span class="w-8 h-8 rounded-field bg-base-200 grid place-items-center shrink-0"><x-icon :name="$customizeSection['icon']" size="sm" /></span>
                            <span class="flex-1 font-medium">{{ $customizeSection['label'] }}</span>
                            <span class="badge badge-sm badge-soft badge-primary" x-show="changedIn(@js($customizeSectionKey)) > 0" x-cloak
                                  x-text="changedIn(@js($customizeSectionKey))" title="{{ __('Changed from the theme\'s defaults') }}"></span>
                            <x-icon name="chevron-right" size="sm" class="opacity-40" />
                        </button>
                    @endforeach
                </nav>

                {{-- Ready-made configurations: forms of their own. --}}
                <div x-show="view === 'presets'" x-cloak class="p-4 flex flex-col gap-4">
                    {{-- The shop's own styles (PLAN §52): the look in the form
                         now, saved under a name, for every storefront wearing
                         this theme. The values travel with the request —
                         copied from the settings form when it is sent. --}}
                    <div class="flex flex-col gap-2">
                        <div class="text-xs font-semibold uppercase tracking-wide opacity-50">{{ __('Your styles') }}</div>
                        @forelse ($styles as $customizeStyle)
                            <div class="flex items-center gap-2 border border-base-300 rounded-box px-3 py-2">
                                <span class="flex-1 min-w-0 truncate font-medium">{{ $customizeStyle['name'] }}</span>
                                <form method="POST" action="{{ route('admin.themes.styles.apply', [$storefront, $customizeStyle['key']]) }}"
                                      x-on:submit="if (! confirm(@js(__('Apply this configuration? The storefront\'s current settings are replaced.')))) { $event.preventDefault(); } else { dirty = false; }">
                                    @csrf
                                    <button class="btn btn-primary btn-xs">{{ __('Apply') }}</button>
                                </form>
                                <form method="POST" action="{{ route('admin.themes.styles.destroy', [$storefront, $customizeStyle['key']]) }}"
                                      x-on:submit="if (! confirm(@js(__('Delete this style?')))) { $event.preventDefault(); } else { dirty = false; }">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-ghost btn-xs btn-square" title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}"><x-icon name="trash-2" size="sm" /></button>
                                </form>
                            </div>
                        @empty
                            <p class="text-xs opacity-60">{{ __('None yet. Set the theme up the way you like it and keep it here — to come back to, or to try something else without losing it.') }}</p>
                        @endforelse
                        <form method="POST" action="{{ route('admin.themes.styles.store', $storefront) }}" class="join w-full"
                              x-on:submit="carry($event.target)">
                            @csrf
                            <input type="text" name="name" required maxlength="60" class="input input-sm join-item flex-1 min-w-0"
                                   placeholder="{{ __('Name of the style') }}" aria-label="{{ __('Name of the style') }}">
                            <button class="btn btn-sm join-item gap-1"><x-icon name="bookmark-plus" size="sm" /> {{ __('Save as a style') }}</button>
                        </form>
                    </div>
                    @if ($presets !== [])
                        <div class="text-xs font-semibold uppercase tracking-wide opacity-50 pt-2 border-t border-base-300">{{ __('From the theme') }}</div>
                    @endif
                        @foreach ($presets as $customizePreset)
                            <form method="POST" action="{{ route('admin.themes.preset', $storefront) }}" class="border border-base-300 rounded-box overflow-hidden"
                                  x-on:submit="if (! confirm(@js(__('Apply this configuration? The storefront\'s current settings are replaced.')))) { $event.preventDefault(); } else { dirty = false; }">
                                @csrf
                                <input type="hidden" name="preset" value="{{ $customizePreset['key'] }}">
                                @if ($customizePreset['preview_url'])
                                    <img src="{{ $customizePreset['preview_url'] }}" alt="" class="w-full aspect-[16/9] object-cover object-top bg-base-200">
                                @endif
                                <div class="p-3 flex flex-col gap-2">
                                    <div class="font-semibold">{{ $customizeLabel($customizePreset['name'] ?? $customizePreset['key']) }}</div>
                                    @if (! empty($customizePreset['description']))
                                        <p class="text-xs opacity-70">{{ $customizeLabel($customizePreset['description']) }}</p>
                                    @endif
                                    @if (! empty($customizePreset['blocks']))
                                        <label class="label cursor-pointer gap-2 text-xs whitespace-normal items-start">
                                            <input type="checkbox" name="with_blocks" value="1" class="checkbox checkbox-sm">
                                            <span class="text-base-content">{{ __('Also lay out its blocks (replaces the blocks in :zones — for every storefront)', ['zones' => implode(', ', array_keys($customizePreset['blocks']))]) }}</span>
                                        </label>
                                    @endif
                                    <button class="btn btn-primary btn-sm self-start">{{ __('Apply') }}</button>
                                </div>
                            </form>
                        @endforeach
                </div>

                <p x-show="view === 'home' && query.trim() !== '' && found === 0" x-cloak class="p-6 text-sm opacity-60 text-center">{{ __('Nothing found.') }}</p>

                {{-- Every field of every section, in one form. --}}
                <form method="POST" action="{{ route('admin.themes.save', $storefront) }}" id="theme-settings" x-ref="form"
                      x-on:change="changed()" x-on:input.debounce.300ms="changed()" class="p-4 flex flex-col gap-6"
                      x-show="view !== 'presets' && (view !== 'home' || query.trim() !== '')">
                    @csrf
                    @foreach ($sections as $customizeSectionKey => $customizeFields)
                        @php $customizeGroup = null; @endphp
                        @if ($customizeSectionKey === $customizeSchemeSection && $colourSchemes !== [])
                            {{-- Ready-made combinations: put into the form, so the
                                 frame shows them and they can still be adjusted;
                                 nothing is kept until Save. Colours a combination
                                 does not name go back to the theme's own. --}}
                            <div class="flex flex-col gap-2" x-show="view === @js($customizeSectionKey)">
                                <div class="text-sm font-semibold">{{ __('Ready-made combinations') }}</div>
                                <div class="grid grid-cols-2 gap-2">
                                    @foreach ($colourSchemes as $customizeScheme)
                                        @php
                                            $schemeSwatches = collect(['primary', 'bar_color', 'secondary', 'accent'])
                                                ->map(fn ($k) => $customizeScheme['colours'][$k] ?? ($customizeDefaults[$k] ?? null))
                                                ->filter()->values();
                                        @endphp
                                        <button type="button" class="flex items-center gap-2 rounded-field border border-base-300 px-2 py-1.5 hover:border-base-content/40 text-left"
                                                x-on:click="applyScheme(@js($customizeScheme['colours']))">
                                            <span class="flex -space-x-1.5 shrink-0">
                                                @foreach ($schemeSwatches as $schemeSwatch)
                                                    <span class="w-4 h-4 rounded-full ring-2 ring-base-100" style="background: {{ $schemeSwatch }}"></span>
                                                @endforeach
                                            </span>
                                            <span class="text-xs truncate">{{ $customizeScheme['name'] }}</span>
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                        @foreach ($customizeFields as $customizeField)
                            @if (($customizeField['group'] ?? null) !== null && $customizeField['group'] !== $customizeGroup)
                                @php $customizeGroup = $customizeField['group']; @endphp
                                {{-- A heading inside a long section (the colours' groups);
                                     the search's flat list of matches goes without. --}}
                                <div class="text-xs font-semibold uppercase tracking-wide opacity-50 pt-2 -mb-3 border-t border-base-300 first:border-0 first:pt-0"
                                     x-show="view === @js($customizeSectionKey)">{{ $customizeLabel($customizeGroup) }}</div>
                            @endif
                            @php
                                $fieldKey = $customizeField['key'];
                                $fieldName = 'values['.$fieldKey.']';
                                $fieldValue = $values[$fieldKey] ?? ($customizeField['default'] ?? null);
                                $fieldLabel = $customizeLabel($customizeField['label'] ?? $fieldKey);
                                $fieldHelp = ! empty($customizeField['help']) ? $customizeLabel($customizeField['help']) : '';
                                $fieldType = $customizeField['type'] ?? 'segmented';
                            @endphp
                            <div role="group" aria-label="{{ $fieldLabel }}" class="flex flex-col gap-2"
                                 data-label="{{ mb_strtolower($fieldLabel) }}" data-search="{{ mb_strtolower($fieldLabel.' '.$fieldHelp) }}"
                                 x-show="shows(@js($fieldKey), searchHelp ? $el.dataset.search : $el.dataset.label)">
                                <div class="flex items-center gap-2 min-h-7">
                                    <div class="flex-1 min-w-0">
                                        <div class="text-xs opacity-50" x-show="view === 'home'" x-cloak>{{ $customizeSectionInfo[$customizeSectionKey]['label'] ?? '' }}</div>
                                        <div class="text-sm font-semibold">{{ $fieldLabel }}</div>
                                    </div>
                                    <button type="button" class="btn btn-ghost btn-xs btn-square opacity-60 hover:opacity-100" x-show="isChanged(@js($fieldKey))" x-cloak
                                            x-on:click="resetField(@js($fieldKey))" title="{{ __('Back to the theme\'s default') }}" aria-label="{{ __('Back to the theme\'s default') }}"><x-icon name="rotate-ccw" size="sm" /></button>
                                    @if ($fieldType === 'checkbox')
                                        <input type="hidden" name="{{ $fieldName }}" value="0">
                                        <input type="checkbox" name="{{ $fieldName }}" value="1" class="toggle toggle-sm toggle-primary" aria-label="{{ $fieldLabel }}" @checked(filter_var($fieldValue, FILTER_VALIDATE_BOOLEAN))>
                                    @endif
                                </div>

                                @switch ($fieldType)
                                    @case ('color')
                                        @if (array_key_exists('dark', $customizeField))
                                            @php $fieldDarkKey = $fieldKey.\App\Core\Themes\ThemeSettings::DARK; @endphp
                                            {{-- A colour with two palettes (PLAN §52): the light one,
                                                 and the dark one — "automatic" (worked out from the
                                                 light, the same way the page does) or the shop's own.
                                                 Under both, the contrast where the colour stands, and a
                                                 readable shade only when asked for. --}}
                                            <div x-data="themeColour({
                                                    key: @js($fieldKey),
                                                    light: @js(strtolower((string) $fieldValue)),
                                                    dark: @js(strtolower((string) ($values[$fieldDarkKey] ?? ''))),
                                                    lightDefault: @js(strtolower((string) ($customizeField['default'] ?? ''))),
                                                    darkDefault: @js(strtolower((string) $customizeField['dark'])),
                                                    role: @js((string) ($customizeField['role'] ?? '')),
                                                    contrast: @js((string) ($customizeField['contrast'] ?? 'none')),
                                                    inkField: @js((string) ($customizeField['ink_field'] ?? '')),
                                                    hasOn: @js(! empty($customizeField['on'])),
                                                    onDefault: @js((array) ($customizeField['on_default'] ?? [])),
                                                 })" class="flex flex-col gap-3">
                                                <input type="hidden" name="{{ $fieldName }}" :value="light" value="{{ $fieldValue }}">
                                                <input type="hidden" name="values[{{ $fieldDarkKey }}]" :value="dark" value="{{ $values[$fieldDarkKey] ?? '' }}">
                                                <div class="join self-start" role="group" aria-label="{{ __('Palette') }}">
                                                    <button type="button" class="btn btn-xs join-item gap-1" :class="tab === 'light' ? 'btn-neutral' : 'btn-ghost border-base-300'"
                                                            x-on:click="tab = 'light'" :aria-pressed="tab === 'light'"><x-icon name="sun" size="sm" /> {{ __('Light') }}</button>
                                                    <button type="button" class="btn btn-xs join-item gap-1" :class="tab === 'dark' ? 'btn-neutral' : 'btn-ghost border-base-300'"
                                                            x-on:click="tab = 'dark'" :aria-pressed="tab === 'dark'"><x-icon name="moon" size="sm" /> {{ __('Dark') }}</button>
                                                </div>
                                                <label class="label cursor-pointer justify-start gap-2 text-sm" x-show="tab === 'dark'" x-cloak>
                                                    <input type="checkbox" class="checkbox checkbox-sm" :checked="dark === ''" x-on:change.stop="setAuto($event.target.checked)">
                                                    <span class="text-base-content">{{ __('Automatic — from the light colour') }}</span>
                                                </label>
                                                <div class="flex flex-wrap gap-2">
                                                    @foreach ((array) ($customizeField['swatches'] ?? []) as $fieldSwatch)
                                                        <button type="button" class="w-7 h-7 rounded-full border-2 transition-transform"
                                                                style="background: {{ $fieldSwatch }}"
                                                                :class="shown() === @js(strtolower($fieldSwatch)) ? 'border-base-content scale-110' : 'border-base-100 ring-1 ring-base-300'"
                                                                x-on:click="pick(@js(strtolower($fieldSwatch)))"
                                                                aria-label="{{ $fieldSwatch }}"></button>
                                                    @endforeach
                                                </div>
                                                <div class="flex items-center gap-2">
                                                    <input type="color" :value="shown()" x-on:input.stop="pick($event.target.value, false)" x-on:change.stop="pick($event.target.value)"
                                                           class="w-9 h-9 cursor-pointer rounded-field border border-base-300 bg-transparent p-0.5" aria-label="{{ __('Your own colour') }}">
                                                    <input type="text" class="input input-sm w-28 font-mono" maxlength="7" spellcheck="false" aria-label="{{ __('Your own colour') }}"
                                                           :value="shown()" x-on:input.stop x-on:change.stop="if (/^#[0-9a-f]{6}$/i.test($event.target.value)) { pick($event.target.value.toLowerCase()) } else { $event.target.value = shown() }">
                                                    <span class="text-xs opacity-50" x-show="tab === 'dark' && dark === ''" x-cloak>{{ __('automatic') }}</span>
                                                </div>
                                                <template x-if="ratio() !== null">
                                                    <div class="flex items-center gap-2 text-xs flex-wrap">
                                                        <span class="inline-flex items-center gap-1.5 rounded-field px-2 py-1"
                                                              :class="ratio() >= 4.5 ? 'bg-success/15' : (ratio() >= 3 ? 'bg-warning/30' : 'bg-error/15')">
                                                            <span x-text="against() === 'ink' ? @js(__('Text on it')) : @js(__('On the page'))"></span>
                                                            <span class="font-mono font-semibold" x-text="ratio().toFixed(1).replace('.', ',') + ':1'"></span>
                                                            <span x-text="ratio() >= 4.5 ? @js(__('reads well')) : (ratio() >= 3 ? @js(__('large text only')) : @js(__('hard to read')))"></span>
                                                        </span>
                                                        <button type="button" class="btn btn-ghost btn-xs" x-show="ratio() < 4.5" x-on:click="readable()">{{ __('Pick a readable shade') }}</button>
                                                    </div>
                                                </template>
                                            </div>
                                            @break
                                        @endif
                                        <div x-data="{ color: @js((string) $fieldValue) }" class="flex flex-col gap-3"
                                             x-on:theme-field-reset.window="if ($event.detail.key === @js($fieldKey)) color = $event.detail.value">
                                            <input type="hidden" name="{{ $fieldName }}" :value="color" value="{{ $fieldValue }}">
                                            <div class="flex flex-wrap gap-2">
                                                @foreach ((array) ($customizeField['swatches'] ?? []) as $fieldSwatch)
                                                    <button type="button" class="w-7 h-7 rounded-full border-2 transition-transform"
                                                            style="background: {{ $fieldSwatch }}"
                                                            :class="color.toLowerCase() === @js(strtolower($fieldSwatch)) ? 'border-base-content scale-110' : 'border-base-100 ring-1 ring-base-300'"
                                                            x-on:click="color = @js($fieldSwatch); $nextTick(() => $dispatch('change'))"
                                                            aria-label="{{ $fieldSwatch }}"></button>
                                                @endforeach
                                            </div>
                                            {{-- Any colour: the browser's picker, or the hex typed in. --}}
                                            <div class="flex items-center gap-2">
                                                <input type="color" x-model="color" class="w-9 h-9 cursor-pointer rounded-field border border-base-300 bg-transparent p-0.5" aria-label="{{ __('Your own colour') }}">
                                                <input type="text" class="input input-sm w-28 font-mono" maxlength="7" spellcheck="false" aria-label="{{ __('Your own colour') }}"
                                                       :value="color" x-on:input.stop x-on:change.stop="if (/^#[0-9a-f]{6}$/i.test($event.target.value)) { color = $event.target.value.toLowerCase(); $nextTick(() => $dispatch('change')) } else { $event.target.value = color }">
                                                <span class="text-xs opacity-50">{{ __('— or your own') }}</span>
                                            </div>
                                        </div>
                                        @break

                                    @case ('checkbox')
                                        @break

                                    @case ('background')
                                        {{-- The theme's own (the colour above), a gradient of
                                             two colours, or a picture uploaded like the logo.
                                             One hidden value carries whichever it is. --}}
                                        @php
                                            $fieldBgValue = (string) $fieldValue;
                                            $fieldBgUrl = str_starts_with($fieldBgValue, 'image:') ? \Illuminate\Support\Facades\Storage::disk('public')->url(substr($fieldBgValue, 6)) : null;
                                        @endphp
                                        <div x-data="themeBackground({ key: @js($fieldKey), value: @js($fieldBgValue), url: @js($fieldBgUrl), upload: @js(route('admin.themes.image', $storefront)), tintFrom: @js((string) ($customizeField['tint_from'] ?? '')) })" class="flex flex-col gap-3">
                                            <input type="hidden" name="{{ $fieldName }}" :value="composed()" value="{{ $fieldBgValue }}">
                                            <div class="join self-start" role="group" aria-label="{{ $fieldLabel }}">
                                                @foreach (['none' => __('Colour only'), 'gradient' => __('Gradient'), 'image' => __('Picture')] as $fieldBgKind => $fieldBgKindLabel)
                                                    <button type="button" class="btn btn-xs join-item" :class="kind === @js($fieldBgKind) ? 'btn-neutral' : 'btn-ghost border-base-300'"
                                                            :aria-pressed="kind === @js($fieldBgKind)" x-on:click="setKind(@js($fieldBgKind))">{{ $fieldBgKindLabel }}</button>
                                                @endforeach
                                            </div>
                                            <div x-show="kind === 'gradient'" x-cloak class="flex flex-col gap-2">
                                                <div class="h-8 rounded-field border border-base-300" :style="`background-image: linear-gradient(${angle}deg, ${from}, ${to})`"></div>
                                                <div class="flex items-center gap-2">
                                                    <input type="color" :value="from" x-on:input.stop="from = $event.target.value" x-on:change.stop="commit()"
                                                           class="w-9 h-9 cursor-pointer rounded-field border border-base-300 bg-transparent p-0.5" aria-label="{{ __('From') }}">
                                                    <x-icon name="arrow-right" size="sm" class="opacity-40" />
                                                    <input type="color" :value="to" x-on:input.stop="to = $event.target.value" x-on:change.stop="commit()"
                                                           class="w-9 h-9 cursor-pointer rounded-field border border-base-300 bg-transparent p-0.5" aria-label="{{ __('To') }}">
                                                    <div class="join ms-auto" role="group" aria-label="{{ __('Direction') }}">
                                                        @foreach (['90' => 'arrow-right', '135' => 'arrow-down-right', '180' => 'arrow-down'] as $fieldBgAngle => $fieldBgIcon)
                                                            <button type="button" class="btn btn-xs btn-square join-item" :class="angle === @js($fieldBgAngle) ? 'btn-neutral' : 'btn-ghost border-base-300'"
                                                                    x-on:click="angle = @js($fieldBgAngle); commit()" aria-label="{{ $fieldBgAngle }}°"><x-icon :name="$fieldBgIcon" size="sm" /></button>
                                                        @endforeach
                                                    </div>
                                                </div>
                                            </div>
                                            <div x-show="kind === 'image'" x-cloak class="flex items-center gap-3">
                                                <div class="w-16 h-16 rounded-box border border-base-300 bg-base-200 bg-cover bg-center shrink-0 grid place-items-center"
                                                     :style="url ? `background-image: url('${url}')` : ''">
                                                    <x-icon name="image" class="opacity-30" x-show="! url" />
                                                </div>
                                                <div class="flex flex-col gap-1.5 min-w-0">
                                                    <label class="btn btn-sm" :class="busy && 'btn-disabled'">
                                                        <span class="loading loading-spinner loading-xs" x-show="busy" x-cloak></span>
                                                        <x-icon name="upload" size="sm" x-show="! busy" /> {{ __('Upload') }}
                                                        <input type="file" accept="image/png,image/jpeg,image/webp,image/gif" class="hidden" x-on:change.stop="send($event)">
                                                    </label>
                                                    <p class="text-xs text-error" x-show="failed" x-cloak>{{ __('This file cannot be used: a PNG, JPG, WebP or GIF up to 2 MB.') }}</p>
                                                </div>
                                            </div>
                                        </div>
                                        @break

                                    @case ('image')
                                        {{-- Uploaded on its own (themes.image) and carried as a
                                             PATH: the draft request never holds a file. Empty
                                             means the theme's own picture, or none. --}}
                                        @php $fieldImageUrl = $fieldValue ? \Illuminate\Support\Facades\Storage::disk('public')->url($fieldValue) : null; @endphp
                                        <div class="flex items-center gap-3" x-data="{ path: @js((string) $fieldValue), url: @js($fieldImageUrl), busy: false, failed: false,
                                                async upload(e) {
                                                    const file = e.target.files[0]; if (! file) return;
                                                    this.busy = true; this.failed = false;
                                                    const body = new FormData(); body.append('key', @js($fieldKey)); body.append('file', file);
                                                    const r = await fetch(@js(route('admin.themes.image', $storefront)), { method: 'POST', body, credentials: 'same-origin',
                                                        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' } });
                                                    this.busy = false; e.target.value = '';
                                                    if (! r.ok) { this.failed = true; return; }
                                                    const j = await r.json(); this.path = j.path; this.url = j.url;
                                                    this.$nextTick(() => this.$refs.hidden.dispatchEvent(new Event('change', { bubbles: true })));
                                                } }"
                                             x-on:theme-field-reset.window="if ($event.detail.key === @js($fieldKey)) { path = $event.detail.value; url = null }">
                                            <input type="hidden" name="{{ $fieldName }}" x-ref="hidden" :value="path" value="{{ $fieldValue }}">
                                            <div class="w-16 h-16 rounded-box border border-base-300 bg-base-200 flex items-center justify-center overflow-hidden shrink-0">
                                                <template x-if="url"><img :src="url" alt="" class="w-full h-full object-contain"></template>
                                                <template x-if="! url"><x-icon name="image" class="opacity-30" /></template>
                                            </div>
                                            <div class="flex flex-col gap-1.5 min-w-0">
                                                <label class="btn btn-sm" :class="busy && 'btn-disabled'">
                                                    <span class="loading loading-spinner loading-xs" x-show="busy" x-cloak></span>
                                                    <x-icon name="upload" size="sm" x-show="! busy" /> {{ __('Upload') }}
                                                    <input type="file" accept="image/png,image/jpeg,image/webp,image/gif" class="hidden" x-on:change.stop="upload($event)">
                                                </label>
                                                <button type="button" class="btn btn-ghost btn-sm" x-show="path" x-cloak
                                                        x-on:click="path = ''; url = null; $nextTick(() => $refs.hidden.dispatchEvent(new Event('change', { bubbles: true })))">{{ __('Use the theme\'s own') }}</button>
                                                <p class="text-xs text-error" x-show="failed" x-cloak>{{ __('This file cannot be used: a PNG, JPG, WebP or GIF up to 2 MB.') }}</p>
                                            </div>
                                        </div>
                                        @break

                                    @case ('number')
                                        {{-- A slider and the number itself — the number is the
                                             field (one source of truth); the slider only moves it. --}}
                                        <div class="flex items-center gap-3" x-data="{ n: @js((string) $fieldValue) }"
                                             x-on:theme-field-reset.window="if ($event.detail.key === @js($fieldKey)) n = $event.detail.value">
                                            <input type="range" class="range range-xs range-primary flex-1" aria-hidden="true" tabindex="-1"
                                                   min="{{ $customizeField['min'] ?? 0 }}" max="{{ $customizeField['max'] ?? 100 }}" step="{{ $customizeField['step'] ?? 1 }}"
                                                   :value="n" x-on:input.stop="n = $event.target.value; $refs.number.value = n; $refs.number.dispatchEvent(new Event('input', { bubbles: true }))"
                                                   x-on:change.stop="$refs.number.dispatchEvent(new Event('change', { bubbles: true }))">
                                            <label class="input input-sm w-28 shrink-0">
                                                <input type="number" name="{{ $fieldName }}" x-ref="number" x-model="n" value="{{ $fieldValue }}" aria-label="{{ $fieldLabel }}"
                                                       min="{{ $customizeField['min'] ?? '' }}" max="{{ $customizeField['max'] ?? '' }}" step="{{ $customizeField['step'] ?? 1 }}">
                                                @if (! empty($customizeField['unit']))<span class="opacity-50">{{ $customizeField['unit'] }}</span>@endif
                                            </label>
                                        </div>
                                        @break

                                    @case ('code')
                                        <textarea name="{{ $fieldName }}" rows="14" spellcheck="false"
                                                  class="textarea w-full font-mono text-xs leading-relaxed"
                                                  placeholder=".torgash-storefront { }">{{ $fieldValue }}</textarea>
                                        @break

                                    @case ('select')
                                        {{-- A list the shop's own rows fill (its menus) can be
                                             long; buttons are for a handful of fixed choices. --}}
                                        <select name="{{ $fieldName }}" class="select select-sm w-full">
                                            @foreach (app(\App\Core\Themes\ThemeSettings::class)->options($customizeField) as $fieldOption => $fieldOptionLabel)
                                                <option value="{{ $fieldOption }}" @selected((string) $fieldValue === (string) $fieldOption)>{{ $customizeLabel($fieldOptionLabel) }}</option>
                                            @endforeach
                                        </select>
                                        @break

                                    @default
                                        <div class="flex flex-wrap gap-1.5">
                                            @foreach (app(\App\Core\Themes\ThemeSettings::class)->options($customizeField) as $fieldOption => $fieldOptionLabel)
                                                <input type="radio" name="{{ $fieldName }}" value="{{ $fieldOption }}" class="btn btn-sm"
                                                       aria-label="{{ $customizeLabel($fieldOptionLabel) }}" @checked((string) $fieldValue === (string) $fieldOption)>
                                            @endforeach
                                        </div>
                                @endswitch

                                @if ($fieldHelp !== '')
                                    <p class="text-xs opacity-60 leading-relaxed">{{ $fieldHelp }}</p>
                                @endif
                            </div>
                        @endforeach
                    @endforeach
                </form>
            </div>

            {{-- The rarer acts, under the list. "Share" opens a WINDOW rather
                 than a drop-down: a drop-down opened upwards over this narrow
                 column and, while it kept the focus, lay over what was under it. --}}
            <div class="shrink-0 border-t border-base-300 px-3 py-2 flex items-center justify-between gap-2" x-show="view === 'home'">
                <x-modal name="theme-share" :title="__('Share the settings')" icon="share-2" size="sm"
                         :trigger="__('Share the settings')" trigger-icon="share-2" trigger-class="btn btn-ghost btn-sm gap-1">
                    {{-- Two directions, each saying what it does: the file is
                         the SAVED look, and taking one saves at once. --}}
                    <div class="flex flex-col gap-4">
                        <div class="flex flex-col gap-2">
                            <div class="font-semibold text-sm">{{ __('Save to a file') }}</div>
                            <p class="text-xs opacity-70 leading-relaxed">{{ __('Everything set here for this storefront — colours, fonts, the header, the footer, the cards — in one small file: a copy to keep, or to give the same look to another storefront or shop. Changes not saved yet are not in it.') }}</p>
                            <a href="{{ route('admin.themes.export', $storefront) }}" class="btn btn-outline gap-2"><x-icon name="download" /> {{ __('Download the settings') }}</a>
                        </div>
                        <div class="divider my-0"></div>
                        <form method="POST" action="{{ route('admin.themes.import', $storefront) }}" enctype="multipart/form-data" class="flex flex-col gap-2"
                              data-confirm="{{ __('Save the settings from the file on this storefront? Its visitors see them at once.') }}" x-on:submit="dirty = false">
                            @csrf
                            <div class="font-semibold text-sm">{{ __('Load from a file') }}</div>
                            <p class="text-xs opacity-70 leading-relaxed">{{ __('A file downloaded above, here or in another shop. Its settings are SAVED on this storefront at once; what the file does not mention stays as it is. A file of another theme brings what the two themes share.') }}</p>
                            <input type="file" name="file" accept="application/json,.json" class="file-input w-full" required>
                            <button class="btn gap-2"><x-icon name="upload" /> {{ __('Take them from a file') }}</button>
                        </form>
                    </div>
                </x-modal>
                <form method="POST" action="{{ route('admin.themes.reset', $storefront) }}"
                      x-on:submit="if (! confirm(@js(__('Forget this storefront\'s settings and use the theme\'s own?')))) { $event.preventDefault(); } else { dirty = false; }">
                    @csrf
                    <button class="btn btn-ghost btn-sm gap-1"><x-icon name="rotate-ccw" size="sm" /> {{ __('Default') }}</button>
                </form>
            </div>
        </aside>

        {{-- ===== The frame ===== --}}
        <section class="flex-1 min-w-0 relative bg-base-300/60 overflow-hidden" x-ref="stage"
                 :class="pane === 'preview' ? 'block' : 'hidden lg:block'">
            <div class="absolute left-1/2 origin-top bg-base-100 overflow-hidden transition-[width,height] duration-200"
                 :class="device === 'phone' ? 'rounded-[2.25rem] border-[10px] border-neutral shadow-2xl' : (device === 'tablet' ? 'rounded-2xl border-[8px] border-neutral shadow-xl' : '')"
                 :style="box()">
                <iframe src="{{ $previewUrl }}" x-ref="frame" class="w-full h-full block bg-base-100" title="{{ __('Storefront preview') }}"
                        x-on:load="loaded()"></iframe>
            </div>
        </section>
    </div>

    {{-- What just happened: saved, applied, failed. --}}
    <div class="toast toast-top toast-center z-50 pointer-events-none" x-show="toast" x-cloak x-transition.opacity>
        <div class="alert shadow-lg pointer-events-auto" :class="toastError ? 'alert-error' : 'alert-success'">
            <x-icon name="check" size="sm" x-show="! toastError" /> <span x-text="toast"></span>
        </div>
    </div>
</div>

<script nonce="{{ csp_nonce() }}">
    // Colour arithmetic — the same formulas as App\Core\Support\Colour, so
    // the "automatic" swatch here is the colour the page gets.
    const themeInk = { light: '#ffffff', dark: '#18181b' };
    const colourMath = {
        rgb(hex) { return [1, 3, 5].map(i => parseInt(hex.slice(i, i + 2), 16) / 255); },
        lin(v) { return v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; },
        luminance(hex) { const [r, g, b] = this.rgb(hex).map(v => this.lin(v)); return 0.2126 * r + 0.7152 * g + 0.0722 * b; },
        contrast(a, b) { const x = this.luminance(a), y = this.luminance(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); },
        ink(bg) { return this.contrast(bg, themeInk.light) >= this.contrast(bg, themeInk.dark) ? themeInk.light : themeInk.dark; },
        oklch(hex) {
            const [r, g, b] = this.rgb(hex).map(v => this.lin(v));
            const l = Math.cbrt(0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b);
            const m = Math.cbrt(0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b);
            const s = Math.cbrt(0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b);
            const L = 0.2104542553 * l + 0.7936177850 * m - 0.0040720468 * s;
            const A = 1.9779984951 * l - 2.4285922050 * m + 0.4505937099 * s;
            const B = 0.0259040371 * l + 0.7827717662 * m - 0.8086757660 * s;
            let H = Math.atan2(B, A) * 180 / Math.PI; if (H < 0) H += 360;
            return [L, Math.hypot(A, B), H];
        },
        fromOklch(L, C, H) {
            let rgb;
            for (let i = 0; i < 40; i++) {
                const A = C * Math.cos(H * Math.PI / 180), B = C * Math.sin(H * Math.PI / 180);
                const l = (L + 0.3963377774 * A + 0.2158037573 * B) ** 3;
                const m = (L - 0.1055613458 * A - 0.0638541728 * B) ** 3;
                const s = (L - 0.0894841775 * A - 1.2914855480 * B) ** 3;
                rgb = [4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s, -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s, -0.0041960863 * l - 0.7034186147 * m + 1.7076147010 * s];
                if (Math.min(...rgb) >= -0.0005 && Math.max(...rgb) <= 1.0005) break;
                C *= 0.9;
            }
            const g = v => v <= 0.0031308 ? 12.92 * v : 1.055 * Math.max(0, v) ** (1 / 2.4) - 0.055;
            return '#' + rgb.map(v => Math.round(Math.min(1, Math.max(0, g(v))) * 255).toString(16).padStart(2, '0')).join('');
        },
        readableOn(colour, bg, ratio = 4.5) {
            if (this.contrast(colour, bg) >= ratio) return colour;
            let [L, C, H] = this.oklch(colour);
            const up = this.luminance(bg) < 0.18;
            for (let i = 0; i < 100; i++) {
                L = Math.min(1, Math.max(0, L + (up ? 0.01 : -0.01)));
                const c = this.fromOklch(L, C, H);
                if (this.contrast(c, bg) >= ratio || L <= 0 || L >= 1) return c;
            }
            return up ? themeInk.light : themeInk.dark;
        },
        darkTwin(light, role, darkBg = '#1c2230') {
            const [L, C, H] = this.oklch(light);
            if (role === 'text') return this.readableOn(light, darkBg);
            if (role === 'surface') return this.fromOklch(Math.min(L, 0.27), C * 0.55, H);
            return light;
        },
    };

    // One colour with its two palettes. `dark` is '' for "automatic".
    function themeColour(f) {
        // Where a colour stands, per palette: a text on the panels, a panel
        // under the text — the theme's own, as the stylesheet draws them.
        const page = { light: { surface: '#ffffff', text: '#191b1e' }, dark: { surface: '#1c2230', text: '#e7e9ee' } };
        return {
            ...f,
            tab: 'light',
            init() {
                window.addEventListener('theme-field-reset', (e) => {
                    if (e.detail.key === f.key) this.light = e.detail.value;
                    if (e.detail.key === f.key + @js(\App\Core\Themes\ThemeSettings::DARK)) this.dark = e.detail.value;
                });
            },
            // The dark colour the page will get: the shop's own, the theme's
            // while nothing changed, else worked out from the light one.
            autoDark() {
                return this.light === this.lightDefault ? this.darkDefault : colourMath.darkTwin(this.light, this.role);
            },
            shown() { return this.tab === 'light' ? this.light : (this.dark || this.autoDark()); },
            pick(colour, commit = true) {
                if (this.tab === 'light') this.light = colour; else this.dark = colour;
                if (commit) this.$nextTick(() => this.$root.dispatchEvent(new Event('change', { bubbles: true })));
            },
            setAuto(on) { this.dark = on ? '' : this.autoDark(); this.$nextTick(() => this.$root.dispatchEvent(new Event('change', { bubbles: true }))); },
            against() { return this.contrast === 'fill' ? 'ink' : 'page'; },
            // The colour the contrast is measured against.
            other() {
                const c = this.shown();
                if (this.contrast === 'fill') {
                    if (this.inkField) {
                        const picked = this.$root.closest('form').querySelector(`[name="values[${this.inkField}]"]:checked`)?.value;
                        return picked === 'black' ? themeInk.dark : themeInk.light;
                    }
                    // Untouched, the theme's own text stands on it; changed,
                    // the page picks white or dark by contrast.
                    const untouched = this.tab === 'light' ? c === this.lightDefault : (this.dark === '' && this.light === this.lightDefault) || c === this.darkDefault;
                    if (untouched && this.onDefault[this.tab]) return this.onDefault[this.tab];
                    return colourMath.ink(c);
                }
                if (this.contrast === 'text') return page[this.tab].surface;
                if (this.contrast === 'surface') return page[this.tab].text;
                return null;
            },
            ratio() { const o = this.other(); return o ? colourMath.contrast(this.shown(), o) : null; },
            readable() {
                const c = this.shown(), o = this.other();
                // A fill moves away from its text; a text from its page.
                this.pick(colourMath.readableOn(c, o));
            },
        };
    }

    // A background: '' (the theme's), 'gradient:#from:#to:angle' or 'image:path'.
    function themeBackground(f) {
        return {
            kind: 'none', from: '#ffffff', to: '#e5e7eb', angle: '135', path: '', url: f.url, busy: false, failed: false,
            init() {
                this.parse(f.value);
                window.addEventListener('theme-field-reset', (e) => { if (e.detail.key === f.key) { this.parse(e.detail.value); this.url = null; } });
            },
            parse(value) {
                const g = /^gradient:(#[0-9a-f]{6}):(#[0-9a-f]{6}):(\d+)$/i.exec(value || '');
                if (g) { this.kind = 'gradient'; [, this.from, this.to, this.angle] = g; return; }
                if ((value || '').startsWith('image:')) { this.kind = 'image'; this.path = value.slice(6); return; }
                this.kind = 'none';
            },
            composed() {
                if (this.kind === 'gradient') return `gradient:${this.from}:${this.to}:${this.angle}`;
                if (this.kind === 'image' && this.path) return `image:${this.path}`;
                return '';
            },
            // A first gradient starts from the zone's own colour and moves to
            // a neighbouring shade of it — not white on a dark bar whose
            // words were made light.
            setKind(kind) {
                if (kind === 'gradient' && this.kind !== 'gradient' && f.tintFrom) {
                    const base = this.$root.closest('form').querySelector(`[name="values[${f.tintFrom}]"]`)?.value;
                    if (/^#[0-9a-f]{6}$/i.test(base || '')) {
                        const [L, C, H] = colourMath.oklch(base);
                        this.from = base;
                        this.to = colourMath.fromOklch(L > 0.6 ? L - 0.08 : L + 0.08, C * 1.2, (H + 30) % 360);
                    }
                }
                this.kind = kind;
                this.commit();
            },
            commit() { this.$nextTick(() => this.$root.dispatchEvent(new Event('change', { bubbles: true }))); },
            async send(e) {
                const file = e.target.files[0]; if (! file) return;
                this.busy = true; this.failed = false;
                const body = new FormData(); body.append('key', f.key); body.append('file', file);
                const r = await fetch(f.upload, { method: 'POST', body, credentials: 'same-origin',
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' } });
                this.busy = false; e.target.value = '';
                if (! r.ok) { this.failed = true; return; }
                const j = await r.json(); this.path = j.path; this.url = j.url; this.commit();
            },
        };
    }

    function themeCustomizer(o) {
        return {
            view: 'home',
            query: '',
            pane: 'settings',
            device: 'desktop',
            deviceBefore: null,
            page: o.startUrl,
            pages: o.pages,
            sections: o.sections,
            frameUrl: o.startUrl,
            dirty: false,
            saving: false,
            loading: true,
            current: {},
            saved: {},
            found: 0,
            searchHelp: false,
            stageW: 0,
            stageH: 0,
            scrollY: 0,
            toast: '',
            toastError: false,

            init() {
                this.current = this.snapshot();
                this.saved = { ...this.current };
                this.page = this.matchPage(o.startUrl);
                new ResizeObserver(([entry]) => {
                    this.stageW = entry.contentRect.width;
                    this.stageH = entry.contentRect.height;
                }).observe(this.$refs.stage);
                // Names first; the help texts only when no name has the words.
                this.$watch('query', (q) => {
                    const words = q.trim().toLowerCase().split(/\s+/).filter(Boolean);
                    const fields = [...this.$refs.form.querySelectorAll('[data-label]')];
                    this.searchHelp = ! fields.some(el => words.every(w => el.dataset.label.includes(w)));
                    this.found = fields.filter(el => this.shows(null, this.searchHelp ? el.dataset.search : el.dataset.label)).length;
                });
                if (o.flash) this.say(o.flash, o.flashError);
                // A section can be linked to: ?section=header.
                const asked = new URLSearchParams(location.search).get('section');
                if (asked && (this.sections[asked] || asked === 'presets')) this.open(asked);
            },

            // What the form would send now, field by field. A switch sends
            // its hidden 0 and then, when on, its 1 — the last one wins.
            snapshot() {
                const out = {};
                for (const [name, value] of new FormData(this.$refs.form)) {
                    const m = name.match(/^values\[(.+)\]$/);
                    if (m) out[m[1]] = String(value);
                }
                return out;
            },

            // A colour counts as changed when either of its palettes is.
            isChanged(key) {
                const twin = key + @js(\App\Core\Themes\ThemeSettings::DARK);
                return (this.current[key] ?? '') !== (o.defaults[key] ?? '')
                    || (twin in o.defaults && (this.current[twin] ?? '') !== o.defaults[twin]);
            },
            changedIn(section) { return Object.keys(o.defaults).filter(k => o.fieldSection[k] === section && this.isChanged(k)).length; },

            shows(key, text) {
                if (this.view === 'home') {
                    const q = this.query.trim().toLowerCase();
                    return q !== '' && q.split(/\s+/).every(w => text.includes(w));
                }
                return o.fieldSection[key] === this.view;
            },

            open(view) {
                this.view = view;
                this.$refs.scroller.scrollTop = 0;
                const wants = this.sections[view]?.device;
                if (wants && wants !== this.device) { this.deviceBefore = this.device; this.device = wants; }
            },
            home() {
                if (this.deviceBefore) { this.device = this.deviceBefore; this.deviceBefore = null; }
                this.view = 'home';
            },
            setDevice(d) { this.device = d; this.deviceBefore = null; },

            // The frame: the whole stage for a computer (drawn at least 1280
            // wide and scaled down to fit, so a narrow window still shows the
            // computer's layout), a tablet or a phone at their own width.
            box() {
                const pad = 24;
                let w, h, scale = 1, top = 0;
                if (this.device === 'desktop') {
                    w = Math.max(this.stageW, 1280);
                    scale = Math.min(1, this.stageW / w);
                    h = this.stageH / scale;
                } else {
                    // The viewport's own width plus the bezel on both sides.
                    w = this.device === 'tablet' ? 820 + 16 : 390 + 20;
                    const maxH = this.device === 'tablet' ? 1180 : 844;
                    top = pad;
                    scale = Math.min(1, (this.stageW - pad * 2) / w);
                    h = Math.min(maxH, (this.stageH - pad * 2) / scale);
                }
                return `top:${top}px;width:${w}px;height:${h}px;transform:translateX(-50%) scale(${scale})`;
            },

            matchPage(url) {
                const clean = (u) => (u || '').replace(/[?#].*$/, '').replace(/\/$/, '');
                return this.pages.find(p => clean(p.url) === clean(url))?.url ?? '';
            },
            go(url) {
                if (! url) return;
                this.loading = true;
                this.scrollY = 0;
                this.$refs.frame.src = url;
            },
            loaded() {
                this.loading = false;
                try {
                    const win = this.$refs.frame.contentWindow;
                    this.frameUrl = win.location.href;
                    this.page = this.matchPage(this.frameUrl);
                    if (this.scrollY > 0) win.scrollTo({ top: this.scrollY, behavior: 'instant' });
                } catch (e) {}
                this.scrollY = 0;
            },

            // The draft goes first, THEN the frame reloads the page it is on —
            // reloading before the answer would draw the old values.
            async changed() {
                this.current = this.snapshot();
                this.dirty = Object.keys(this.current).some(k => this.current[k] !== this.saved[k]);
                this.loading = true;
                await fetch(o.previewUrl, {
                    method: 'POST',
                    body: new FormData(this.$refs.form),
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                    credentials: 'same-origin',
                });
                try {
                    const win = this.$refs.frame.contentWindow;
                    this.scrollY = win.scrollY;
                    win.location.reload();
                } catch (e) {
                    this.$refs.frame.src = this.$refs.frame.src;
                }
            },

            // A ready-made combination: its colours, every other colour back
            // to the theme's, every dark twin automatic unless it names one.
            applyScheme(colours) {
                for (const key of o.colourKeys) {
                    this.setField(key, colours[key] ?? o.defaults[key] ?? '');
                }
                this.$nextTick(() => this.changed());
            },

            // A field's value put into the form: the pickers hear the event,
            // plain inputs and choices are set here.
            setField(key, value) {
                window.dispatchEvent(new CustomEvent('theme-field-reset', { detail: { key, value } }));
                this.$refs.form.querySelectorAll(`[name="values[${CSS.escape(key)}]"]`).forEach(el => {
                    if (el.type === 'radio') el.checked = el.value === value;
                    else if (el.type === 'checkbox') el.checked = value === '1';
                    else if (el.type !== 'hidden') el.value = value;
                });
            },

            // "Save as a style": the settings form's values ride along.
            carry(form) {
                form.querySelectorAll('[data-carried]').forEach(el => el.remove());
                for (const [name, value] of new FormData(this.$refs.form)) {
                    if (! name.startsWith('values[')) continue;
                    const input = Object.assign(document.createElement('input'), { type: 'hidden', name, value });
                    input.dataset.carried = '';
                    form.append(input);
                }
                this.dirty = false;
            },

            resetField(key) {
                this.setField(key, o.defaults[key] ?? '');
                const twin = key + @js(\App\Core\Themes\ThemeSettings::DARK);
                if (twin in o.defaults) window.dispatchEvent(new CustomEvent('theme-field-reset', { detail: { key: twin, value: '' } }));
                this.$nextTick(() => this.changed());
            },

            async save() {
                if (this.saving) return;
                this.saving = true;
                try {
                    const r = await fetch(o.saveUrl, {
                        method: 'POST',
                        body: new FormData(this.$refs.form),
                        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json' },
                        credentials: 'same-origin',
                    });
                    if (! r.ok) throw new Error(r.status);
                    this.saved = this.snapshot();
                    this.current = { ...this.saved };
                    this.dirty = false;
                    this.say((await r.json()).message || o.texts.saved);
                } catch (e) {
                    this.say(o.texts.failed, true);
                }
                this.saving = false;
            },

            // Back to what is saved: opening the configurator forgets the
            // draft, so a fresh load IS the saved state.
            discard() {
                this.dirty = false;
                window.location.reload();
            },

            say(text, error = false) {
                this.toast = text;
                this.toastError = error;
                clearTimeout(this.toastTimer);
                this.toastTimer = setTimeout(() => this.toast = '', error ? 6000 : 3000);
            },
        };
    }
</script>
</body>
</html>
