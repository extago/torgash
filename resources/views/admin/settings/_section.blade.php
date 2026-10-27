{{--
    One module's settings: its form, split into a card per `group` heading.
    Included by the settings screen for every module and for one alone.
    `$settingsSection` is named distinctively on purpose — an @include inherits
    every variable of the caller (docs/LESSONS.md).
--}}
@php
    // A `group` field is a heading, and a heading with nothing but more
    // headings under it reads as one long list. Split the section there
    // instead: each group becomes its own card, so "Units of measure"
    // and "Exchange rates" stop looking like one setting each other's.
    // Fields before the first group keep the module's own card.
    $blocks = [];
    $block = ['label' => null, 'fields' => []];

    foreach ($settingsSection['fields'] as $field) {
        if (($field['type'] ?? 'text') !== 'group') {
            $block['fields'][] = $field;

            continue;
        }

        if ($block['fields'] !== []) {
            $blocks[] = $block;
        }

        $block = [
            'label' => label_text($field['label'] ?? null, null, $field['key']),
            'fields' => [],
        ];
    }

    if ($block['fields'] !== []) {
        $blocks[] = $block;
    }
@endphp

{{-- One module alone ($only) has its name and Save in the sticky bar above,
     so here the cards are titled by their groups and carry no button. --}}
