{{--
    The collection form's "Looks": pictures with points on the products in
    them. Inside the collection's own form (it is multipart): new pictures are
    `looks_new[]`, each look's points travel as JSON in `looks[id][points]`,
    kept by a small editor — a click on the picture puts a point there (in
    percent, so it stays put at any width), the point's product is FOUND under
    it by what the admin types, never listed: a shop may have a hundred
    thousand products (Catalog\Admin\ProductLookup). Named look*: an @include
    hands a partial every variable its caller has.
--}}
@once
    @push('scripts')
        <script nonce="{{ csp_nonce() }}">
            document.addEventListener('alpine:init', () => {
                Alpine.data('lookEditor', (points, source) => ({
                    points: points,
                    source: source,
                    open: null,
                    query: '',
                    results: [],
                    loading: false,
                    asked: 0,
                    put(event) {
                        const box = event.currentTarget.getBoundingClientRect();
                        const x = Math.round(((event.clientX - box.left) / box.width) * 1000) / 10;
                        const y = Math.round(((event.clientY - box.top) / box.height) * 1000) / 10;
                        this.points.push({ x: x, y: y, product_id: '', label: '' });
                        this.edit(this.points.length - 1);
                    },
                    remove(index) { this.points.splice(index, 1); this.open = null; },
                    edit(index) {
                        this.open = index;
                        this.query = '';
                        this.results = [];
                        // Once x-show has drawn the field; the button that was clicked is gone by then.
                        this.$nextTick(() => requestAnimationFrame(() => this.$root.querySelector('[data-point-search="' + index + '"]')?.focus()));
                    },
                    // Debounced, and only the latest answer counts.
                    ask() {
                        clearTimeout(this.timer);
                        const q = this.query.trim();
                        if (q === '') { this.results = []; this.loading = false; return; }
                        this.loading = true;
                        this.timer = setTimeout(async () => {
                            const ticket = ++this.asked;
                            try {
                                const response = await fetch(this.source + '?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } });
                                const found = response.ok ? await response.json() : [];
                                if (ticket === this.asked) { this.results = found; }
                            } finally {
                                if (ticket === this.asked) { this.loading = false; }
                            }
                        }, 250);
                    },
                    choose(index, option) {
                        this.points[index].product_id = option.id;
                        this.points[index].label = option.label;
                        this.open = null;
                        this.query = '';
                        this.results = [];
                    },
                    get json() { return JSON.stringify(this.points.filter((p) => p.product_id !== '').map((p) => ({ x: p.x, y: p.y, product_id: Number(p.product_id) }))); },
                }));
            });
        </script>
    @endpush
@endonce

<section class="card bg-base-100 border border-base-300" id="looks">
    <div class="card-body gap-4">
        <div>
            <h3 class="font-semibold">{{ __('Looks') }}</h3>
            <p class="text-sm opacity-70 mt-1">{{ __('A picture of a room or an outfit with points on the products in it — shown above the collection\'s products. Click the picture to put a point, then choose its product. A point without a product is not kept.') }}</p>
        </div>

        @foreach ($looks as $lookRow)
            @php
                $lookStart = collect((array) $lookRow->points)->map(fn ($lookPoint) => [
                    'x' => (float) ($lookPoint['x'] ?? 0),
                    'y' => (float) ($lookPoint['y'] ?? 0),
                    'product_id' => (int) ($lookPoint['product_id'] ?? 0),
                    'label' => $lookProducts[(int) ($lookPoint['product_id'] ?? 0)] ?? '#'.(int) ($lookPoint['product_id'] ?? 0),
                ])->values();
            @endphp
            <div class="border border-base-300 rounded-box p-3 flex flex-col gap-3"
                 x-data="lookEditor(@js($lookStart), @js(route('admin.collections.products')))">
                <input type="hidden" name="looks[{{ $lookRow->id }}][points]" :value="json">
                <div class="relative cursor-crosshair select-none" x-on:click="put($event)">
                    <img src="{{ $lookRow->imageUrl() }}" alt="" class="w-full rounded" draggable="false">
                    <template x-for="(point, index) in points" :key="index">
                        <span class="absolute -translate-x-1/2 -translate-y-1/2 w-7 h-7 rounded-full text-xs font-bold grid place-items-center shadow ring-2 ring-white pointer-events-none"
                              :class="point.product_id === '' ? 'bg-warning text-warning-content' : 'bg-primary text-primary-content'"
                              :style="'left:' + point.x + '%;top:' + point.y + '%'" x-text="index + 1"></span>
                    </template>
                </div>
                <div class="flex flex-col gap-2">
                    <template x-for="(point, index) in points" :key="'row' + index">
                        <div class="flex items-center gap-2">
                            <span class="w-7 h-7 rounded-full text-xs font-bold grid place-items-center shrink-0"
                                  :class="point.product_id === '' ? 'bg-warning text-warning-content' : 'bg-primary text-primary-content'" x-text="index + 1"></span>
                            <div class="relative flex-1 min-w-0" x-on:click.outside="open === index && (open = null)">
                                {{-- The chosen product reads as text; a click on it searches again. --}}
                                <button type="button" x-show="open !== index" x-on:click="edit(index)"
                                        class="input input-bordered w-full text-left" title="{{ __('Choose another product') }}">
                                    <span class="truncate" :class="point.product_id === '' && 'opacity-50'" x-text="point.label || @js(__('Choose the product…'))"></span>
                                </button>
                                <input type="search" x-show="open === index" x-model="query"
                                       :data-point-search="index"
                                       x-on:focus="open = index" x-on:input="open = index; ask()"
                                       x-on:keydown.enter.prevent="results.length && choose(index, results[0])"
                                       x-on:keydown.escape="open = null"
                                       placeholder="{{ __('Search products by SKU or name…') }}" aria-label="{{ __('The point\'s product') }}"
                                       class="input input-bordered w-full" autocomplete="off">
                                <ul x-show="open === index && query.trim() !== ''" x-cloak
                                    class="absolute z-30 mt-1 w-full bg-base-100 border border-base-300 rounded-box shadow-md max-h-64 overflow-y-auto">
                                    <template x-for="option in results" :key="option.id">
                                        <li><button type="button" x-on:click="choose(index, option)" class="w-full text-left px-3 py-1.5 hover:bg-base-200 text-sm" x-text="option.label"></button></li>
                                    </template>
                                    <li x-show="loading" class="px-3 py-2 text-sm opacity-50">{{ __('Searching…') }}</li>
                                    <li x-show="!loading && results.length === 0" class="px-3 py-2 text-sm opacity-50">{{ __('No matches') }}</li>
                                </ul>
                            </div>
                            <button type="button" class="btn btn-ghost btn-sm btn-square text-error" x-on:click="remove(index)" title="{{ __('Remove the point') }}"><x-icon name="x" /></button>
                        </div>
                    </template>
                </div>
                <div class="grid sm:grid-cols-[1fr_10rem_auto] gap-3 items-end">
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Caption') }} <span class="opacity-40 font-normal">({{ strtoupper(admin_content_locale()) }})</span></legend>
                        <input name="looks[{{ $lookRow->id }}][caption]" value="{{ $lookRow->t('caption', admin_content_locale()) }}" aria-label="{{ __('Caption') }}" class="input input-bordered w-full">
                    </fieldset>
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Sort order') }}</legend>
                        <input type="number" name="looks[{{ $lookRow->id }}][sort]" value="{{ $lookRow->sort }}" aria-label="{{ __('Sort order') }}" class="input input-bordered w-full">
                    </fieldset>
                    <label class="flex items-center gap-2 text-sm text-error cursor-pointer pb-3">
                        <input type="checkbox" name="looks[{{ $lookRow->id }}][delete]" value="1" class="checkbox checkbox-sm checkbox-error"> {{ __('Delete the look') }}
                    </label>
                </div>
            </div>
        @endforeach

        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Add pictures') }}</legend>
            <input type="file" name="looks_new[]" multiple accept="image/webp,image/png,image/jpeg,image/avif" aria-label="{{ __('Add pictures') }}" class="file-input file-input-bordered w-full">
            <p class="label text-xs opacity-60 whitespace-normal">{{ __('WebP, PNG, JPEG or AVIF. Save, and put the points on them.') }}</p>
        </fieldset>
    </div>
</section>
