{{--
    A product's categories, right under its name — where the editor reads
    "what is this and where does it live" in one glance.

    Each chosen category is a chip that reads as a path: the parents quiet,
    the category itself in bold. "Add a category" opens a panel with the TREE
    as the shop built it (indented, in its own order) and a search over full
    paths; a row toggles, so the panel stays open to tick several. A category
    that is switched off is still assignable (a technical grouping for
    promotions and rules) and says so.

    `$catOptions`: tree-ordered list of {id, parts, off}; `$catSelected`: ids.
--}}
@once
    @push('scripts')
        <script nonce="{{ csp_nonce() }}">
            document.addEventListener('alpine:init', () => {
                Alpine.data('productCategories', (config) => ({
                    options: config.options,
                    selected: config.selected,
                    search: '',
                    open: false,
                    cursor: 0,

                    get chosen() {
                        return this.selected.map((id) => this.options.find((o) => o.id === id)).filter(Boolean);
                    },

                    get rows() {
                        const q = this.search.trim().toLowerCase();
                        if (!q) return this.options;
                        return this.options.filter((o) => o.parts.join(' / ').toLowerCase().includes(q)).slice(0, 80);
                    },

                    has(id) { return this.selected.includes(id); },

                    toggle(option) {
                        this.has(option.id)
                            ? this.selected = this.selected.filter((id) => id !== option.id)
                            : this.selected.push(option.id);
                        // Back to the search box, so the next one can be typed.
                        if (this.open) this.$refs.search.focus();
                    },

                    show() {
                        this.open = true;
                        this.cursor = 0;
                        this.$nextTick(() => this.$refs.search.focus());
                    },

                    hide() { this.open = false; this.search = ''; },

                    move(step) {
                        const n = this.rows.length;
                        if (!n) return;
                        this.cursor = (this.cursor + step + n) % n;
                        this.$nextTick(() => this.$refs.list.querySelector('[data-cursor]')?.scrollIntoView({ block: 'nearest' }));
                    },

                    // Enter ticks the row under the cursor rather than submitting
                    // the product form the panel stands in.
                    pick() { this.rows[this.cursor] && this.toggle(this.rows[this.cursor]); },
                }));
            });
        </script>
    @endpush
@endonce

<div x-data="productCategories({ options: {{ \Illuminate\Support\Js::from($catOptions) }}, selected: {{ \Illuminate\Support\Js::from(array_values($catSelected)) }} })"
     class="flex flex-col gap-1.5" @keydown.escape.stop="hide()">
    <div class="fieldset-legend p-0 flex items-center gap-2 w-full">
        {{ __('Categories') }}
        <a href="{{ route('admin.catalog.categories.index') }}" class="link link-hover text-xs opacity-70 ml-auto">{{ __('manage') }}</a>
    </div>

    <div class="relative" @click.outside="hide()">
        <div class="flex flex-wrap items-center gap-2">
            <template x-for="option in chosen" :key="option.id">
                <span class="inline-flex items-center gap-1.5 h-8 ps-2.5 pe-1 rounded-field border border-base-300 bg-base-200/60 text-sm max-w-full"
                      :class="option.off && 'border-dashed'">
                    <x-icon name="folder" size="sm" class="opacity-50 shrink-0" />
                    <span class="truncate">
                        <template x-for="(part, i) in option.parts.slice(0, -1)" :key="i">
                            <span class="opacity-55"><span x-text="part"></span> › </span>
                        </template>
                        <span class="font-medium" x-text="option.parts[option.parts.length - 1]"></span>
                        <span class="text-xs opacity-60" x-show="option.off" x-text="'· ' + option.off"></span>
                    </span>
                    <button type="button" class="btn btn-ghost btn-circle btn-xs min-h-0 h-6 w-6 shrink-0" @click="toggle(option)" aria-label="{{ __('Remove') }}">
                        <x-icon name="x" size="sm" />
                    </button>
                    <input type="hidden" name="categories[]" :value="option.id">
                </span>
            </template>

            <button type="button" class="btn btn-sm btn-ghost border border-dashed border-base-300 gap-1.5" @click="open ? hide() : show()">
                <x-icon name="plus" size="sm" />
                <span x-text="selected.length ? @js(__('Add a category')) : @js(__('Choose a category'))"></span>
            </button>
            <span class="text-xs text-warning" x-show="!selected.length" x-cloak>{{ __("No category: the product is not in any category's listing.") }}</span>
        </div>

        <div x-show="open" x-cloak x-transition.opacity.duration.100ms
             class="absolute z-30 mt-2 w-full max-w-xl bg-base-100 border border-base-300 rounded-box shadow-lg flex flex-col">
            <label class="input input-bordered m-2 gap-2 w-auto">
                <x-icon name="search" size="sm" class="opacity-50" />
                <input type="text" x-ref="search" x-model="search" @input="cursor = 0" class="grow" autocomplete="off"
                       placeholder="{{ __('Search categories…') }}" aria-label="{{ __('Search categories…') }}"
                       @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)" @keydown.enter.prevent="pick()">
            </label>
            <ul x-ref="list" class="max-h-80 overflow-y-auto px-1 pb-1">
                <template x-for="(option, index) in rows" :key="option.id">
                    <li>
                        <button type="button" @click="toggle(option)" @mouseenter="cursor = index"
                                class="w-full flex items-center gap-2 py-1.5 pe-2 rounded-field text-left text-sm"
                                :class="index === cursor && 'bg-base-200'"
                                :data-cursor="index === cursor ? '' : null"
                                :style="search.trim() ? 'padding-inline-start: .5rem' : 'padding-inline-start: ' + (0.5 + option.parts.length * 1.1 - 1.1) + 'rem'">
                            <span class="size-4 shrink-0 rounded border flex items-center justify-center"
                                  :class="has(option.id) ? 'bg-primary border-primary text-primary-content' : 'border-base-content/30'">
                                <span x-show="has(option.id)" class="inline-flex"><x-icon name="check" size="sm" /></span>
                            </span>
                            <span class="truncate" :class="option.off && 'opacity-60'">
                                <template x-if="search.trim()">
                                    <span>
                                        <template x-for="(part, i) in option.parts.slice(0, -1)" :key="i">
                                            <span class="opacity-55"><span x-text="part"></span> › </span>
                                        </template>
                                    </span>
                                </template>
                                <span :class="option.parts.length === 1 && !search.trim() && 'font-medium'" x-text="option.parts[option.parts.length - 1]"></span>
                                <span class="text-xs opacity-60" x-show="option.off" x-text="'· ' + option.off"></span>
                            </span>
                        </button>
                    </li>
                </template>
                <li x-show="rows.length === 0" class="px-3 py-2 text-sm opacity-50">{{ __('No matches') }}</li>
            </ul>
            <div class="flex items-center justify-between gap-2 border-t border-base-300 px-3 py-2 text-xs opacity-70">
                <span>{{ __('Click to tick or untick; several can be ticked.') }}</span>
                <button type="button" class="btn btn-sm btn-primary" @click="hide()">{{ __('Done') }}</button>
            </div>
        </div>
    </div>
</div>
