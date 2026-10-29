@extends('admin.layout')

@section('title', __('Search statistics'))
@section('title_hint', __('What customers searched for in the period, how often, and what they clicked. A search that found nothing is a synonym or a phrase waiting to be written.'))

@section('content')
    @include('torgash.search::admin.header', ['searchTab' => 'stats'])

    @unless ($enabled)
        <div class="alert alert-warning">{{ __('Counting is switched off in the settings — nothing new is being added.') }}</div>
    @endunless

    {{-- The period as one row of tabs over the figures (app.css, `admin-tabs`). --}}
    <nav class="admin-tabs" aria-label="{{ __('Period') }}">
        @foreach (\Torgash\Search\Http\Admin\StatsController::PERIODS as $statsPeriod)
            <a href="{{ route('admin.search-engine.stats', ['days' => $statsPeriod]) }}" @class(['admin-tab', 'is-active' => $statsPeriod === $days])>
                {{ trans_choice(':count day|:count days', $statsPeriod) }}
            </a>
        @endforeach
    </nav>

    {{-- Three headline numbers: stat tiles, not a chart — each is one figure. --}}
    @php
        $statsZeroShare = $totals->searches > 0 ? round(100 * $totals->zero / $totals->searches) : 0;
        $statsClickShare = $totals->searches > 0 ? round(100 * $totals->clicks / $totals->searches) : 0;
    @endphp
    <div class="stats stats-vertical md:stats-horizontal border border-base-300/60 bg-base-100 shadow-sm w-full">
        <div class="stat">
            <div class="stat-title">{{ __('Searches') }}</div>
            <div class="stat-value text-2xl">{{ number_format($totals->searches) }}</div>
            <div class="stat-desc">{{ trans_choice(':count different query|:count different queries', $totals->queries) }}</div>
        </div>
        <div class="stat">
            <div class="stat-title">{{ __('Found nothing') }}</div>
            <div class="stat-value text-2xl">{{ $statsZeroShare }}%</div>
            <div class="stat-desc">{{ trans_choice(':count search|:count searches', $totals->zero) }}</div>
        </div>
        <div class="stat">
            <div class="stat-title">{{ __('Products opened from search') }}</div>
            <div class="stat-value text-2xl">{{ number_format($totals->clicks) }}</div>
            <div class="stat-desc">{{ __(':percent% of searches', ['percent' => $statsClickShare]) }}</div>
        </div>
    </div>

    {{-- The page's reason to exist: what to stock, or what to rename. --}}
    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-3">
            <h3 class="font-semibold">{{ __('Searched and not found') }}</h3>
            <p class="text-xs opacity-60">{{ __('Each is a customer who left with nothing. Either the shop does not have it — or has it under another name, and a synonym or a phrase answers it.') }}</p>
            @if ($missing->isEmpty())
                <p class="text-sm opacity-60">{{ __('Nothing — every search found something.') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="table table-sm">
                        <thead>
                            <tr><th>{{ __('Query') }}</th><th class="text-right">{{ __('Found nothing') }}</th><th class="text-right">{{ __('Searches') }}</th><th class="text-right">{{ __('Finds now') }}</th><th></th></tr>
                        </thead>
                        <tbody>
                            @foreach ($missing as $statsRow)
                                <tr>
                                    <td><a href="{{ route('admin.search-engine.index', ['q' => $statsRow->query]) }}" class="link link-hover">{{ $statsRow->query }}</a></td>
                                    <td class="text-right tabular-nums">{{ $statsRow->zero }}</td>
                                    <td class="text-right tabular-nums">{{ $statsRow->searches }}</td>
                                    <td class="text-right tabular-nums">
                                        @if ($statsRow->now > 0)
                                            <span class="inline-flex items-center gap-1"><x-icon name="check" size="sm" /> {{ $statsRow->now }}</span>
                                        @else
                                            <span class="opacity-50">0</span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap text-right">
                                        <a href="{{ route('admin.search-engine.rules.index', ['kind' => 'synonyms', 'phrases' => $statsRow->query]) }}" class="btn btn-ghost btn-sm">+ {{ __('Synonym') }}</a>
                                        <a href="{{ route('admin.search-engine.rules.index', ['kind' => 'phrases', 'phrase' => $statsRow->query]) }}" class="btn btn-ghost btn-sm">+ {{ __('Phrase') }}</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <div class="grid lg:grid-cols-[2fr_1fr] gap-4 items-start">
        <div class="card bg-base-100 border border-base-300">
            <div class="card-body gap-3">
                <h3 class="font-semibold">{{ __('Most searched') }}</h3>
                @if ($top->isEmpty())
                    <p class="text-sm opacity-60">{{ __('Nothing searched in this period yet.') }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="table table-sm">
                            <thead>
                                <tr><th>{{ __('Query') }}</th><th class="text-right">{{ __('Searches') }}</th><th class="text-right">{{ __('Found') }}</th><th class="text-right">{{ __('Opened') }}</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($top as $statsRow)
                                    <tr>
                                        <td><a href="{{ route('admin.search-engine.index', ['q' => $statsRow->query]) }}" class="link link-hover">{{ $statsRow->query }}</a></td>
                                        <td class="text-right tabular-nums">{{ $statsRow->searches }}</td>
                                        <td class="text-right tabular-nums">{{ $statsRow->found }}</td>
                                        <td class="text-right tabular-nums">
                                            {{ $statsRow->clicks }}
                                            @if ($statsRow->searches > 0)
                                                <span class="opacity-50 text-xs">({{ round(100 * $statsRow->clicks / $statsRow->searches) }}%)</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        <div class="card bg-base-100 border border-base-300">
            <div class="card-body gap-3">
                <h3 class="font-semibold">{{ __('Chosen from search') }}</h3>
                <p class="text-xs opacity-60">{{ __('Opened from the answers — and lifted for the query they were chosen from, if the settings say so.') }}</p>
                @if ($chosen->isEmpty())
                    <p class="text-sm opacity-60">{{ __('Nothing yet.') }}</p>
                @else
                    <ol class="list-decimal list-inside text-sm space-y-1">
                        @foreach ($chosen as $statsChosen)
                            <li>
                                <a href="{{ route('admin.catalog.products.edit', $statsChosen['product']) }}" class="link link-hover">{{ $statsChosen['product']->t('name') }}</a>
                                <span class="opacity-50 tabular-nums">— {{ $statsChosen['clicks'] }}</span>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </div>
        </div>
    </div>
@endsection
