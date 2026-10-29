@props([
    'name',
    'options' => [],
    'selected' => [],
    'label' => null,
    'placeholder' => 'Search…',
    'empty' => 'Nothing selected.',
    'badge' => false,
    'ordered' => false,
    'manageUrl' => null,
    'source' => null,
])
{{--
    A relation picker: what is selected reads as chips, what is
    available is searched rather than listed. Scales to hundreds of categories
    or labels, where a wall of checkboxes stops being usable.

    Selected items are submitted as repeated hidden inputs under $name, in the
    order they appear — which is what makes "ordered" (related products) work.

    `source` is for a set too big to print into the page — products, of which
    a shop may have a hundred thousand: an address answering `?q=` with
    `[{id, label}]` (at most a screenful), asked as the admin types. `options`
    then needs only the SELECTED ones, so their chips have words.
--}}
@once
    @push('scripts')
        <script nonce="{{ csp_nonce() }}">
            document.addEventListener('alpine:init', () => {
                Alpine.data('adminPicker', (config) => ({
                    options: config.options,
                    selected: config.selected,
                    source: config.source,
                    search: '',
                    open: false,
                    loading: false,
                    asked: 0,

                    init() {
                        if (this.source) {
                            this.options = [];
                            this.$watch('search', () => this.ask());
                        }
                    },

                    // Debounced, and only the latest answer counts: a slow reply
                    // to "sam" must not replace the one to "samsung".
                    ask() {
                        clearTimeout(this.timer);
                        const q = this.search.trim();
                        if (q === '') { this.options = []; this.loading = false; return; }
                        this.loading = true;
                        this.timer = setTimeout(async () => {
                            const ticket = ++this.asked;
                            const url = this.source + (this.source.includes('?') ? '&' : '?') + 'q=' + encodeURIComponent(q);
                            try {
                                const response = await fetch(url, { headers: { Accept: 'application/json' } });
                                const found = response.ok ? await response.json() : [];
                                if (ticket === this.asked) { this.options = found; }
                            } finally {
                                if (ticket === this.asked) { this.loading = false; }
                            }
                        }, 250);
                    },

                    get filtered() {
                        const q = this.search.trim().toLowerCase();
                        const taken = this.selected.map((o) => o.id);
                        return this.options
                            .filter((o) => !taken.includes(o.id))
                            .filter((o) => this.source || !q || o.label.toLowerCase().includes(q))
                            .slice(0, 50);
                    },

                    add(option) {
                        this.selected.push(option);
                        this.search = '';
                    },

                    remove(index) {
                        this.selected.splice(index, 1);
                    },

                    clear() {
                        this.selected = [];
                    },
                }));
            });
        </script>
    @endpush
@endonce
@php
    $all = collect($options)->map(fn ($o) => [
        'id' => (int) $o['id'],
        'label' => (string) $o['label'],
        'color' => $o['color'] ?? null,
    ])->values();

    $selectedIds = array_map('intval', (array) $selected);
    $chosen = $ordered
        ? collect($selectedIds)->map(fn ($id) => $all->firstWhere('id', $id))->filter()->values()
        : $all->whereIn('id', $selectedIds)->values();
@endphp

<div x-data="adminPicker({
        options: {{ \Illuminate\Support\Js::from($all) }},
        selected: {{ \Illuminate\Support\Js::from($chosen) }},
        source: {{ \Illuminate\Support\Js::from($source) }},
     })" class="flex flex-col gap-1.5">

    @if ($label)
        {{-- Drawn like every other field's legend, so a picker sits among
             ordinary fields without looking like another kind of thing. --}}
        <div class="fieldset-legend p-0 flex items-center gap-2 w-full">
            {{ $label }}
            <span class="opacity-60" x-show="selected.length" x-cloak>(<span x-text="selected.length"></span>)</span>
            <button type="button" class="link link-hover text-xs opacity-70" x-show="selected.length" x-cloak
                    @click="clear()">{{ __('clear') }}</button>
            @if ($manageUrl)
                <a href="{{ $manageUrl }}" class="link link-hover text-xs opacity-70 ml-auto">{{ __('manage') }}</a>
            @endif
        </div>
    @endif

    <div class="flex flex-wrap gap-2">
        <template x-for="(option, i) in selected" :key="option.id">
            <span class="badge badge-sm gap-1 pr-1"
                  :class="option.color ? 'badge-' + option.color : 'badge-ghost'">
                <span x-text="option.label"></span>
                <button type="button" @click="remove(i)" class="btn btn-circle btn-ghost btn-xs min-h-0 h-5 w-5" aria-label="{{ __('Remove') }}">
                    <x-icon name="x" size="sm" />
                </button>
                <input type="hidden" name="{{ $name }}" :value="option.id">
            </span>
        </template>
        <span x-show="selected.length === 0" class="text-sm opacity-50">{{ $empty }}</span>
    </div>

    {{-- "Outside" is outside the field AND its list: on the list alone, the
         click that opened it (on the field) closed it again whenever the list
         was drawn before the click landed. --}}
    <div class="relative" @click.outside="open = false; search = ''">
        {{-- Enter takes the first match rather than submitting the form the
             picker stands in, which saved (or applied) a half-filled form. --}}
        <input type="text" x-model="search" @focus="open = true" @click="open = true"
               @keydown.enter.prevent="filtered.length && add(filtered[0])"
               placeholder="{{ $placeholder }}" aria-label="{{ $label ?: $placeholder }}" class="input input-bordered w-full" autocomplete="off">
        <ul x-show="open" x-cloak
            class="absolute z-30 mt-1 w-full bg-base-100 border border-base-300 rounded-box shadow-md max-h-64 overflow-y-auto">
            <template x-for="option in filtered" :key="option.id">
                <li>
                    <button type="button" @click="add(option)"
                            class="w-full text-left px-3 py-1.5 hover:bg-base-200 text-sm" x-text="option.label"></button>
                </li>
            </template>
            @if ($source)
                <li x-show="loading" class="px-3 py-2 text-sm opacity-50">{{ __('Searching…') }}</li>
                <li x-show="!loading && search.trim() === ''" class="px-3 py-2 text-sm opacity-50">{{ __('Start typing a SKU or a name') }}</li>
                <li x-show="!loading && search.trim() !== '' && filtered.length === 0" class="px-3 py-2 text-sm opacity-50">{{ __('No matches') }}</li>
            @else
                <li x-show="filtered.length === 0" class="px-3 py-2 text-sm opacity-50">{{ __('No matches') }}</li>
            @endif
        </ul>
    </div>
</div>
