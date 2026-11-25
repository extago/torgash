{{--
    Category tiles — see ProductBlock::register(). The categories the shop
    picked, in its order (none picked: the top ones, in the catalogue's
    order), each a picture and a name. A grid fills the width with as many
    tiles as fit their size; a row (or two) scrolls sideways — the browser's
    own scrolling, so touch and trackpad need no script; tiles.js adds the
    indicator, the arrows and dragging by mouse. Prints nothing when no
    category is left to show.

    A tile is a row of the block's `categories` (BlockTypes `rows`): a
    category, which may have its own picture, name and CSS class on this
    block, or a link of its own (an address, a name, a picture). A bare id
    is a category tile with nothing of its own — how the block stored them
    before, and how the subcategories partial still passes them.

    A category's page draws its subcategories with this same view
    (partials/catalog-subcategories, settings from the theme), so the two
    look alike; `bare` there leaves the heading and the margin to the page.
--}}
@php
    $tilesTree = app(\Torgash\Catalog\Support\CategoryTree::class);
    $tilesRows = collect((array) ($settings['categories'] ?? []))
        ->map(fn ($row) => is_array($row) ? $row : ['kind' => 'category', 'category' => $row]);
    // Each tile: its category (none for a link), where it leads, and what
    // the shop gave it on this block — checked again here, since a theme's
    // default blocks reach this view without the block form's casting.
    $tilesItems = ($tilesRows->isEmpty()
            ? collect($tilesTree->ordered(listedOnly: true))->filter(fn ($c) => $c->parent_id === null)->map(fn ($c) => ['category' => $c])
            : $tilesRows->map(function (array $row) use ($tilesTree) {
                $ownName = $row['name'] ?? null;
                $ownName = trim(is_array($ownName) ? (string) pick_translation($ownName) : (string) $ownName);
                $own = [
                    'name' => $ownName !== '' ? $ownName : null,
                    'image' => class_exists(\Torgash\Blocks\BlockImages::class) ? \Torgash\Blocks\BlockImages::url($row['image'] ?? null) : null,
                    'fit' => ($row['fit'] ?? null) === 'cover' ? 'cover' : 'whole',
                    'class' => trim(implode(' ', array_filter(preg_split('/\s+/', (string) ($row['class'] ?? '')), fn ($name) => preg_match('/^-?[A-Za-z_][A-Za-z0-9_-]*$/', $name)))),
                ];

                if (($row['kind'] ?? 'category') === 'link') {
                    $href = trim((string) ($row['url'] ?? ''));

                    return $own['name'] !== null && preg_match('#^(https?://|/(?!/))\S*$#i', $href) ? $own + ['category' => null, 'href' => $href] : null;
                }

                $category = is_numeric($row['category'] ?? null) ? $tilesTree->find((int) $row['category']) : null;

                return $category && $category->isListed() ? $own + ['category' => $category] : null;
            })->filter())
        ->values();
    $tilesCategories = $tilesItems->pluck('category')->filter()->unique('id')->values();

    $tilesLayout = in_array($settings['layout'] ?? '', ['grid', 'strip', 'strip2'], true) ? $settings['layout'] : 'strip';
    $tilesLook = in_array($settings['look'] ?? '', ['under', 'inside', 'corner'], true) ? $settings['look'] : 'under';
    // Tinted: each tile a soft colour of its own, by position — so two
    // neighbours never share one, whatever the shop picked.
    $tilesHues = ($settings['colors'] ?? '') === 'tinted' ? [250, 340, 35, 150, 205, 295, 80, 175] : null;
    $tilesPicture = in_array($settings['picture'] ?? '', ['auto', 'tile', 'icon', 'none'], true) ? $settings['picture'] : 'auto';
    // The picture's frame under the `under` look: the card as wide as the
    // tile, or a circle / a rounded square around the picture.
    $tilesShape = in_array($settings['shape'] ?? '', ['card', 'circle', 'rounded'], true) ? $settings['shape'] : 'card';
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
    $tilesBare = ! empty($settings['bare']);
    // How many products each holds, under its name; a category with no tile
    // picture borrows the photo of its most popular product in stock — one
    // query each for the whole row (CategoryCounts, CategoryPhotos).
    $tilesCounts = ! empty($settings['counts']) && $tilesCategories->isNotEmpty()
        ? app(\Torgash\Catalog\Support\CategoryCounts::class)->of($tilesCategories)
        : null;
    $tilesPhotos = ! empty($settings['photos']) && in_array($tilesPicture, ['auto', 'tile'], true)
        ? app(\Torgash\Catalog\Support\CategoryPhotos::class)->of($tilesItems->filter(fn ($item) => $item['category'] && empty($item['image']) && $item['category']->tileImageUrl() === null)->pluck('category')->unique('id')->values(), $tilesSize * 2)
        : [];