<form method="POST" action="{{ route('admin.settings.update', $settingsSection['module']->id) }}" class="flex flex-col gap-4"
      @isset($only) id="settings-form" @endisset>
    @csrf

    @foreach ($blocks as $blockIndex => $block)
    {{-- data-settings-card: the screen's filter hides a card none of whose settings match. --}}
    <div class="card bg-base-100 border border-base-300 scroll-mt-32" data-settings-card id="settings-card-{{ $blockIndex }}">
    <div class="card-body gap-4">
        {{-- The module names itself once; every card after that is
             titled by its own group. --}}
        @if (isset($only))
            <h2 class="card-title text-base">{{ $block['label'] ?? __('General') }}</h2>
        @elseif ($blockIndex === 0)
            <div class="flex items-baseline gap-2">
                {{-- A module's name is a declared English word, translated by its own lang file. --}}
                <h2 class="card-title text-base">{{ label_text($settingsSection['module']->name) }}</h2>
                <span class="text-xs font-normal opacity-50">{{ $settingsSection['module']->id }}</span>
                @unless (isset($only))
                    <a href="{{ route('admin.settings.edit', $settingsSection['module']->id) }}" class="link link-hover text-xs ml-auto">{{ __('Open') }}</a>
                @endunless
            </div>
            @if ($block['label'])
                <div class="text-xs font-semibold uppercase tracking-wide opacity-50 border-b border-base-300 pb-1">{{ $block['label'] }}</div>
            @endif
        @else
            <h2 class="card-title text-base">{{ $block['label'] }}</h2>
        @endif

        @foreach ($block['fields'] as $field)
            @php
                $type = $field['type'] ?? 'text';
                $label = label_text($field['label'] ?? null, null, $field['key']);
                $help = label_text($field['help'] ?? null) ?: null;
            @endphp

            {{-- The address the admin search links to (`#setting-<key>`), lit
                 up while it is the target so the eye lands on it after the
                 jump. scroll-margin keeps it clear of the sticky header. --}}
            <div @isset($field['key']) id="setting-{{ $field['key'] }}" @endisset
                 class="torgash-setting scroll-mt-32">
            @if ($type === 'action' && ! empty($field['permission']) && ! auth()->user()?->can($field['permission']))
                {{-- An action behind a right of its own (`permission`): who
                     may change the settings may not run it, and a button that
                     answers 403 is worse than none. --}}
            @elseif ($type === 'action')
                {{-- formaction redirects this one button to the module's own
                     endpoint; a nested <form> inside the settings form would
                     be dropped by the parser and take its inputs with it. --}}
                <div>
                    <button type="submit" formaction="{{ url($field['url']) }}" class="btn btn-outline btn-sm">
                        {{ $label }}
                    </button>
                    @if ($help)<div class="text-xs opacity-50 mt-1">{{ $help }}</div>@endif
                </div>
            @elseif ($type === 'link')
                {{-- A module's own screen, reached from its settings card
                     instead of a menu item of its own: a carrier's data
                     page is not a section of the shop. --}}
                <div>
                    <a href="{{ url($field['url']) }}" class="btn btn-outline btn-sm">
                        {{ $label }} <x-icon name="arrow-right" size="sm" />
                    </a>
                    @if ($help)<div class="text-xs opacity-50 mt-1">{{ $help }}</div>@endif
                </div>
            @elseif ($type === 'checkbox')
                <div>
                    <label class="label cursor-pointer justify-start gap-2 p-0">
                        <input type="hidden" name="{{ $field['key'] }}" value="0">
                        <input type="checkbox" name="{{ $field['key'] }}" value="1" class="checkbox checkbox-sm checkbox-primary" @checked($field['value'])>
                        <span class="label-text">{{ $label }}</span>
                    </label>
                    @if ($help)<div class="text-xs opacity-50 mt-1">{{ $help }}</div>@endif
                </div>
            @elseif ($type === 'select')
                <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ $label }}</legend>
                    <select name="{{ $field['key'] }}" class="select select-bordered w-full max-w-sm">
                        @foreach ($field['options'] ?? [] as $optValue => $optLabel)
                            <option value="{{ $optValue }}" @selected((string) $field['value'] === (string) $optValue)>
                                {{ label_text($optLabel, null, (string) $optValue) }}
                            </option>
                        @endforeach
                    </select>
                    @if ($help)<div class="text-xs opacity-50">{{ $help }}</div>@endif
                </fieldset>
            @elseif ($type === 'checkboxes')
                {{-- A row of ticks rather than a <select multiple>: with
                     half a dozen options the select hides both what is
                     chosen and how to unchoose it. --}}
                <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ $label }}</legend>
                    @php $chosen = array_map('strval', (array) $field['value']); @endphp
                    <div class="flex flex-wrap gap-x-6 gap-y-1">
                        @foreach ($field['options'] ?? [] as $optValue => $optLabel)
                            <label class="label cursor-pointer justify-start gap-2 p-0">
                                <input type="checkbox" name="{{ $field['key'] }}[]" value="{{ $optValue }}"
                                       class="checkbox checkbox-sm checkbox-primary"
                                       @checked(in_array((string) $optValue, $chosen, true))>
                                {{-- A plain string option is an English source string the
                                     module already translates elsewhere; a map is a
                                     per-locale label written into the manifest. --}}
                                <span class="label-text">{{ label_text($optLabel, null, (string) $optValue) }}</span>
                            </label>
                        @endforeach
                    </div>
                    @if ($help)<div class="text-xs opacity-50">{{ $help }}</div>@endif
                </fieldset>
            @elseif ($type === 'textarea')
                {{-- Monospace on purpose: this type is for markup/snippets
                     an admin edits by hand, not prose. --}}
                <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ $label }}</legend>
                    <textarea name="{{ $field['key'] }}" rows="{{ $field['rows'] ?? 8 }}"
                              class="textarea textarea-bordered w-full font-mono text-xs">{{ $field['value'] }}</textarea>
                    @if ($help)<div class="text-xs opacity-50">{{ $help }}</div>@endif
                </fieldset>
            @else
                <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ $label }}</legend>
                    <input name="{{ $field['key'] }}" type="{{ $type === 'number' ? 'number' : 'text' }}"
                           @if ($type === 'number') step="any" @endif
                           value="{{ $field['value'] }}" class="input input-bordered w-full max-w-sm">
                    @if ($help)<div class="text-xs opacity-50">{{ $help }}</div>@endif
                </fieldset>
            @endif
            {{-- What was changed here, and from what: "what did we change
                 on this page" answered without reading every field. --}}
            @if (isset($field['key'], $changed[$field['key']]))
                @php
                    $fieldDefault = $field['default'] ?? null;
                    $defaultText = match (true) {
                        $type === 'checkbox' => $fieldDefault ? __('on') : __('off'),
                        in_array($type, ['select', 'checkboxes'], true) => collect((array) $fieldDefault)
                            ->map(fn ($v) => label_text($field['options'][$v] ?? null, null, (string) $v))->implode(', '),
                        default => (string) $fieldDefault,
                    };
                @endphp
                <div class="text-xs text-primary mt-1 flex items-center gap-1">
                    <x-icon name="pencil" size="sm" />
                    {{ __('Changed') }} · {{ __('by default') }}: {{ $defaultText === '' ? __('empty') : $defaultText }}
                </div>
            @endif
            </div>
        @endforeach

        {{-- One per card, and each saves the whole section: the cards
             are one form, and a Save the reader has to scroll past two
             other blocks to find reads as "these were not saved". --}}
        @unless (isset($only))
            <div class="card-actions">
                <button class="btn btn-primary btn-sm">{{ __('Save') }}</button>
            </div>
        @endunless
    </div>
    </div>
    @endforeach
</form>
