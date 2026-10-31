{{--
    The comparison page (PLAN §35.4, the owner's notes of 2026-09-23).

    The head is the wishlist's: the title, then one card holding the controls —
    a tab per group, "differences only" as ONE switch (a GET form its switch
    submits; "Show" does it without JavaScript) and "Clear" as a form of its
    own BESIDE it, never inside.

    The products are the COLUMNS of one table, so a photograph and a price
    always stand over their own values. The first column (the names of the
    characteristics) stays put while the table scrolls sideways; once the
    products' own row has scrolled away, a thin strip of their names sticks
    under the site header and follows the table sideways (Alpine: an
    IntersectionObserver and a scrollLeft copy — nothing else). Rows that
    differ are marked even when everything is shown.

    Widths are two variables (--cmp-label, --cmp-col), read by the table AND
    the strip, which is what keeps the strip over its columns.
--}}
@extends('layouts.storefront')

@section('content')
    <x-breadcrumbs />
    <h1 class="text-2xl font-bold mb-4">{{ __('Comparison') }}</h1>

    @if ($total === 0)
        <div class="card bg-base-100 border border-base-300/60 shadow-sm">
            <div class="card-body items-center text-center gap-3 py-12">
                <x-icon name="square-kanban" size="xl" class="opacity-30" />
                <p class="opacity-70">{{ __('Nothing to compare yet. Press the comparison button on a product to put it here.') }}</p>
                <a href="{{ route('catalog.index') }}" class="btn btn-primary">{{ __('Browse the catalogue') }}</a>
            </div>
        </div>
    @else
        @php $compareColumns = $products->count(); @endphp
        <div class="flex flex-col gap-4">
            <div class="card bg-base-100 border border-base-300/60 shadow-sm">
                <div class="card-body gap-4 flex-row flex-wrap items-center">
                    {{-- A tab per group — ONLY one category is compared at a time. --}}
                    <nav class="flex flex-wrap gap-2 flex-1 min-w-0" aria-label="{{ __('Categories') }}">
                        @foreach ($tabs as $compareTab)
                            <a href="{{ route('compare.index', array_filter(['group' => $compareTab['id'], 'diff' => $diff ? 1 : null])) }}"
                               @class([
                                   'btn btn-sm btn-touch gap-2',
                                   'btn-primary' => $compareTab['id'] === $active,
                                   'btn-ghost bg-base-200' => $compareTab['id'] !== $active,
                               ])
                               @if ($compareTab['id'] === $active) aria-current="page" @endif>
                                {{ $compareTab['name'] }}
                                <span class="opacity-70 tabular-nums">{{ $compareTab['count'] }}</span>
                            </a>
                        @endforeach
                    </nav>
                    <form method="GET" action="{{ route('compare.index') }}" class="flex items-center gap-3" x-data>
                        <input type="hidden" name="group" value="{{ $active }}">
                        <label @class(['label gap-2', 'cursor-pointer' => $compareColumns > 1])>
                            <input type="checkbox" name="diff" value="1" class="toggle toggle-primary"
                                   @checked($diff) @disabled($compareColumns < 2) x-on:change="$el.form.requestSubmit()">
                            <span class="text-sm text-base-content">{{ __('Differences only') }}</span>
                        </label>
                        <noscript><button class="btn btn-sm btn-touch btn-outline">{{ __('Show') }}</button></noscript>
                    </form>
                    <form method="POST" action="{{ route('compare.clear') }}" data-confirm="{{ __('Remove everything from the comparison?') }}">
                        @csrf
                        <button class="btn btn-error btn-soft gap-2"><x-icon name="trash-2" /> {{ __('Clear the comparison') }}</button>
                    </form>
                </div>
            </div>

            <div class="card bg-base-100 border border-base-300/60 shadow-sm [--cmp-label:7.5rem] [--cmp-col:11rem] md:[--cmp-label:13rem] md:[--cmp-col:15rem]"
                 style="--cmp-min: calc(var(--cmp-label) + {{ $compareColumns }} * var(--cmp-col));"
                 x-data="{ stuck: false }"
                 x-init="new IntersectionObserver(([e]) => stuck = !e.isIntersecting && e.boundingClientRect.top < 0,
                            { rootMargin: '-' + (document.querySelector('header')?.offsetHeight || 0) + 'px 0px 0px 0px' }).observe($refs.head)">
                {{-- The sticky strip: takes no room (-mb equal to its height)
                     and shows only once the products' row has gone by. --}}
                <div class="sticky z-20 h-14 -mb-14 top-[var(--torgash-header-h)] pointer-events-none" aria-hidden="true">
                    <div x-show="stuck" x-cloak x-transition.opacity.duration.150ms x-ref="strip"
                         class="overflow-hidden bg-base-100/95 backdrop-blur border-b border-base-300 shadow-sm pointer-events-auto">
                        <div class="flex w-full min-w-[var(--cmp-min)] h-14">
                            <div class="sticky left-0 z-10 w-[var(--cmp-label)] shrink-0 bg-base-100 px-3 flex items-center text-xs text-base-content/60">
                                {{ trans_choice(':count product|:count products', $compareColumns) }}
                            </div>
                            @foreach ($products as $compareProduct)
                                <a href="{{ route('catalog.product', $compareProduct->slug) }}" tabindex="-1"
                                   class="flex-1 min-w-[var(--cmp-col)] basis-0 px-3 flex items-center gap-2 border-l border-base-300">
                                    <span class="w-9 h-9 shrink-0 rounded bg-base-200 overflow-hidden flex items-center justify-center">
                                        @if ($compareStripImage = $compareProduct->primaryImageUrl(96))
                                            <img src="{{ $compareStripImage }}" alt="" class="object-contain w-full h-full">
                                        @endif
                                    </span>
                                    <span class="text-sm leading-tight line-clamp-2">{{ $compareProduct->t('name') }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto rounded-box" x-on:scroll.passive="$refs.strip.scrollLeft = $el.scrollLeft">
                    <table class="table-fixed border-collapse w-full min-w-[var(--cmp-min)] text-sm">
                        <colgroup>
                            <col class="w-[var(--cmp-label)]">
                            @foreach ($products as $compareProduct)
                                <col>
                            @endforeach
                        </colgroup>
                        <thead x-ref="head">
                            <tr>
                                <th class="sticky left-0 z-10 bg-base-100 align-top p-3 text-left font-normal text-xs text-base-content/60">
                                    @if ($diff)
                                        {{ trans_choice(':count difference|:count differences', $differing) }}
                                    @endif
                                </th>
                                @php $compareShown = app(\Torgash\Catalog\Pricing\ShownPrice::class); @endphp
                                @foreach ($products as $compareProduct)
                                    @php $compareFrom = $compareShown->isFrom($compareProduct); @endphp
                                    <th class="relative align-top p-4 border-l border-base-300 font-normal text-left">
                                        <form method="POST" action="{{ route('compare.remove', $compareProduct->slug) }}" class="absolute top-2 right-2 z-[1]">
                                            @csrf
                                            <button class="btn btn-ghost btn-sm btn-touch btn-circle" title="{{ __('Remove from comparison') }}"
                                                    aria-label="{{ __('Remove from comparison') }}: {{ $compareProduct->t('name') }}">
                                                <x-icon name="x" size="lg" />
                                            </button>
                                        </form>
                                        <div class="flex flex-col gap-2 h-full">
                                            <a href="{{ route('catalog.product', $compareProduct->slug) }}"
                                               class="block aspect-square w-full max-w-40 mx-auto rounded bg-base-200 overflow-hidden">
                                                @if ($compareImage = $compareProduct->primaryImageUrl(400))
                                                    <img src="{{ $compareImage }}" alt="" class="object-contain w-full h-full" loading="lazy">
                                                @else
                                                    <span class="flex items-center justify-center h-full"><x-icon name="image" size="xl" class="opacity-20" /></span>
                                                @endif
                                            </a>
                                            <a href="{{ route('catalog.product', $compareProduct->slug) }}" class="link link-hover leading-snug line-clamp-3">{{ $compareProduct->t('name') }}</a>
                                            {!! hook_render('catalog.product.card_meta', ['product' => $compareProduct]) !!}
                                            <div class="flex items-end justify-between gap-2 mt-auto pt-1">
                                                <div class="flex flex-col min-w-0">
                                                    @if (! $compareFrom && $compareProduct->discountPercent() > 0)
                                                        <span class="text-xs torgash-old-price">{{ format_price($compareProduct->oldPrice()) }}</span>
                                                    @endif
                                                    <span class="text-lg font-bold leading-tight torgash-price">{{ $compareShown->format($compareProduct) }}</span>
                                                    {!! hook_render('catalog.product.card_price_extra', ['product' => $compareProduct]) !!}
                                                    <span @class([
                                                        'text-xs mt-0.5',
                                                        'torgash-availability torgash-availability-'.$compareProduct->effectiveAvailabilityKey(),
                                                        'torgash-in-stock' => $compareProduct->isOrderable(),
                                                        'torgash-out-of-stock' => ! $compareProduct->isOrderable(),
                                                    ])>{{ $compareProduct->stockLabel() }}</span>
                                                </div>
                                                {{-- The basket's button as a card draws it; a product
                                                     that cannot be bought gets none, and its stock line
                                                     above already says why. --}}
                                                {!! hook_render('catalog.product.card_actions', ['product' => $compareProduct, 'place' => 'compare']) !!}
                                            </div>
                                        </div>
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($rows as $compareRow)
                                @if ($compareRow['type'] === 'section')
                                    <tr>
                                        <th colspan="{{ $compareColumns + 1 }}" class="bg-base-200/60 border-t border-base-300 px-3 py-2 text-left font-semibold">
                                            <span class="sticky left-3">{{ $compareRow['label'] }}</span>
                                        </th>
                                    </tr>
                                @else
                                    <tr class="border-t border-base-300 hover:bg-base-200/40">
                                        <th scope="row" @class([
                                            'sticky left-0 z-10 bg-base-100 p-3 text-left align-top font-normal text-base-content/70 break-words',
                                            // A difference is marked by a bar on its name, in every mode.
                                            'shadow-[inset_3px_0_0_var(--color-primary)] text-base-content! font-medium' => $compareRow['differs'],
                                        ])>{{ $compareRow['label'] }}</th>
                                        @foreach ($products as $compareProduct)
                                            @php $compareValue = $compareRow['values'][$compareProduct->id] ?? null; @endphp
                                            <td class="p-3 align-top border-l border-base-300 break-words">
                                                @if ($compareValue === null)
                                                    <span class="opacity-30">—</span>
                                                @else
                                                    {{ $compareValue }}
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endif
                            @empty
                                <tr class="border-t border-base-300">
                                    <td colspan="{{ $compareColumns + 1 }}" class="p-6 text-center opacity-60">
                                        {{ $diff ? __('These products say the same about everything.') : __('These products have no characteristics to compare.') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
@endsection
