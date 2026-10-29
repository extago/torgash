@extends('admin.layout')

@section('title', __('Search'))
@section('title_hint', __('The index the storefront searches and a trial box: type what a customer would and see the answer in the same order — and why.'))

@section('content')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @include('torgash.search::admin.header', ['searchTab' => 'engine'])

    {{-- Try it first: that is what this screen is opened for. The same engine
         the storefront asks, with the reading it chose and the rules that
         shaped the answer. --}}
    <section class="card bg-base-100 border border-base-300">
        <div class="card-body gap-4">
            <div>
                <h3 class="font-semibold">{{ __('Try a query') }}</h3>
                <p class="text-xs opacity-60 mt-1">{{ __('The answer the storefront would give, in the same order — and why.') }}</p>
            </div>
            <form method="GET" class="join w-full max-w-2xl">
                <label class="input input-bordered join-item flex-1 gap-2">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" name="q" value="{{ $q }}" class="grow" placeholder="{{ __('What a customer would type') }}" autofocus>
                </label>
                <button class="btn btn-primary join-item">{{ __('Search') }}</button>
            </form>

            @if ($result)
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <span class="badge badge-sm badge-neutral">{{ trans_choice(':count product found|:count products found', $result->total()) }}</span>
                    @if ($result->how === 'layout')
                        <span class="inline-flex items-center gap-1.5 opacity-80"><x-icon name="keyboard" size="sm" class="text-info shrink-0" /> {{ __('read on the other keyboard layout as «:q»', ['q' => $result->readAs]) }}</span>
                    @elseif ($result->how === 'typo')
                        <span class="inline-flex items-center gap-1.5 opacity-80"><x-icon name="spell-check" size="sm" class="text-warning shrink-0" /> {{ __('nothing matched as typed; corrected by the dictionary of the catalogue to «:q»', ['q' => $result->readAs]) }}</span>
                    @elseif ($result->how === 'fallback')
                        <span class="inline-flex items-center gap-1.5 opacity-80"><x-icon name="life-buoy" size="sm" class="text-warning shrink-0" /> {{ __('nothing matched exactly; these are the answers of a fallback source') }}</span>
                    @endif
                    @if ($result->didYouMean)
                        <span class="inline-flex items-center gap-1.5 opacity-80"><x-icon name="lightbulb" size="sm" class="shrink-0" /> {{ __('the window and the page will offer «:q» instead', ['q' => $result->didYouMean]) }}</span>
                    @endif
                </div>
                @if ($fired !== [])
                    <ul class="text-xs flex flex-col gap-1 rounded-box bg-base-200 p-3">
                        @foreach ($fired as $firedRule)
                            <li class="flex items-center gap-1.5"><x-icon name="wand-sparkles" size="sm" class="opacity-60" /> {{ $firedRule }}</li>
                        @endforeach
                    </ul>
                @endif

                @if ($found->isNotEmpty())
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th class="w-10">#</th>
                                    <th class="w-14"></th>
                                    <th>{{ __('Product') }}</th>
                                    <th class="text-end">{{ __('Price') }}</th>
                                    <th>{{ __('Status') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($found as $foundProduct)
                                    <tr class="hover:bg-base-200/50">
                                        <td class="tabular-nums opacity-50">{{ $loop->iteration }}</td>
                                        <td>
                                            @if ($foundImage = $foundProduct->primaryImageUrl(120))
                                                <div class="h-10 w-10 shrink-0 rounded bg-base-200 overflow-hidden">
                                                    <img src="{{ $foundImage }}" alt="" class="h-full w-full object-contain" loading="lazy">
                                                </div>
                                            @else
                                                <div class="h-10 w-10 rounded bg-base-200 flex items-center justify-center"><x-icon name="image" class="opacity-30" /></div>
                                            @endif
                                        </td>
                                        <td class="min-w-0">
                                            <a href="{{ route('admin.catalog.products.edit', $foundProduct) }}" class="link link-hover font-medium">{{ $foundProduct->t('name', admin_content_locale()) ?: '—' }}</a>
                                            <div class="font-mono text-xs opacity-50">{{ $foundProduct->sku }}</div>
                                        </td>
                                        <td class="text-end whitespace-nowrap tabular-nums">{{ $foundProduct->adminPrice() }}</td>
                                        <td class="whitespace-nowrap">
                                            @if ($foundProduct->status !== 'active')
                                                <span class="badge badge-sm badge-ghost">{{ $foundProduct->status === 'hidden' ? __('Hidden') : __('Disabled') }}</span>
                                            @endif
                                            <span @class(['text-sm', 'text-success' => $foundProduct->isOrderable(), 'opacity-60' => ! $foundProduct->isOrderable()])>{{ $foundProduct->stockLabel() }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($result->total() > $found->count())
                        <p class="text-xs opacity-60">{{ __('The first :shown of :total are shown.', ['shown' => $found->count(), 'total' => $result->total()]) }}</p>
                    @endif
                @else
                    <div class="text-center py-8 opacity-60">
                        <x-icon name="search-x" size="xl" class="opacity-40" />
                        <p class="mt-2">{{ __('Nothing found. A synonym or a phrase can answer this query.') }}</p>
                        <div class="flex justify-center gap-2 mt-3">
                            <a href="{{ route('admin.search-engine.rules.index', ['kind' => 'synonyms', 'phrases' => $q]) }}" class="btn btn-sm">+ {{ __('Synonym') }}</a>
                            <a href="{{ route('admin.search-engine.rules.index', ['kind' => 'phrases', 'phrase' => $q]) }}" class="btn btn-sm">+ {{ __('Phrase') }}</a>
                        </div>
                    </div>
                @endif
            @endif
        </div>
    </section>

    <section class="card bg-base-100 border border-base-300">
        <div class="card-body gap-4">
            <div class="flex items-start justify-between gap-3 flex-wrap">
                <div>
                    <h3 class="font-semibold">{{ __('Index') }}</h3>
                    <p class="text-xs opacity-60 mt-1 max-w-3xl">
                        {{ __('A document is rebuilt by itself whenever a product or its characteristics are saved. What it holds: the name in every language, the SKU and the brand first; characteristics marked "Search" in their own form next; then the short description; the description last, if the module\'s settings say so.') }}
                    </p>
                </div>
                <form method="POST" action="{{ route('admin.search-engine.reindex') }}">
                    @csrf
                    <button class="btn btn-sm gap-2"><x-icon name="refresh-cw" size="sm" /> {{ __('Rebuild the index') }}</button>
                </form>
            </div>

            <div class="grid sm:grid-cols-3 gap-3">
                <div @class(['rounded-box border p-4', 'border-base-300' => $indexed >= $products, 'border-warning bg-warning/5' => $indexed < $products])>
                    <div class="text-xs opacity-60">{{ __('Products in the index') }}</div>
                    <div class="text-2xl font-semibold tabular-nums">{{ number_format($indexed) }} <span class="text-base font-normal opacity-50">/ {{ number_format($products) }}</span></div>
                    @if ($indexed < $products)
                        <div class="text-xs text-warning mt-1">{{ __('Products without one are not found. Rebuild the index.') }}</div>
                    @endif
                </div>
                <div @class(['rounded-box border p-4', 'border-base-300' => ! ($words === 0 && $indexed > 0), 'border-warning bg-warning/5' => $words === 0 && $indexed > 0])>
                    <div class="text-xs opacity-60">{{ __('Words in the typo dictionary') }}</div>
                    <div class="text-2xl font-semibold tabular-nums">{{ number_format($words) }}</div>
                    @if ($words === 0 && $indexed > 0)
                        <div class="text-xs text-warning mt-1">{{ __('It is empty — typos are not corrected. Rebuild the index.') }}</div>
                    @endif
                </div>
                <div class="rounded-box border border-base-300 p-4">
                    <div class="text-xs opacity-60">{{ __('Sources asked:') }}</div>
                    <div class="flex flex-wrap gap-1 mt-2">
                        @foreach ($sources as $key => $source)
                            <span class="badge badge-ghost badge-sm font-mono">{{ $key }}{{ $source['fallback'] ? ' · '.__('fallback') : '' }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
