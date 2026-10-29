@extends('admin.layout')

@section('title', __('Blocks'))

@php
    use Torgash\Blocks\BlockTypes;

    $locale = app()->getLocale();
    $settings = old('settings', $block->settings ?? []);
    $txt = fn ($field, $key) => isset($field[$key]) ? (label_text($field[$key], $locale) ?: null) : null;
    $hasTranslatable = collect($schema)->contains(fn ($field) => BlockTypes::isTranslatable($field));

    // The JSON view is seeded from the SAME values the form is bound to, which
    // is the whole point: they are two renderings of one object, not two
    // formats that need converting into each other.
    $settingsJson = old('settings_json', json_encode(
        $settings ?: (object) [],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ));
@endphp

@section('content')
    <form method="POST"
          action="{{ $block->exists ? route('admin.blocks.update', $block) : route('admin.blocks.store') }}"
          x-data="{ mode: '{{ old('input_mode', 'form') }}' }">
        @csrf
        @if ($block->exists) @method('PUT') @endif
        <input type="hidden" name="zone" value="{{ $block->zone }}">
        <input type="hidden" name="type" value="{{ $block->type }}">
        <input type="hidden" name="input_mode" :value="mode">

        <x-admin.form-bar class="mb-4">
            <a href="{{ route('admin.blocks.zone', $block->zone) }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to the zone') }}" aria-label="{{ __('Back to the zone') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ label_text($zone['label'] ?? null, $locale, $block->zone) }}</div>
                <div class="font-semibold truncate leading-tight flex items-center gap-2">
                    <x-icon :name="$type['icon'] ?? 'square'" size="sm" class="opacity-60" />
                    {{ $block->exists ? $block->displayTitle() : label_text($type['label'] ?? null, $locale, $block->type) }}
                </div>
            </div>
            {{-- Placeholders are global, so the reference belongs next to the
                 editor rather than buried in one module's help text — and it
                 is the same component the SEO templates carry, because the
                 registry and its syntax are the same on both screens. --}}
            @if ($offerPlaceholders)
                <x-admin.placeholders name="block" icon-only
                    fields='textarea[name^="settings"], input[name^="settings"], textarea[name="settings_json"]' />
            @endif
            <div class="dropdown dropdown-end">
                <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}" aria-label="{{ __('More actions') }}">
                    <x-icon name="settings" size="lg" />
                </div>
                <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-60 mt-2 p-2 z-50 border border-base-300">
                    <li><button type="submit" name="after_save" value="close"><x-icon name="check" /> {{ __('Save and close') }}</button></li>
                    @if ($block->exists)
                        <li class="menu-title p-0 my-1"><span class="border-t border-base-300 block"></span></li>
                        <li><button type="submit" form="block-delete" class="text-error"><x-icon name="trash-2" /> {{ __('Delete') }}</button></li>
                    @endif
                </ul>
            </div>
            <x-slot:actions>
                <a href="{{ route('admin.blocks.zone', $block->zone) }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
                <button class="btn btn-primary btn-sm">{{ $block->exists ? __('Save') : __('Create') }}</button>
            </x-slot:actions>
        </x-admin.form-bar>

        @if (session('status'))
            <div class="alert alert-success text-sm mb-4">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error text-sm mb-4">{{ $errors->first() }}</div>
        @endif

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem] items-start">
        <div class="flex flex-col gap-4 min-w-0">
        <section class="card bg-base-100 border border-base-300">
            <div class="card-body gap-4">
                <div class="flex items-center justify-between gap-3 flex-wrap">
                    <h3 class="font-semibold">{{ label_text($type['label'] ?? null, $locale, $block->type) }}</h3>
                <div role="tablist" class="tabs tabs-border tabs-sm w-fit">
                    <a role="tab" class="tab" :class="mode === 'form' && 'tab-active'"
                       x-on:click.prevent="mode = 'form'" href="#">{{ __('Settings') }}</a>
                    <a role="tab" class="tab" :class="mode === 'json' && 'tab-active'"
                       x-on:click.prevent="mode = 'json'" href="#">JSON</a>
                </div>
                </div>
                @if (! empty($type['description']))
                    <p class="text-sm opacity-60 -mt-2">{{ __($type['description']) }}</p>
                @endif

                <div x-show="mode === 'form'" class="flex flex-col gap-4">
                    @forelse ($schema as $field)
                        @php
                            $key = $field['key'];
                            $value = $settings[$key] ?? ($field['default'] ?? null);
                            $locked = ! empty($field['permission']) && ! auth()->user()?->can($field['permission']);
                            $translatable = BlockTypes::isTranslatable($field);
                        @endphp

                        @if ($translatable && ! $locked)
                            {{-- The same component every other translatable field in
                                 the admin uses, so blocks behave the way products and
                                 categories already do: one language at a time (the
                                 content-language switch in the header), and a language
                                 left blank shows the base text as an italic placeholder
                                 because blank MEANS "inherits", not "empty". --}}
                            <x-admin.translatable-field
                                :name="'settings['.$key.']'"
                                :label="$txt($field, 'label') ?? $key"
                                :value="BlockTypes::map($value)"
                                :textarea="in_array($field['type'] ?? 'text', ['textarea', 'html'], true)"
                                :rows="($field['type'] ?? 'text') === 'html' ? 10 : 5"
                                :class="($field['type'] ?? 'text') === 'html' ? 'font-mono text-xs' : ''" />
                            @if ($txt($field, 'help'))
                                <p class="label text-xs opacity-60 whitespace-normal -mt-2">{{ $txt($field, 'help') }}</p>
                            @endif
                            @continue
                        @endif

                        <fieldset class="fieldset p-0">
                            <legend class="fieldset-legend">{{ $txt($field, 'label') ?? $key }}</legend>

                            @if ($locked)
                                {{-- Kept, not blanked: saving without this permission
                                     preserves whatever is already stored. --}}
                                <div class="alert alert-warning text-sm">
                                    <x-icon name="lock" size="sm" />
                                    {{ __('You do not have permission to edit this field. Saving will keep its current value.') }}
                                </div>
                            @elseif (($field['type'] ?? 'text') === 'checkbox')
                                <label class="label cursor-pointer justify-start gap-2 p-0">
                                    <input type="checkbox" name="settings[{{ $key }}]" value="1"
                                           class="checkbox checkbox-sm" @checked($value)>
                                    <span class="label-text">{{ $txt($field, 'help') ?? __('Yes') }}</span>
                                </label>
                            @elseif (($field['type'] ?? 'text') === 'select')
                                @php
                                    // A label is a declared English word (translated
                                    // through lang files), an older {en, ru} map, or
                                    // a string built from the shop's own rows — which
                                    // no lang file has a key for, so it passes as is.
                                    $optText = fn ($text, $fallback) => label_text($text, $locale, (string) $fallback);
                                    $optGroups = collect(BlockTypes::options($field))
                                        ->map(fn ($option) => is_array($option) ? $option : ['value' => $option, 'label' => $option])
                                        ->groupBy(fn ($option) => $optText($option['group'] ?? '', ''));
                                @endphp
                                <select name="settings[{{ $key }}]" class="select w-full max-w-sm">
                                    @foreach ($optGroups as $optGroup => $groupOptions)
                                        @if ($optGroup !== '') <optgroup label="{{ $optGroup }}"> @endif
                                        @foreach ($groupOptions as $option)
                                            @php $optValue = (string) ($option['value'] ?? ''); @endphp
                                            <option value="{{ $optValue }}" @selected((string) $value === $optValue)>{{ $optText($option['label'] ?? null, $optValue) }}</option>
                                        @endforeach
                                        @if ($optGroup !== '') </optgroup> @endif
                                    @endforeach
                                </select>
                            @elseif (($field['type'] ?? 'text') === 'picks')
                                {{-- The admin's own searchable picker, in the order
                                     picked; nothing picked posts nothing, which
                                     saves as an empty list. --}}
                                <x-admin.picker :name="'settings['.$key.'][]'" ordered
                                    :options="collect(BlockTypes::options($field))->map(fn ($option) => ['id' => $option['value'] ?? $option, 'label' => label_text($option['label'] ?? null, $locale, (string) ($option['value'] ?? $option))])->all()"
                                    :selected="(array) $value"
                                    :placeholder="__('Search…')"
                                    :empty="$txt($field, 'empty') ?? __('Nothing selected.')" />
                            @elseif (($field['type'] ?? 'text') === 'number')
                                {{-- `suffix` names the unit beside the number (px). --}}
                                <label @class(['input w-40' => ! empty($field['suffix'])])>
                                    <input type="number" name="settings[{{ $key }}]" value="{{ $value }}"
                                           @isset($field['min']) min="{{ $field['min'] }}" @endisset
                                           @isset($field['max']) max="{{ $field['max'] }}" @endisset
                                           @class(['grow' => ! empty($field['suffix']), 'input w-40' => empty($field['suffix'])])>
                                    @if (! empty($field['suffix']))
                                        <span class="text-base-content/60">{{ $field['suffix'] }}</span>
                                    @endif
                                </label>
                            @elseif (in_array($field['type'] ?? 'text', ['textarea', 'html'], true))
                                <textarea name="settings[{{ $key }}]" rows="{{ ($field['type'] === 'html') ? 10 : 5 }}"
                                          class="textarea w-full @if ($field['type'] === 'html') font-mono text-xs @endif">{{ $value }}</textarea>
                            @else
                                <input name="settings[{{ $key }}]" value="{{ $value }}"
                                       @isset($field['placeholder']) placeholder="{{ $field['placeholder'] }}" @endisset
                                       @class(['input', 'w-64' => ! empty($field['short']), 'w-full' => empty($field['short'])])>
                            @endif

                            @if (! $locked && $txt($field, 'help') && ($field['type'] ?? 'text') !== 'checkbox')
                                <p class="label text-xs opacity-60 whitespace-normal">{{ $txt($field, 'help') }}</p>
                            @endif
                        </fieldset>
                    @empty
                        <p class="text-sm opacity-60">{{ __('This block type has no settings.') }}</p>
                    @endforelse
                </div>

                <div x-show="mode === 'json'" x-cloak class="flex flex-col gap-2">
                    {{-- The scope changes between the two tabs and nothing else
                         says so: the form edits ONE language, this edits the
                         stored object — every language at once. Without this
                         banner, clearing the box here reads as "clear the
                         language I am looking at" and silently takes the rest
                         with it. --}}
                    @if ($hasTranslatable)
                        <div class="alert alert-warning text-sm">
                            <x-icon name="languages" size="sm" />
                            <span>{{ __('This tab shows the whole stored object — all languages at once. The content-language switch does not apply here; use the Settings tab to edit one language.') }}</span>
                        </div>
                    @endif
                    <textarea name="settings_json" rows="14" class="textarea w-full font-mono text-xs"
                              spellcheck="false">{{ $settingsJson }}</textarea>
                    <p class="text-xs opacity-60">
                        {{ __('Validated against the same schema as the form. Keys this block type does not declare are dropped on save.') }}
                    </p>
                    <details class="text-xs opacity-60">
                        <summary class="cursor-pointer">{{ __('Fields this type accepts') }}</summary>
                        <ul class="mt-2 ml-4 list-disc font-mono">
                            @foreach ($schema as $field)
                                <li>
                                    {{ $field['key'] }} <span class="opacity-60">— {{ $field['type'] ?? 'text' }}</span>
                                    @if (($field['type'] ?? 'text') === 'picks')
                                        <span class="opacity-60">— {{ __('a list of ids, in order') }}</span>
                                    @elseif (isset($field['options']))
                                        <span class="opacity-60">({{ implode(' | ', BlockTypes::optionValues($field)) }})</span>
                                    @endif
                                    @if (BlockTypes::isTranslatable($field))
                                        {{-- Both shapes are valid here, which is why the
                                             JSON tab says so rather than showing one. --}}
                                        <span class="opacity-60">— {{ __('text, or one value per language') }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </details>
                </div>
            </div>
        </section>

        {{-- When this block is shown, beyond "always". The kinds come from
             core's registry, so a module that adds one appears here without
             this file changing — which is the whole reason the registry exists. --}}
        <section class="card bg-base-100 border border-base-300">
            <div class="card-body gap-4">
                <x-admin.display-rules
                    :rules="$block->rules ?? []"
                    :match="$block->rules_match ?? 'all'"
                    :hint="__('No conditions means the block is shown wherever its zone is. A condition whose module is switched off hides the block rather than being ignored.')" />
            </div>
        </section>
        </div>

        <div class="flex flex-col gap-4">
            <section class="card bg-base-100 border border-base-300">
                <div class="card-body gap-3">
                    <h3 class="font-semibold">{{ __('Status') }}</h3>
                    <label class="label cursor-pointer justify-start gap-3">
                        <input type="checkbox" name="enabled" value="1" class="toggle toggle-sm toggle-success"
                               @checked(old('enabled', $block->enabled ?? true))>
                        <span class="label-text">{{ __('Enabled') }}</span>
                    </label>
                    <fieldset class="fieldset p-0">
                        <legend class="fieldset-legend">{{ __('Internal title') }}</legend>
                        <input name="title" value="{{ old('title', $block->title) }}" class="input input-bordered w-full"
                               placeholder="{{ __('Admin reference only — not shown to visitors') }}">
                    </fieldset>
                    <fieldset class="fieldset p-0">
                        <legend class="fieldset-legend">{{ __('CSS class') }}</legend>
                        <input name="css_class" autocapitalize="off" autocorrect="off" spellcheck="false" value="{{ old('css_class', $block->css_class) }}" maxlength="{{ \Torgash\Blocks\CssClasses::MAX }}"
                               class="input input-bordered w-full font-mono @error('css_class') input-error @enderror" placeholder="home-hits">
                        @error('css_class')<p class="label text-xs text-error whitespace-normal">{{ $message }}</p>@enderror
                        <p class="label text-xs opacity-60 whitespace-normal">{{ __('For the shop\'s own stylesheet: added to the block\'s outermost element. Several are separated by spaces.') }}</p>
                    </fieldset>
                </div>
            </section>

            {{-- Where it stands among its neighbours. Arranged on the zone
                 screen (join, take out, move); a column's width is the one
                 thing set here too, because it is this block's own. --}}
            <section class="card bg-base-100 border border-base-300">
                <div class="card-body gap-2 text-sm">
                    <h3 class="font-semibold">{{ __('Where it stands') }}</h3>
                    <div class="opacity-70">{{ label_text($zone['label'] ?? null, $locale, $block->zone) }}</div>
                    @if ($group && $group['kind'] === 'row')
                        <div>{{ trans_choice('In a row of :count column.|In a row of :count columns.', count($group['blocks']), ['count' => count($group['blocks'])]) }}</div>
                        <fieldset class="fieldset p-0">
                            <legend class="fieldset-legend">{{ __('Column width') }}</legend>
                            <select name="width" class="select select-bordered w-full">
                                <option value="">{{ __('An equal share') }}</option>
                                @foreach (array_keys(\Torgash\Blocks\ZoneRenderer::WIDTHS) as $widthOption)
                                    <option value="{{ $widthOption }}" @selected(old('width', $block->width) === $widthOption)>{{ $widthOption }}</option>
                                @endforeach
                            </select>
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('Of the row\'s width, from a tablet up. On a phone columns stand one under another.') }}</p>
                        </fieldset>
                    @elseif ($group && $group['kind'] === 'tabs')
                        <div>{{ trans_choice('One of :count tabs.|One of :count tabs.', count($group['blocks']), ['count' => count($group['blocks'])]) }}</div>
                    @else
                        <div class="opacity-70">{{ __('On its own row.') }}</div>
                    @endif
                    <a href="{{ route('admin.blocks.zone', $block->zone) }}" class="btn btn-ghost btn-sm gap-1.5 self-start -ml-2">
                        <x-icon name="layout-dashboard" size="sm" /> {{ __('Arrange the zone') }}
                    </a>
                </div>
            </section>

        </div>
        </div>
    </form>

    @if ($block->exists)
        <form id="block-delete" method="POST" action="{{ route('admin.blocks.destroy', $block) }}"
              data-confirm="{{ __('Delete this block?') }}">
            @csrf @method('DELETE')
        </form>
    @endif
@endsection
