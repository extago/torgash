{{--
    Rendered below the buy panel via the catalog.product.after_buy_box hook
    (see product.blade.php and MotivationServiceProvider). Multiple cards can
    be open at once — each toggles independently, there is no accordion
    exclusivity.
--}}
@php
    $items = \Torgash\Motivation\Models\MotivationItem::forProduct($product, auth()->user()?->customer_group_id);
    $renderer = app(\Torgash\Motivation\ContentRenderer::class);
    $cards = $items
        ->map(fn ($item) => ['item' => $item, 'html' => $renderer->render($item, $product)])
        ->filter(fn ($row) => $row['html'] !== null)
        ->values();
    $remember = (bool) setting('torgash.motivation', 'remember_state', true);
@endphp
@if ($cards->isNotEmpty())
    {{-- No margin here: this renders straight into the product page's grid
         (see catalog.product.after_buy_box), whose own gap already spaces it
         from the buy panel above — and default grid stretch is what lets this
         very box reach down to match the description column's height. --}}
    <div class="bg-base-100 border border-base-300 rounded-box divide-y divide-base-300">
        @foreach ($cards as $row)
            @php $item = $row['item']; @endphp
            <div x-data="{
                    remember: {{ $remember ? 'true' : 'false' }},
                    open: {{ $item->expanded ? 'true' : 'false' }},
                    init() {
                        if (!this.remember) return;
                        try {
                            const saved = localStorage.getItem('torgash-mb-{{ $item->id }}');
                            if (saved !== null) this.open = saved === '1';
                        } catch (e) {}
                    },
                    toggle() {
                        this.open = !this.open;
                        if (!this.remember) return;
                        try { localStorage.setItem('torgash-mb-{{ $item->id }}', this.open ? '1' : '0'); } catch (e) {}
                    },
                 }">
                <button type="button" @click="toggle()" class="w-full flex items-center gap-2.5 px-4 py-3 text-left">
                    @if ($item->icon_type === 'lucide' && $item->icon_name)
                        <x-icon :name="$item->icon_name" size="lg" class="{{ $item->icon_color ? 'text-'.$item->icon_color : 'opacity-60' }} shrink-0" />
                    @elseif ($item->icon_type === 'image' && $item->iconUrl())
                        <img src="{{ $item->iconUrl() }}" class="h-5 w-5 object-contain shrink-0" alt="">
                    @endif
                    <span class="font-medium flex-1">{{ $item->t('name') }}</span>
                    {{-- :class on a component would be a Blade prop bound at render time, not
                         an Alpine directive — the plain <span> wrapper is what actually
                         reaches the browser for Alpine to bind. --}}
                    <span class="shrink-0 transition-transform" :class="{ 'rotate-180': open }">
                        <x-icon name="chevron-down" size="sm" class="opacity-50" />
                    </span>
                </button>
                <div x-show="open" x-transition class="px-4 pb-4 -mt-1 torgash-rich text-sm opacity-80">
                    {!! $row['html'] !!}
                </div>
            </div>
        @endforeach
    </div>
@endif
