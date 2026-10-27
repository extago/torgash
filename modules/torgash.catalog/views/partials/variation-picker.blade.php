{{--
    The variation group's pickers (Support\Variations): one row per axis, each
    value a LINK to the member that keeps this product's other answers. How a
    row is drawn is the characteristic's own `product_style`
    (Feature::productStyle()): labels, the members' pictures, colour swatches
    or a drop-down — the links underneath are the same in every one.

    Over a picture, the gallery shows that member's photo until the pointer
    leaves (setting `variation_image_hover`) —
    a look, not a choice: nothing is selected until the link is followed.

    `$pickerAxes` rather than `$axes`: @include hands every variable down.
--}}
@php
    // The members drawn as pictures need their photos — loaded once for the
    // whole group, not one query per tile.
    $pickerImageMembers = collect($pickerAxes)
        ->filter(fn ($axis) => $axis['feature']->productStyle() === 'images')
        ->flatMap(fn ($axis) => array_column($axis['options'], 'product'))
        ->unique('id');
    if ($pickerImageMembers->isNotEmpty()) {
        (new \Illuminate\Database\Eloquent\Collection($pickerImageMembers->all()))->loadMissing('media');
    }
    $pickerHover = (bool) setting('torgash.catalog', 'variation_image_hover', true);
@endphp
<div class="flex flex-col gap-3 pt-4 border-t border-base-300"
     @if ($pickerHover && $pickerImageMembers->isNotEmpty())
     x-data="{
         slide() {
             const stage = document.querySelector('[data-gallery-stage]');
             if (! stage) return null;
             return stage.children[Math.round(stage.scrollLeft / Math.max(1, stage.clientWidth))]?.querySelector('img') ?? null;
         },
         preview(url) {
             const img = this.slide();
             if (! img || ! url) return;
             img.dataset.ownSrc ??= img.getAttribute('src');
             img.src = url;
         },
         restore() {
             const img = this.slide();
             if (img?.dataset.ownSrc) { img.src = img.dataset.ownSrc; delete img.dataset.ownSrc; }
         },
     }"
     @endif>
    @foreach ($pickerAxes as $pickerAxis)
        @php
            $pickerStyle = $pickerAxis['feature']->productStyle();
            $pickerActive = collect($pickerAxis['options'])->firstWhere('active', true);
        @endphp
        <div>
            {{-- The axis and the answer this page has, as a shop says it: "Colour: Pacific blue". --}}
            <div class="text-sm mb-1.5">
                <span class="opacity-70">{{ $pickerAxis['feature']->t('name') }}:</span>
                @if ($pickerActive)
                    <span class="font-medium">{{ $pickerActive['label'] }}</span>
                @endif
            </div>

            @if ($pickerStyle === 'dropdown')
                <select class="select w-full" aria-label="{{ $pickerAxis['feature']->t('name') }}"
                        x-data x-on:change="window.location = $event.target.value">
                    @foreach ($pickerAxis['options'] as $pickerOption)
                        <option value="{{ route('catalog.product', $pickerOption['product']->slug) }}" @selected($pickerOption['active'])>{{ $pickerOption['label'] }}</option>
                    @endforeach
                </select>
            @else
                <div @class(['flex flex-wrap', 'gap-2' => $pickerStyle !== 'swatches', 'gap-2.5' => $pickerStyle === 'swatches'])>
                    @foreach ($pickerAxis['options'] as $pickerOption)
                        @php
                            $pickerUrl = route('catalog.product', $pickerOption['product']->slug);
                            // A 56px tile asks for a thumbnail, never the original
                            // (eight originals per page was measured on the live shop);
                            // the gallery preview on hover wants the page's size.
                            $pickerImage = $pickerStyle === 'images' ? $pickerOption['product']->primaryImageUrl(120) : null;
                            $pickerPreview = $pickerImage && $pickerHover && ! $pickerOption['active'] ? $pickerOption['product']->primaryImageUrl(800) : null;
                        @endphp
                        @if ($pickerStyle === 'images')
                            {{-- A long name ("полуночный черный") wraps inside the tile,
                                 hyphenated, two lines at most; the whole of it is the title. --}}
                            <a href="{{ $pickerUrl }}" @if ($pickerOption['active']) aria-current="true" @endif
                               @if ($pickerPreview)
                                   x-on:mouseenter="preview(@js($pickerPreview))" x-on:mouseleave="restore()"
                               @endif
                               title="{{ $pickerOption['label'] }}"
                               @class([
                                   'flex flex-col items-center gap-1 w-[5rem] p-1 rounded-lg border text-xs text-center leading-tight',
                                   'border-primary ring-1 ring-primary font-semibold' => $pickerOption['active'],
                                   'border-base-300 hover:border-primary/50' => ! $pickerOption['active'],
                               ])>
                                @if ($pickerImage)
                                    <img src="{{ $pickerImage }}" alt="" class="w-14 h-14 object-contain" loading="lazy">
                                @elseif ($pickerOption['swatch'])
                                    <span class="w-14 h-14 rounded-md border border-black/10" style="background-color: {{ $pickerOption['swatch'] }}"></span>
                                @else
                                    <x-no-image class="w-14 h-14 rounded-md" />
                                @endif
                                <span class="line-clamp-2 w-full hyphens-auto [overflow-wrap:anywhere]">{{ $pickerOption['label'] }}</span>
                            </a>
                        @elseif ($pickerStyle === 'swatches' && $pickerOption['swatch'])
                            <a href="{{ $pickerUrl }}" title="{{ $pickerOption['label'] }}" aria-label="{{ $pickerOption['label'] }}"
                               @if ($pickerOption['active']) aria-current="true" @endif
                               @class([
                                   'h-8 w-8 rounded-full border border-black/15 ring-offset-2 ring-offset-base-100',
                                   'ring-2 ring-primary' => $pickerOption['active'],
                                   'hover:ring-2 hover:ring-base-300' => ! $pickerOption['active'],
                               ])
                               style="background-color: {{ $pickerOption['swatch'] }}"></a>
                        @else
                            <a href="{{ $pickerUrl }}" @if ($pickerOption['active']) aria-current="true" @endif
                               @class([
                                   'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border text-sm',
                                   'border-primary bg-primary/5 text-primary font-semibold' => $pickerOption['active'],
                                   'border-base-300 hover:border-primary/50' => ! $pickerOption['active'],
                               ])>
                                @if ($pickerOption['swatch'])
                                    <span class="h-3.5 w-3.5 rounded-full border border-black/15" style="background-color: {{ $pickerOption['swatch'] }}"></span>
                                @endif
                                {{ $pickerOption['label'] }}
                            </a>
                        @endif
                    @endforeach
                </div>
            @endif
        </div>
    @endforeach
</div>
