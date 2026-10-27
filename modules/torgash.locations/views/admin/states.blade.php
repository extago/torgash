@extends('admin.layout')

@section('title', __('Regions'))

@section('content')
    @include('torgash.locations::admin.partials.tabs')

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-4">
            <div class="flex justify-between items-start gap-3 flex-wrap">
                <p class="text-sm opacity-70 max-w-3xl">
                    {{ __('Regions group the places of a country. A disabled region hides all of its places from customers.') }}
                </p>
                <a href="{{ route('admin.locations.regions.create', ['country' => $filters['country']]) }}" class="btn btn-primary btn-sm">
                    + {{ __('Add region') }}
                </a>
            </div>

            <form method="GET" class="flex flex-wrap items-center gap-2">
                <label class="input input-sm w-full sm:w-72 gap-2">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="grow" placeholder="{{ __('Name or code') }}">
                </label>
                @if ($countries->count() > 1)
                    <select name="country" class="select select-bordered select-sm w-auto" data-submit-on-change aria-label="{{ __('Country') }}">
                        <option value="">{{ __('Any country') }}</option>
                        @foreach ($countries as $code)
                            <option value="{{ $code }}" @selected($filters['country'] === $code)>
                                {{ ($countryNames[$code][admin_content_locale()] ?? $countryNames[$code]['en'] ?? $code) }}
                            </option>
                        @endforeach
                    </select>
                @endif
                <div class="join">
                    @foreach (['' => __('All'), 'enabled' => __('Enabled'), 'disabled' => __('Disabled')] as $statusKey => $statusLabel)
                        <button type="submit" name="status" value="{{ $statusKey }}"
                                @class(['btn btn-sm join-item', 'btn-active' => $filters['status'] === $statusKey])>
                            {{ $statusLabel }} <span class="opacity-50">{{ $statusCounts[$statusKey] }}</span>
                        </button>
                    @endforeach
                </div>
                @if (array_filter($filters))
                    <a href="{{ route('admin.locations.regions') }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
            </form>

            <form method="POST" action="{{ route('admin.locations.regions.bulk') }}" x-data="{ selected: [], applyToAll: false }">
                @csrf
                @foreach ($filters as $key => $value)
                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                @endforeach

                @include('torgash.locations::admin.partials.bulk-bar', [
                    'total' => $states->total(),
                    'actions' => ['enable' => __('Enable'), 'disable' => __('Disable')],
                    'hint' => __('Tick regions to switch several on or off at once — or all of those found.'),
                ])

                @if ($states->isEmpty())
                    <div class="text-center py-12 opacity-60">
                        <x-icon name="map" size="xl" class="opacity-40" />
                        <p class="mt-2">{{ array_filter($filters) ? __('No regions match this search.') : __('No regions yet — import a pack to fill the registry.') }}</p>
                        @unless (array_filter($filters))
                            <a href="{{ route('admin.locations.import') }}#where-towns-come-from" class="link link-primary text-sm">{{ __('Where the towns come from') }}</a>
                        @endunless
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th class="w-10">
                                        <input type="checkbox" class="checkbox checkbox-sm" aria-label="{{ __('Select all on this page') }}"
                                               @change="selected = $event.target.checked
                                                   ? [...document.querySelectorAll('[name=&quot;ids[]&quot;]')].map(i => i.value)
                                                   : []">
                                    </th>
                                    <th>{{ __('Region') }}</th>
                                    <th>{{ __('Code') }}</th>
                                    <th class="text-right">{{ __('Places') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($states as $state)
                                    @include('torgash.locations::admin.partials.state-row', [
                                        'state' => $state,
                                        'placeCount' => $placeCounts[$state->id] ?? 0,
                                    ])
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </form>

            {{ $states->links() }}
        </div>
    </div>
@endsection
