@php
    $pickerLocale = admin_content_locale();

    // The same rows twice: once as markup (so the form works with no
    // JavaScript at all, which is how every other form in this admin works) and
    // once as a small array the search reasons over. Labels are short; the
    // alternative is asking the DOM, and DOM state is not reactive, so a card
    // would not know to re-hide itself when the box above it is typed into.
    $pickerCards = $features
        ->filter(fn ($feature) => $feature->values->isNotEmpty())
        ->map(fn ($feature) => [
            'id' => $feature->id,
            'name' => (string) $feature->t('name', $pickerLocale),
            'values' => $feature->values->map(fn ($value) => [
                'id' => (string) $value->id,
                'label' => (string) $value->display($pickerLocale),
            ])->values()->all(),
            'ticked' => array_map('strval', (array) ($selectedValues[$feature->id] ?? [])),
        ])->values();
@endphp

{{--
    The characteristic picker: only what applies to the chosen category, and a
    search over it.

    Two bounds rather than one, because they answer different halves of "there
    are far too many of these". The CATEGORY bound is the server's — a page can
    only be built out of characteristics that apply where it is published, so
    everything else was never a choice. The SEARCH is the browser's, over what
    is already rendered: within one category the set is small enough to send,
    and a round trip per keystroke would be slower than filtering in place.

    Swapped whole by HTMX when the category changes, and the ticks go with it —
    which is right, because another category's values are not valid here.
--}}
<div x-data="{ q: '', onlyTicked: false, get needle() { return this.q.trim().toLowerCase() } }"
     class="flex flex-col gap-3">
    <div class="flex flex-wrap items-center gap-3">
        <label class="input input-bordered input-sm flex items-center gap-2 grow max-w-sm">
            <x-icon name="search" size="sm" class="opacity-40" />
            <input type="search" x-model="q" class="grow" placeholder="{{ __('Search characteristics and values') }}">
        </label>
        <label class="label cursor-pointer justify-start gap-2 py-0">
            <input type="checkbox" x-model="onlyTicked" class="checkbox checkbox-sm">
            <span class="label-text text-sm">{{ __('Only what is ticked') }}</span>
        </label>
        <span class="text-xs opacity-50" x-show="q || onlyTicked" x-cloak>
            {{ __('A hidden box keeps whatever is ticked in it.') }}
        </span>
    </div>

    @if ($pickerCards->isEmpty())
        <div class="text-sm opacity-60">
            {{ __('No characteristics apply to this category — assign one to it on the Characteristics screen, or let this page stand for a system filter or a town instead.') }}
        </div>
    @else
        <div class="grid md:grid-cols-2 gap-3">
            @foreach ($pickerCards as $card)
                <div class="border border-base-300 rounded-box p-3"
                     x-data="{
                         name: @js(mb_strtolower($card['name'])),
                         items: @js($card['values']),
                         ticked: @js($card['ticked']),
                         // The search state is passed IN rather than reached
                         // for: an expression resolves up the scope chain, but
                         // `this` inside a method is this card's own data, and
                         // reading the box above from here would be undefined.
                         shows(item, needle, onlyTicked) {
                             if (onlyTicked && ! this.ticked.includes(item.id)) { return false; }
                             if (needle === '') { return true; }
                             return this.name.includes(needle) || item.label.toLowerCase().includes(needle);
                         },
                     }"
                     x-show="items.some(item => shows(item, needle, onlyTicked))">
                    <div class="text-sm font-medium mb-1.5">{{ $card['name'] }}</div>
                    <div class="flex flex-col gap-1 max-h-48 overflow-y-auto">
                        @foreach ($card['values'] as $index => $value)
                            {{-- x-model keeps `ticked` honest without a second
                                 source of truth: the input is an ordinary named
                                 checkbox and submits exactly as it always did. --}}
                            <label class="label cursor-pointer justify-start gap-2 py-0.5"
                                   x-show="shows(items[{{ $index }}], needle, onlyTicked)">
                                <input type="checkbox" name="values[{{ $card['id'] }}][]" value="{{ $value['id'] }}"
                                       class="checkbox checkbox-sm" x-model="ticked"
                                       @checked(in_array($value['id'], $card['ticked'], true))>
                                <span class="label-text">{{ $value['label'] }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