@endphp
@if ($tilesItems->isNotEmpty())
    @if ($tilesScrolls)
        @once
            @push('scripts')
                <script nonce="{{ csp_nonce() }}" src="{{ module_asset('torgash.catalog', 'tiles.js') }}"></script>
            @endpush
        @endonce
    @endif
    <section @class(['torgash-block torgash-block-category-tiles', 'mb-10' => ! $inTabs && ! $tilesBare])
             style="--tile-pic: {{ $tilesSize }}px; --tile-gap: {{ $tilesGap }}px; --tile-text: {{ $tilesText }}px; --tile-pt: {{ $tilesPad[0] }}px; --tile-pr: {{ $tilesPad[1] }}px; --tile-pb: {{ $tilesPad[2] }}px; --tile-pl: {{ $tilesPad[3] }}px"
             @if ($tilesScrolls) x-data="torgashTiles" @endif>
        @if (! $inTabs && $tilesHeading !== '')
            <h2 class="text-lg font-semibold mb-4">{{ $tilesHeading }}</h2>
        @endif

        <div class="relative group">
            <div @class([
                    'torgash-tiles',
                    'torgash-tiles-'.$tilesLook,
                    'torgash-tiles-'.$tilesShape => $tilesShape !== 'card',
                    'torgash-tiles-tinted' => $tilesHues !== null,
                    'torgash-tiles-grid' => ! $tilesScrolls,
                    'torgash-tiles-strip' => $tilesScrolls,
                    'torgash-tiles-two' => $tilesLayout === 'strip2',
                 ])
                 @if ($tilesScrolls) x-ref="track" x-on:scroll.passive="measure()" @endif>
                @foreach ($tilesItems as $tilesItem)
                    @php
                        $tilesCategory = $tilesItem['category'];
                        $tilesName = $tilesItem['name'] ?? $tilesCategory->t('name');
                        $tilesLent = $tilesCategory ? ($tilesPhotos[$tilesCategory->id] ?? null) : null;
                        // The tile's own picture, given on this block, wins
                        // whatever "Picture" says — except "No picture".
                        $tilesOwn = $tilesPicture !== 'none' ? ($tilesItem['image'] ?? null) : null;
                        $tilesImage = $tilesOwn ?? ($tilesCategory ? match ($tilesPicture) {
                            'auto' => $tilesCategory->tileImageUrl() ?? $tilesLent ?? $tilesCategory->iconImageUrl(),
                            'tile' => $tilesCategory->tileImageUrl() ?? $tilesLent,
                            'icon' => $tilesCategory->iconImageUrl(),
                            default => null,
                        } : null);
                        // A photo fills its box; a product's photo is the
                        // product whole, inside it; an icon is a sign, drawn
                        // small in the shop's colour.
                        $tilesIsPhoto = ($tilesOwn !== null && ($tilesItem['fit'] ?? '') === 'cover') || ($tilesOwn === null && $tilesImage !== null && $tilesPicture !== 'icon' && $tilesCategory?->tileImageUrl() === $tilesImage);
                        $tilesIsProduct = $tilesImage !== null && ! $tilesIsPhoto && $tilesImage === $tilesLent;
                        $tilesIcon = $tilesImage === null && $tilesPicture !== 'none' ? $tilesCategory?->lucideIcon() : null;
                        $tilesHref = $tilesCategory ? route('catalog.category', $tilesCategory->slug) : $tilesItem['href'];
                    @endphp
                    <a href="{{ $tilesHref }}" @class(['torgash-tile', $tilesItem['class'] ?? '' => ($tilesItem['class'] ?? '') !== '']) draggable="false"
                       @if ($tilesHues) style="--tile-hue: {{ $tilesHues[$loop->index % count($tilesHues)] }}" @endif>
                        @if ($tilesPicture !== 'none')
                            <span @class(['torgash-tile-pic', 'is-photo' => $tilesIsPhoto, 'is-product' => $tilesIsProduct])>
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
                        @if ($tilesCounts !== null && $tilesCategory)
                            <span class="torgash-tile-count">{{ trans_choice(':count product|:count products', $tilesCounts[$tilesCategory->id] ?? 0) }}</span>
                        @endif
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
