{{-- The live window's contents. Every link that can be chosen with the
     keyboard is role="option" (the script moves through exactly those); the
     buy buttons are not options — Tab reaches them, arrows do not. --}}
{{-- The window does not scroll; the product list does (flex-1, min-h-0),
     and everything else is shrink-0 — so "All results" and the categories
     stay put while the list scrolls and grows. --}}
<div class="flex flex-col min-h-0 text-sm [&>*:not([data-live-search-products])]:shrink-0">
    @if ($result->how === 'layout' && $result->readAs)
        <div class="px-3 py-2 text-xs bg-info/10 flex items-center gap-2">
            <x-icon name="keyboard" size="sm" />
            {{ __('Showing results for «:q»', ['q' => $result->readAs]) }}
        </div>
    @elseif ($result->how === 'typo' && $result->readAs)
        <div class="px-3 py-2 text-xs bg-info/10 flex items-center gap-2">
            <x-icon name="spell-check" size="sm" />
            {{ __('Showing results for «:q»', ['q' => $result->readAs]) }}
        </div>
    @elseif ($result->how === 'fallback' && $products->isNotEmpty())
        <div class="px-3 py-2 text-xs bg-info/10 flex items-center gap-2">
            <x-icon name="spell-check" size="sm" />
            {{ __('Nothing matched exactly — the closest answers') }}
        </div>
    @endif

    {{-- Found as typed, but a word is unknown and its correction finds more:
         offered, never substituted — the customer may have meant exactly that. --}}
    @if ($result->didYouMean)
        <a href="{{ route('catalog.search', ['q' => $result->didYouMean, 'scope' => $scope]) }}" role="option" class="px-3 py-2 text-xs bg-info/10 flex items-center gap-2 hover:bg-info/20 aria-selected:bg-info/20">
            <x-icon name="spell-check" size="sm" />
            <span>{{ __('Did you mean') }} <span class="font-semibold underline">{{ $result->didYouMean }}</span>?</span>
        </a>
    @endif

    @if ($phrases !== [])
        <ul class="flex flex-col py-1 border-b border-base-300">
            @foreach ($phrases as $suggestedPhrase)
                <li>
                    <a href="{{ route('catalog.search', ['q' => $suggestedPhrase, 'scope' => $scope]) }}" role="option" class="flex items-center gap-2 px-3 py-1.5 hover:bg-base-200 aria-selected:bg-base-200">
                        <x-icon name="search" size="sm" class="opacity-40" />
                        <span>{{ \Torgash\Search\Highlight::html($suggestedPhrase, $result->query->words) }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($products->isEmpty() && $categories->isEmpty() && $brands->isEmpty() && $sections === [] && $phrases === [])
        <div class="px-4 py-6 text-center opacity-70">
            {{ __('Nothing found for «:q»', ['q' => $q]) }}
        </div>
    @endif

    @if ($brands->isNotEmpty())
        <div class="px-3 pt-2 pb-1 text-xs uppercase tracking-wide opacity-50">{{ __('Brands') }}</div>
        <div class="flex flex-wrap gap-1.5 px-3 pb-2">
            @foreach ($brands as $brand)
                <a href="{{ route('catalog.brands.show', $brand['slug']) }}" role="option" class="badge badge-outline hover:badge-primary aria-selected:badge-primary"><span>{{ \Torgash\Search\Highlight::html($brand['name'], $marked) }}</span></a>
            @endforeach
        </div>
    @endif

    @if ($products->isNotEmpty())
        <div class="px-3 pt-2 pb-1 text-xs uppercase tracking-wide opacity-50">{{ __('Products') }}</div>
        <ul class="flex flex-col flex-1 min-h-24 overflow-y-auto overscroll-contain" data-live-search-products>
            @include('torgash.search::suggest.products', [
                'products' => $products, 'show' => $show, 'inCart' => $inCart, 'marked' => $marked, 'more' => $more,
            ])
        </ul>
    @endif

    @if ($categories->isNotEmpty())
        <div class="px-3 pt-2 pb-1 text-xs uppercase tracking-wide opacity-50">{{ __('Narrow to a category') }}</div>
        <div class="flex flex-wrap gap-1.5 px-3 pb-2">
            @foreach ($categories as $row)
                <a href="{{ route('catalog.search', ['q' => $q, 'in' => $row['category']->slug]) }}" role="option" class="badge badge-ghost gap-1 hover:badge-primary aria-selected:badge-primary">
                    <span>{{ \Torgash\Search\Highlight::html($row['category']->t('name'), $marked) }}</span> <span class="opacity-60">{{ $row['count'] }}</span>
                </a>
            @endforeach
        </div>
    @endif

    @foreach ($sections as $section)
        <div class="px-3 pt-2 pb-1 text-xs uppercase tracking-wide opacity-50">{{ $section['title'] }}</div>
        <ul class="flex flex-col pb-1">
            @foreach ($section['items'] as $item)
                <li>
                    <a href="{{ $item['url'] }}" role="option" class="flex items-baseline gap-2 px-3 py-1 hover:bg-base-200 aria-selected:bg-base-200">
                        <span>{{ \Torgash\Search\Highlight::html($item['label'], $marked) }}</span>
                        @if ($item['hint'] ?? null)<span class="text-xs opacity-50 truncate">{{ $item['hint'] }}</span>@endif
                    </a>
                </li>
            @endforeach
        </ul>
    @endforeach

    @if ($result->total() > 0 || $sections !== [])
        <a href="{{ route('catalog.search', ['q' => $q, 'scope' => $scope]) }}" role="option" class="px-3 py-2 border-t border-base-300 text-primary hover:bg-base-200 aria-selected:bg-base-200 flex items-center justify-between">
            <span>{{ __('All results') }}</span>
            @if ($result->total() > 0)
                <span class="badge badge-primary badge-sm">{{ $result->total() }}</span>
            @endif
        </a>
    @endif
</div>
