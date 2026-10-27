@extends('admin.layout')

@section('title', __('Places'))

@php
    // Which types the customer is never shown. Read once here rather than per
    // row: the list is fifty rows long and the answer is the same for all of
    // them.
    $hiddenTypes = array_filter((array) setting('torgash.locations', 'hidden_types', []));
    $statusTabs = [
        '' => __('All'),
        'enabled' => __('Enabled'),
        'disabled' => __('Disabled'),
        'edited' => \Illuminate\Support\Str::ucfirst(__('edited')),
        'manual' => \Illuminate\Support\Str::ucfirst(__('added by hand')),
        'missing' => \Illuminate\Support\Str::ucfirst(__('not in the pack')),
    ];
@endphp

@section('content')
    @include('torgash.locations::admin.partials.tabs')

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-4">
            <div class="flex justify-between items-start gap-3 flex-wrap">
                <p class="text-sm opacity-70 max-w-3xl">
                    {{ __('Towns and villages a customer picks at checkout and in the town window. Rows you edit or add by hand are yours: the next pack import leaves them alone.') }}
                    <span class="opacity-70">{{ __(':total places', ['total' => number_format($totals['places'], 0, '.', ' ')]) }}</span>
                </p>
                <div class="flex items-center gap-2">
                    <a href="{{ route('admin.locations.import') }}" class="btn btn-ghost btn-sm gap-1.5">
                        <x-icon name="upload" size="sm" /> {{ __('Import a pack') }}
                    </a>
                    <a href="{{ route('admin.locations.create') }}" class="btn btn-primary btn-sm">+ {{ __('Add place') }}</a>
                </div>
            </div>

            <form method="GET" class="flex flex-col gap-3">
                <div class="flex flex-wrap items-center gap-2">
                    <label class="input input-sm w-full sm:w-72 gap-2">
                        <x-icon name="search" size="sm" class="opacity-50" />
                        <input type="search" name="q" value="{{ $filters['q'] }}" class="grow" placeholder="{{ __('Name or an old name') }}">
                    </label>

                    @if ($countries->count() > 1)
                        {{-- Only worth the space once a second pack is imported. --}}
                        <select name="country" class="select select-bordered select-sm w-auto" data-submit-on-change aria-label="{{ __('Country') }}">
                            <option value="">{{ __('Any country') }}</option>
                            @foreach ($countries as $country)
                                <option value="{{ $country }}" @selected($filters['country'] === $country)>{{ $country }}</option>
                            @endforeach
                        </select>
                    @endif

                    <select name="state" class="select select-bordered select-sm w-auto max-w-64" data-submit-on-change aria-label="{{ __('Region') }}">
                        <option value="">{{ __('Any region') }}</option>
                        @foreach ($states as $state)
                            <option value="{{ $state->id }}" @selected($filters['state'] == $state->id)>
                                {{ $filters['country'] === '' ? $state->country.' · ' : '' }}{{ $state->t('name', admin_content_locale()) }}
                            </option>
                        @endforeach
                    </select>

                    <select name="type" class="select select-bordered select-sm w-auto" data-submit-on-change aria-label="{{ __('Type') }}">
                        <option value="">{{ __('Any type') }}</option>
                        @foreach ($types as $code => $label)
                            <option value="{{ $code }}" @selected($filters['type'] === $code)>
                                {{ __($label) }}{{ in_array($code, $hiddenTypes, true) ? ' · '.__('not offered') : '' }}
                            </option>
                        @endforeach
                    </select>

                    @if (array_filter($filters))
                        <a href="{{ route('admin.locations.index') }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                    @endif
                </div>

                {{-- The status is part of what the form asks; the tabs are its
                     buttons, so a search keeps the tab it was typed under. --}}
                <div class="join flex-wrap">
                    @foreach ($statusTabs as $statusKey => $statusLabel)
                        <button type="submit" name="status" value="{{ $statusKey }}"
                                @class(['btn btn-sm join-item', 'btn-active' => $filters['status'] === $statusKey])>
                            {{ $statusLabel }} <span class="opacity-50">{{ number_format($statusCounts[$statusKey], 0, '.', ' ') }}</span>
                        </button>
                    @endforeach
                </div>
            </form>

            <form method="POST" action="{{ route('admin.locations.bulk') }}" x-data="{ selected: [], applyToAll: false }">
                @csrf
                @foreach ($filters as $key => $value)
                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                @endforeach

                @include('torgash.locations::admin.partials.bulk-bar', [
                    'total' => $places->total(),
                    'actions' => ['enable' => __('Enable'), 'disable' => __('Disable'), 'delete' => __('Delete')],
                    'hint' => __('Tick places to switch several on or off or delete them — or all of those found.'),
                ])

                @if ($places->isEmpty())
                    <div class="text-center py-12 opacity-60">
                        <x-icon name="map-pin" size="xl" class="opacity-40" />
                        <p class="mt-2">
                            {{ $totals['places'] === 0
                                ? __('Nothing here yet — import a pack to fill the registry.')
                                : __('Nothing matches those filters.') }}
                        </p>
                        @if ($totals['places'] === 0)
                            <a href="{{ route('admin.locations.import') }}#where-towns-come-from" class="link link-primary text-sm">{{ __('Where the towns come from') }}</a>
                        @endif
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
                                    <th>{{ __('Place') }}</th>
                                    <th>{{ __('Region') }}</th>
                                    <th>{{ __('Type') }}</th>
                                    <th class="text-right">{{ __('Population') }}</th>
                                    <th>{{ __('Postal codes') }}</th>
                                    <th></th>
                                    <th>{{ __('Status') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($places as $place)
                                    @include('torgash.locations::admin.partials.row', ['place' => $place])
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </form>

            {{ $places->links() }}
        </div>
    </div>

    {{-- The rows' delete buttons name this form (form= + formaction=): they
         sit inside the bulk form, and forms do not nest. --}}
    <form id="place-delete" method="POST" class="hidden">
        @csrf @method('DELETE')
        @foreach ($filters as $key => $value)
            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
        @endforeach
        <input type="hidden" name="page" value="{{ $places->currentPage() }}">
    </form>
@endsection
