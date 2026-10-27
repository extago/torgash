{{--
    Category tiles — see ProductBlock::register(). The categories the shop
    picked, in its order (none picked: the top ones, in the catalogue's
    order), each a picture and a name. A grid fills the width with as many
    tiles as fit their size; a row (or two) scrolls sideways — the browser's
    own scrolling, so touch and trackpad need no script; tiles.js adds the
    indicator, the arrows and dragging by mouse. Prints nothing when no
    category is left to show.
--}}
@php
    $tilesTree = app(\Torgash\Catalog\Support\CategoryTree::class);
    $tilesPicked = array_map('intval', (array) ($settings['categories'] ?? []));
    $tilesCategories = ($tilesPicked === []
            ? collect($tilesTree->ordered(listedOnly: true))->filter(fn ($c) => $c->parent_id === null)
            : collect($tilesPicked)->map(fn ($id) => $tilesTree->find($id))->filter(fn ($c) => $c && $c->isListed()))
        ->values();

    $tilesLayout = in_array($settings['layout'] ?? '', ['grid', 'strip', 'strip2'], true) ? $settings['layout'] : 'strip';
    $tilesLook = in_array($settings['look'] ?? '', ['under', 'inside', 'corner'], true) ? $settings['look'] : 'under';
    // Tinted: each tile a soft colour of its own, by position — so two
    // neighbours never share one, whatever the shop picked.
    $tilesHues = ($settings['colors'] ?? '') === 'tinted' ? [250, 340, 35, 150, 205, 295, 80, 175] : null;
    $tilesPicture = in_array($settings['picture'] ?? '', ['auto', 'tile', 'icon', 'none'], true) ? $settings['picture'] : 'auto';
    // Sizes are whole px, typed by the shop; sanitize() clamped the numbers,
    // the view clamps again for a block saved before a limit moved.
    $tilesPx = fn ($value, int $default, int $min, int $max) => is_numeric($value) ? max($min, min($max, (int) round((float) $value))) : $default;
    $tilesSize = $tilesPx($settings['picture_size'] ?? null, 72, 24, 240);
    $tilesGap = $tilesPx($settings['gap_size'] ?? null, 16, 0, 80);
    $tilesText = $tilesPx($settings['text_size'] ?? null, 14, 10, 32);
    // Padding as CSS writes it — one to four numbers, "px" optional: top,
    // right, bottom, left. Anything else is the look's own padding.
    $tilesPad = $tilesLook === 'corner' ? [12, 14, 12, 14] : [16, 16, 16, 16];
    $tilesPadTyped = trim((string) ($settings['padding'] ?? ''));
    if ($tilesPadTyped !== '' && preg_match('/^\d{1,3}(?:px)?(?:\s+\d{1,3}(?:px)?){0,3}$/i', $tilesPadTyped)) {
        $tilesPadParts = array_map(fn ($part) => min(200, (int) $part), preg_split('/\s+/', $tilesPadTyped));
        [$tilesPadT, $tilesPadR, $tilesPadB, $tilesPadL] = match (count($tilesPadParts)) {
            1 => array_fill(0, 4, $tilesPadParts[0]),
            2 => [$tilesPadParts[0], $tilesPadParts[1], $tilesPadParts[0], $tilesPadParts[1]],
            3 => [$tilesPadParts[0], $tilesPadParts[1], $tilesPadParts[2], $tilesPadParts[1]],
            default => $tilesPadParts,
        };
        $tilesPad = [$tilesPadT, $tilesPadR, $tilesPadB, $tilesPadL];
    }
    $tilesScrolls = $tilesLayout !== 'grid';
    $tilesHeading = trim((string) ($settings['heading'] ?? ''));
