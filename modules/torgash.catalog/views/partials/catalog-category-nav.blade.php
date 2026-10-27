{{-- The category list beside the catalogue. Rows come worked out from
     Support\CategoryNav — whole tree or the visitor's branch, as the shop set
     it — so this only draws: "back" rows with an arrow, the current one with
     a bar on the CARD's edge (it reaches out over the padding to sit on
     the border, which is what makes it read as a marker and not a stray line), and the count on the right when there is one. On a
     phone the sheet hides it (`torgash-sheet-hidden`): the menu drawer and
     the subcategory strip already lead there. A category with nothing in it
     is still listed — the shop switched it on, and it may be filling — but
     quiet, and without a "0" that reads as a dead end. --}}
<nav class="relative bg-base-100 border border-base-300 rounded-box p-3 torgash-sheet-hidden" aria-label="{{ __('Categories') }}">
    <div class="text-lg font-bold px-2 pt-1 pb-2">{{ __('Categories') }}</div>
    <ul class="flex flex-col text-sm">
        @foreach ($navRows as $navRow)
            @php
                $navCategory = $navRow['category'];
                $navKind = $navRow['kind'];
                $navUrl = $navCategory ? route('catalog.category', $navCategory->slug) : route('catalog.index');
                $navName = $navCategory ? $navCategory->t('name') : __('All products');
                $navEmpty = $navRow['count'] === 0 && $navKind === 'item';
            @endphp
            <li>
                <a href="{{ $navUrl }}"
                   @class([
                       'group relative flex items-center gap-2 min-h-9 py-1.5 pr-2 rounded-field hover:bg-base-200',
                       'font-bold' => $navKind === 'current',
                       'text-base-content/70 hover:text-base-content' => $navKind === 'up',
                       'font-medium' => $navKind === 'item' && $navRow['depth'] === 0 && ! $navEmpty,
                       'text-base-content/80 hover:text-base-content' => $navKind === 'item' && $navRow['depth'] > 0 && ! $navEmpty,
                       'text-base-content/45 hover:text-base-content' => $navEmpty,
                   ])
                   style="padding-left: {{ 0.5 + $navRow['depth'] * 0.875 }}rem"
                   @if ($navKind === 'current') aria-current="page" @endif
                   @if ($navKind === 'up') title="{{ __('Up to :category', ['category' => $navName]) }}" @endif>
                    @if ($navKind === 'current')
                        <span class="absolute -left-3.25 inset-y-1 w-[3px] rounded-r-full bg-primary" aria-hidden="true"></span>
                    @endif
                    @if ($navKind === 'up')
                        <x-icon name="arrow-left" size="sm" class="shrink-0 opacity-60" />
                    @endif
                    <span class="grow min-w-0 leading-snug">{{ $navName }}</span>
                    @if ($navRow['count'] !== null && ! $navEmpty)
                        <span @class([
                            'shrink-0 min-w-6 px-1.5 rounded-full text-xs text-center tabular-nums leading-5',
                            'font-bold text-base-content' => $navKind === 'current',
                            'bg-base-200 text-base-content/60 group-hover:bg-base-100' => $navKind !== 'current',
                        ])>{{ $navRow['count'] }}</span>
                    @endif
                </a>
            </li>
        @endforeach
    </ul>
</nav>
