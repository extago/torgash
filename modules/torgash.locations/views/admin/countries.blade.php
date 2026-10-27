@extends('admin.layout')

@section('title', __('Countries'))

@section('content')
    @include('torgash.locations::admin.partials.tabs')

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    @if ($totals['countries'] === 0)
        <div class="alert alert-info text-sm">
            {{ $pack
                ? __('The country list has not been imported yet.')
                : __('Put countries.csv in the packs folder to import the country list.') }}
        </div>
    @endif

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-4">
            <div class="flex justify-between items-start gap-3 flex-wrap">
                <p class="text-sm opacity-70 max-w-3xl">
                    {{ __('The countries a customer can choose at checkout — only the enabled ones are offered.') }}
                    <span class="opacity-70">{{ __(':enabled of :total available to customers', ['enabled' => $totals['enabled'], 'total' => $totals['countries']]) }}</span>
                </p>
                <div class="flex items-center gap-2">
                    @if ($pack)
                        <form method="POST" action="{{ route('admin.locations.countries.import') }}">
                            @csrf
                            <button class="btn btn-ghost btn-sm gap-1.5">
                                <x-icon name="upload" size="sm" />
                                {{ $totals['countries'] > 0 ? __('Re-import the list') : __('Import the country list') }}
                            </button>
                        </form>
                    @endif
                    <a href="{{ route('admin.locations.countries.create') }}" class="btn btn-primary btn-sm">+ {{ __('Add country') }}</a>
                </div>
            </div>

            <form method="GET" class="flex flex-wrap items-center gap-2">
                <label class="input input-sm w-full sm:w-72 gap-2">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="grow" placeholder="{{ __('Name or code') }}">
                </label>
                <div class="join">
                    @foreach (['' => __('All'), 'enabled' => __('Enabled'), 'disabled' => __('Disabled')] as $statusKey => $statusLabel)
                        <button type="submit" name="status" value="{{ $statusKey }}"
                                @class(['btn btn-sm join-item', 'btn-active' => $filters['status'] === $statusKey])>
                            {{ $statusLabel }} <span class="opacity-50">{{ $statusCounts[$statusKey] }}</span>
                        </button>
                    @endforeach
                </div>
                @if (array_filter($filters))
                    <a href="{{ route('admin.locations.countries') }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
            </form>

            <form method="POST" action="{{ route('admin.locations.countries.bulk') }}" x-data="{ selected: [], applyToAll: false }">
                @csrf
                @foreach ($filters as $key => $value)
                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                @endforeach

                @include('torgash.locations::admin.partials.bulk-bar', [
                    'total' => $countries->total(),
                    'actions' => ['enable' => __('Enable'), 'disable' => __('Disable')],
                    'hint' => __('Tick countries to switch several on or off at once — or all of those found.'),
                ])

                @if ($countries->isEmpty())
                    <div class="text-center py-12 opacity-60">
                        <x-icon name="globe" size="xl" class="opacity-40" />
                        <p class="mt-2">{{ __('No countries match this search.') }}</p>
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
                                    <th>{{ __('Country') }}</th>
                                    <th>{{ __('Code') }}</th>
                                    <th>{{ __('Continent') }}</th>
                                    <th>{{ __('Phone code') }}</th>
                                    <th class="text-end">{{ __('Places') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($countries as $country)
                                    @include('torgash.locations::admin.partials.country-row', [
                                        'country' => $country,
                                        'placeCount' => (int) ($placeCounts[$country->code] ?? 0),
                                    ])
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </form>

            {{ $countries->links() }}
        </div>
    </div>
@endsection
