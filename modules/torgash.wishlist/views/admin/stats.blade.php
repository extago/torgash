@extends('admin.layout')

@section('title', __('Wishlists'))

@section('content')
    <div class="flex flex-wrap items-center gap-3">
        <h1 class="text-xl font-bold flex-1">{{ __('Wishlists') }}</h1>
        {{-- The period bounds "added" — the second figure and the "Saved
             most" table; everything else is what the lists hold NOW. --}}
        <div class="join">
            @foreach (\Torgash\Wishlist\Http\Admin\StatsController::PERIODS as $statsPeriod)
                <a href="{{ route('admin.wishlist.stats', ['days' => $statsPeriod]) }}" @class(['btn btn-sm join-item', 'btn-active' => $statsPeriod === $days])>
                    {{ trans_choice(':count day|:count days', $statsPeriod) }}
                </a>
            @endforeach
        </div>
        @can('settings.manage')
            <a href="{{ route('admin.settings.edit', 'torgash.wishlist') }}" class="btn btn-sm btn-ghost gap-2"><x-icon name="settings" /> {{ __('Settings') }}</a>
        @endcan
    </div>

    <p class="text-sm opacity-70 max-w-3xl -mt-2">
        {{ __('What customers put aside for later: which products are wanted, and which of them the shop cannot sell right now. Products and numbers only — whose list it is never appears here.') }}
    </p>

    <div class="grid sm:grid-cols-2 xl:grid-cols-4 gap-3">
        <div class="rounded-box border border-base-300 bg-base-100 p-4">
            <div class="flex items-center gap-2 text-xs opacity-60"><x-icon name="heart" size="sm" /> {{ __('Lists') }}</div>
            <div class="text-2xl font-semibold tabular-nums mt-1">{{ number_format($lists->lists) }}</div>
            <div class="text-xs opacity-60">{{ __(':accounts with an account, :guests guests', ['accounts' => $lists->accounts, 'guests' => $lists->lists - $lists->accounts]) }}</div>
        </div>
        <div class="rounded-box border border-base-300 bg-base-100 p-4">
            <div class="flex items-center gap-2 text-xs opacity-60"><x-icon name="package" size="sm" /> {{ __('Products saved') }}</div>
            <div class="text-2xl font-semibold tabular-nums mt-1">{{ number_format($items->items) }}</div>
            <div class="text-xs opacity-60">{{ trans_choice(':count added in :days days|:count added in :days days', $items->added, ['days' => $days]) }}</div>
        </div>
        <div class="rounded-box border border-base-300 bg-base-100 p-4">
            <div class="flex items-center gap-2 text-xs opacity-60"><x-icon name="mail" size="sm" /> {{ __('Asked for letters') }}</div>
            <div class="text-2xl font-semibold tabular-nums mt-1">{{ number_format(max($lists->price_letters, $lists->stock_letters)) }}</div>
            <div class="text-xs opacity-60">{{ __('price drops :price, returns :stock', ['price' => $lists->price_letters, 'stock' => $lists->stock_letters]) }}</div>
        </div>
        <div class="rounded-box border border-base-300 bg-base-100 p-4">
            <div class="flex items-center gap-2 text-xs opacity-60"><x-icon name="share-2" size="sm" /> {{ __('Shared by a link') }}</div>
            <div class="text-2xl font-semibold tabular-nums mt-1">{{ number_format($lists->shared) }}</div>
        </div>
    </div>

    {{-- The table that says what to order in. --}}
    <section @class(['card bg-base-100 border', 'border-warning/60' => $unavailable->isNotEmpty(), 'border-base-300' => $unavailable->isEmpty()])>
        <div class="card-body gap-3">
            <div>
                <h3 class="font-semibold flex items-center gap-2">
                    @if ($unavailable->isNotEmpty())<x-icon name="triangle-alert" class="text-warning" />@endif
                    {{ __('Wanted, and cannot be bought') }}
                </h3>
                <p class="text-xs opacity-60 mt-1">{{ __('Saved in lists and out of stock or not sold now — demand the shop is not meeting. "Waiting" asked for a letter when it is back.') }}</p>
            </div>
            @if ($unavailable->isEmpty())
                <div class="flex items-center gap-2 text-sm opacity-70 py-2"><x-icon name="circle-check" class="text-success" /> {{ __('Nothing — everything that is wished for can be bought.') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th></th>
                                <th>{{ __('Product') }}</th>
                                <th class="text-end">{{ __('Price') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th class="text-end">{{ __('In lists') }}</th>
                                <th class="text-end">{{ __('Waiting for it') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($unavailable as $statsRow)
                                <tr class="hover:bg-base-200/50">
                                    @include('torgash.wishlist::admin._product', ['wishProduct' => $statsRow['product']])
                                    <td class="text-end tabular-nums font-semibold">{{ $statsRow['lists'] }}</td>
                                    <td class="text-end tabular-nums">
                                        @if ($statsRow['waiting'] > 0)
                                            <span class="badge badge-sm badge-warning gap-1"><x-icon name="bell" size="sm" /> {{ $statsRow['waiting'] }}</span>
                                        @else
                                            <span class="opacity-40">0</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </section>

    <section class="card bg-base-100 border border-base-300">
        <div class="card-body gap-3">
            <div>
                <h3 class="font-semibold">{{ __('Saved most') }}</h3>
                <p class="text-xs opacity-60 mt-1">{{ trans_choice('Added to lists in the last :count day, beside how many lists hold it now.|Added to lists in the last :count days, beside how many lists hold it now.', $days) }}</p>
            </div>
            @if ($top->isEmpty())
                <div class="text-center py-10 opacity-60">
                    <x-icon name="heart" size="xl" class="opacity-40" />
                    <p class="mt-2">{{ __('Nothing was saved in this period.') }}</p>
                </div>
            @else
                @php $topMax = max(1, (int) $top->max('added')); @endphp
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="w-10">#</th>
                                <th></th>
                                <th>{{ __('Product') }}</th>
                                <th class="text-end">{{ __('Price') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th class="w-48">{{ __('Added in the period') }}</th>
                                <th class="text-end">{{ __('In lists now') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($top as $statsRow)
                                <tr class="hover:bg-base-200/50">
                                    <td class="tabular-nums opacity-50">{{ $loop->iteration }}</td>
                                    @include('torgash.wishlist::admin._product', ['wishProduct' => $statsRow['product']])
                                    <td>
                                        <div class="flex items-center gap-2">
                                            <progress class="progress progress-primary w-28" value="{{ $statsRow['added'] }}" max="{{ $topMax }}"></progress>
                                            <span class="tabular-nums text-sm">{{ $statsRow['added'] }}</span>
                                        </div>
                                    </td>
                                    <td class="text-end tabular-nums">{{ $statsRow['now'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </section>
@endsection