@endphp
@if ($tilesCategories->isNotEmpty())
    @if ($tilesScrolls)
        @once
            @push('scripts')
                <script nonce="{{ csp_nonce() }}" src="{{ module_asset('torgash.catalog', 'tiles.js') }}"></script>
            @endpush
        @endonce
    @endif
    <section @class(['torgash-block torgash-block-category-tiles', 'mb-10' => ! $inTabs])
             style="--tile-pic: {{ $tilesSize }}px; --tile-gap: {{ $tilesGap }}px; --tile-text: {{ $tilesText }}px; --tile-pt: {{ $tilesPad[0] }}px; --tile-pr: {{ $tilesPad[1] }}px; --tile-pb: {{ $tilesPad[2] }}px; --tile-pl: {{ $tilesPad[3] }}px"
             @if ($tilesScrolls) x-data="torgashTiles" @endif>
        @if (! $inTabs && $tilesHeading !== '')
            <h2 class="text-lg font-semibold mb-4">{{ $tilesHeading }}</h2>
        @endif

        <div class="relative group">
            <div @class([
                    'torgash-tiles',
                    'torgash-tiles-'.$tilesLook,
                    'torgash-tiles-tinted' => $tilesHues !== null,
                    'torgash-tiles-grid' => ! $tilesScrolls,
                    'torgash-tiles-strip' => $tilesScrolls,
                    'torgash-tiles-two' => $tilesLayout === 'strip2',
                 ])
                 @if ($tilesScrolls) x-ref="track" x-on:scroll.passive="measure()" @endif>
                @foreach ($tilesCategories as $tilesCategory)
                    @php
                        $tilesName = $tilesCategory->t('name');
                        $tilesImage = match ($tilesPicture) {
                            'auto' => $tilesCategory->tileImageUrl() ?? $tilesCategory->iconImageUrl(),
                            'tile' => $tilesCategory->tileImageUrl(),
                            'icon' => $tilesCategory->iconImageUrl(),
                            default => null,
                        };
                        // A photo fills its box; an icon is a sign, drawn small
                        // in the shop's colour.
                        $tilesIsPhoto = $tilesImage !== null && $tilesPicture !== 'icon' && $tilesCategory->tileImageUrl() === $tilesImage;
                        $tilesIcon = $tilesImage === null && $tilesPicture !== 'none' ? $tilesCategory->lucideIcon() : null;
                    @endphp
                    <a href="{{ route('catalog.category', $tilesCategory->slug) }}" class="torgash-tile" draggable="false"
                       @if ($tilesHues) style="--tile-hue: {{ $tilesHues[$loop->index % count($tilesHues)] }}" @endif>
                        @if ($tilesPicture !== 'none')
                            <span @class(['torgash-tile-pic', 'is-photo' => $tilesIsPhoto])>
                                @if ($tilesImage)
                                    <img src="{{ $tilesImage }}" alt="" loading="lazy" draggable="false">
                                @elseif ($tilesIcon)
                                    <x-icon :name="$tilesIcon" />
                                @else
                                    <span class="torgash-tile-letter">{{ mb_substr($tilesName, 0, 1) }}</span>
                                @endif
                            </span>
                        @endif
                        <span class="torgash-tile-name">{{ $tilesName }}</span>
                    </a>
                @endforeach
            </div>

            @if ($tilesScrolls && ! empty($settings['arrows']))
                <button type="button" x-cloak x-show="canPrev" x-on:click="page(-1)" aria-label="{{ __('Previous') }}"
                        class="torgash-tiles-arrow left-0 -translate-x-1/2"><x-icon name="chevron-left" size="sm" /></button>
                <button type="button" x-cloak x-show="canNext" x-on:click="page(1)" aria-label="{{ __('Next') }}"
                        class="torgash-tiles-arrow right-0 translate-x-1/2"><x-icon name="chevron-right" size="sm" /></button>
            @endif
        </div>

        @if ($tilesScrolls && ($settings['indicator'] ?? true))
            <div class="torgash-tiles-indicator" x-cloak x-show="thumb < 100" aria-hidden="true">
                <span x-bind:style="`width: ${thumb}%; transform: translateX(${offset}%)`"></span>
            </div>
        @endif
    </section>
@endif
