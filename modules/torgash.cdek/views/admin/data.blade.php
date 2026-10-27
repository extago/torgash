@extends('admin.layout')

@section('title', __('CDEK'))

@section('content')
    {{-- Reached from the carrier's settings card, not from a menu item of its
         own: a carrier is a property of a shipping method, not a section of
         the shop — so the way back is to those settings. --}}
    <div class="flex items-center gap-3 mb-4">
        <a href="{{ auth()->user()?->can('settings.manage') ? route('admin.settings.edit', 'torgash.cdek') : route('admin.dashboard') }}" class="btn btn-ghost btn-sm btn-square"
           title="{{ __('Back to module settings') }}">
            <x-icon name="arrow-left" size="lg" />
        </a>
        <div>
            <h1 class="text-2xl font-semibold">{{ __('CDEK') }}</h1>
            <div class="text-xs opacity-60 mt-1">
                {{ __('The carrier\'s own directory and how it lines up with your locations registry.') }}
            </div>
        </div>
    </div>

    @if (session('status'))
        <div class="alert alert-success mb-4 text-sm">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error mb-4 text-sm">{{ $errors->first() }}</div>
    @endif

    <div class="card bg-base-100 shadow-sm mb-4">
        <div class="card-body gap-4">
            <div class="grid sm:grid-cols-2 lg:grid-cols-5 gap-4 text-sm">
                <div>
                    <div class="opacity-50 text-xs uppercase tracking-wide">{{ __('API keys') }}</div>
                    <div class="mt-1 flex flex-wrap items-center gap-2">
                        @if ($configured)
                            <span class="badge badge-success badge-sm">{{ __('set') }}</span>
                            <form method="POST" action="{{ route('admin.cdek.check') }}">
                                @csrf
                                <button class="btn btn-ghost btn-sm">{{ __('Test it') }}</button>
                            </form>
                        @else
                            <span class="badge badge-warning badge-sm">{{ __('not set') }}</span>
                            @can('settings.manage')
                                <a href="{{ route('admin.settings.edit', 'torgash.cdek') }}" class="link text-xs">{{ __('module settings') }}</a>
                            @endcan
                        @endif
                    </div>
                    @if ($testMode)
                        {{-- Said out loud: the test server's directory is a
                             sample, and a shop that forgot this toggle would
                             wonder where most of the country went. --}}
                        <div class="mt-1"><span class="badge badge-warning badge-sm">{{ __('test environment') }}</span></div>
                    @endif
                </div>
                <div>
                    <div class="opacity-50 text-xs uppercase tracking-wide">{{ __('Cities synced') }}</div>
                    <div class="mt-1">
                        {{ number_format($counts['cities'], 0, '.', ' ') }}
                        @if ($citiesSyncedAt)
                            <span class="opacity-50">· {{ $citiesSyncedAt->diffForHumans() }}</span>
                        @endif
                    </div>
                    <div class="text-xs opacity-50">
                        {{ __(':points pickup points in :cities cities', [
                            'points' => number_format($counts['points'], 0, '.', ' '),
                            'cities' => number_format($counts['point_cities'], 0, '.', ' '),
                        ]) }}
                        @if ($pointsSyncedAt)
                            · {{ $pointsSyncedAt->diffForHumans() }}
                        @endif
                    </div>
                </div>
                <div>
                    <div class="opacity-50 text-xs uppercase tracking-wide">{{ __('Places mapped') }}</div>
                    <div class="mt-1">
                        {{ number_format($counts['mapped'], 0, '.', ' ') }}
                        <span class="opacity-50">/ {{ number_format($counts['places'], 0, '.', ' ') }}</span>
                    </div>
                    @if ($byMethod)
                        @php
                            $methodLabels = [
                                'name_district' => __('name + region + district'),
                                'coords' => __('name + coordinates'),
                                'name_region' => __('name + region'),
                                'name' => __('unique name'),
                                'manual' => __('by hand'),
                            ];
                        @endphp
                        <div class="text-xs opacity-50">
                            {{ collect($byMethod)->map(fn ($total, $method) => ($methodLabels[$method] ?? $method).': '.number_format($total, 0, '.', ' '))->implode(', ') }}
                        </div>
                    @endif
                </div>
                <div>
                    @include('torgash.cdek::admin.partials.sender', ['sender' => $sender, 'query' => '', 'matches' => collect()])
                </div>
                <div>
                    <div class="opacity-50 text-xs uppercase tracking-wide">{{ __('Not offered CDEK') }}</div>
                    <div class="mt-1 {{ $counts['unmatched'] > 0 ? 'text-warning' : '' }}">
                        {{ number_format($counts['unmatched'], 0, '.', ' ') }}
                    </div>
                    <div class="text-xs opacity-50">{{ __('a place with no match is simply not offered this carrier') }}</div>
                </div>
            </div>

            <div class="flex flex-wrap gap-2 items-center border-t border-base-300 pt-4">
                <form method="POST" action="{{ route('admin.cdek.sync') }}">
                    @csrf
                    <button class="btn btn-primary btn-sm" @disabled(! $configured)>
                        <x-icon name="refresh-cw" size="sm" />
                        {{ $counts['cities'] ? __('Re-sync the directory') : __('Sync the directory') }}
                    </button>
                </form>
                <form method="POST" action="{{ route('admin.cdek.match') }}">
                    @csrf
                    <button class="btn btn-sm" @disabled($counts['cities'] === 0)>
                        <x-icon name="link" size="sm" />
                        {{ __('Match with the registry') }}
                    </button>
                </form>
                <form method="POST" action="{{ route('admin.cdek.points') }}">
                    @csrf
                    <button class="btn btn-sm" @disabled(! $configured)>
                        <x-icon name="map-pin" size="sm" />
                        {{ __('Fetch all pickup points') }}
                    </button>
                </form>
                @can('tasks.manage')
                    <a href="{{ route('admin.tasks') }}" class="btn btn-ghost btn-sm">{{ __('Background tasks') }}</a>
                @endcan

                {{-- The mapping is the only thing here that cannot be rebuilt
                     from the carrier's API, so it can be taken away as a file
                     and put back. --}}
                <div class="ml-auto flex flex-wrap items-center gap-2">
                    <a href="{{ route('admin.cdek.map.export') }}" class="btn btn-ghost btn-sm" @disabled($counts['mapped'] === 0)>
                        <x-icon name="download" size="sm" /> {{ __('Save the mapping') }}
                    </a>
                    <form method="POST" action="{{ route('admin.cdek.map.import') }}" enctype="multipart/form-data"
                          class="join" x-data
                          @change="$el.querySelector('input[type=file]').files.length && $el.requestSubmit()">
                        @csrf
                        <label class="btn btn-ghost btn-sm join-item">
                            <x-icon name="upload" size="sm" /> {{ __('Restore it') }}
                            <input type="file" name="file" accept=".csv,text/csv" class="hidden">
                        </label>
                    </form>
                </div>
            </div>

            <p class="text-xs opacity-60">
                {{ __('CDEK addresses everything by its own city code, so every place has to be tied to one before a customer types anything. Names alone cannot do it — Russia has dozens of every village name — so matching goes name + region + district, then name + region, then coordinates (CDEK’s own are wrong for some of its oldest towns, so they only break ties). What is left over is listed below.') }}
            </p>
        </div>
    </div>

    <div class="card bg-base-100 shadow-sm">
        <div class="card-body gap-4">
            @php
                $titles = [
                    'unmatched' => __('Places without a match'),
                    'manual' => __('Places bound by hand'),
                    'all' => __('Places and their CDEK city'),
                ];
                // A sortable header: same URL, this column, and the direction
                // flipped when it is already the one being sorted by.
                $sortLink = function (string $column) use ($order) {
                    $active = $order['sort'] === $column;
                    $dir = $active && $order['dir'] === 'asc' ? 'desc' : 'asc';

                    return [
                        'url' => request()->fullUrlWithQuery(['sort' => $column, 'dir' => $dir, 'page' => null]),
                        'active' => $active,
                        'icon' => $active ? ($order['dir'] === 'asc' ? 'arrow-up' : 'arrow-down') : 'chevrons-up-down',
                    ];
                };
                $placeSort = $sortLink('place');
                $regionSort = $sortLink('region');
                $populationSort = $sortLink('population');
            @endphp

            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="card-title text-base">{{ $titles[$show] }}</h2>
                <div class="flex flex-wrap items-center gap-2">
                    <div class="join">
                        <a href="{{ request()->fullUrlWithQuery(['show' => null, 'page' => null]) }}"
                           class="btn btn-sm join-item {{ $show === 'unmatched' ? 'btn-active' : '' }}">{{ __('Without a match') }}</a>
                        <a href="{{ request()->fullUrlWithQuery(['show' => 'manual', 'page' => null]) }}"
                           class="btn btn-sm join-item {{ $show === 'manual' ? 'btn-active' : '' }}">
                            {{ __('By hand') }}
                            @if (! empty($byMethod['manual']))
                                <span class="badge badge-sm badge-ghost">{{ number_format($byMethod['manual'], 0, '.', ' ') }}</span>
                            @endif
                        </a>
                        <a href="{{ request()->fullUrlWithQuery(['show' => 'all', 'page' => null]) }}"
                           class="btn btn-sm join-item {{ $show === 'all' ? 'btn-active' : '' }}">{{ __('All places') }}</a>
                    </div>
                    <form method="GET" class="join">
                        @if ($show !== 'unmatched')<input type="hidden" name="show" value="{{ $show }}">@endif
                        <input type="hidden" name="sort" value="{{ $order['sort'] }}">
                        <input type="hidden" name="dir" value="{{ $order['dir'] }}">
                        <input name="q" value="{{ $search }}" class="input input-bordered input-sm join-item"
                               placeholder="{{ __('Find a place') }}">
                        <button class="btn btn-sm join-item">{{ __('Search') }}</button>
                    </form>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>
                                <a href="{{ $placeSort['url'] }}" class="inline-flex items-center gap-1 hover:text-base-content {{ $placeSort['active'] ? 'text-base-content' : '' }}">
                                    {{ __('Place') }} <x-icon :name="$placeSort['icon']" size="sm" class="opacity-50" />
                                </a>
                            </th>
                            <th>
                                <a href="{{ $regionSort['url'] }}" class="inline-flex items-center gap-1 hover:text-base-content {{ $regionSort['active'] ? 'text-base-content' : '' }}">
                                    {{ __('Region') }} <x-icon :name="$regionSort['icon']" size="sm" class="opacity-50" />
                                </a>
                            </th>
                            <th>{{ __('CDEK city') }}</th>
                            <th class="text-right">
                                <a href="{{ $populationSort['url'] }}" class="inline-flex items-center gap-1 hover:text-base-content {{ $populationSort['active'] ? 'text-base-content' : '' }}">
                                    {{ __('Population') }} <x-icon :name="$populationSort['icon']" size="sm" class="opacity-50" />
                                </a>
                            </th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($places as $place)
                            @include('torgash.cdek::admin.partials.place-row', [
                                'place' => $place,
                                'map' => $maps[$place->id] ?? null,
                                'justBound' => false,
                            ])
                        @empty
                            <tr>
                                <td colspan="5" class="text-sm opacity-60">
                                    @if ($search !== '')
                                        {{ __('Nothing matches.') }}
                                    @elseif ($show === 'manual')
                                        {{ __('Nothing has been bound by hand yet.') }}
                                    @else
                                        {{ __('Every place has a CDEK city.') }}
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $places->links() }}
        </div>
    </div>
@endsection
