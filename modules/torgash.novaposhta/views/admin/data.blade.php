@extends('admin.layout')

@section('title', __('Nova Poshta'))

@section('content')
    {{-- Reached from the carrier's settings card, not from a menu item of its
         own: a carrier is a property of a shipping method, not a section of
         the shop — so the way back is to those settings. --}}
    <div class="flex items-center gap-3 mb-4">
        <a href="{{ auth()->user()?->can('settings.manage') ? route('admin.settings.edit', 'torgash.novaposhta') : route('admin.dashboard') }}" class="btn btn-ghost btn-sm btn-square"
           title="{{ __('Back to module settings') }}" aria-label="{{ __('Back to module settings') }}">
            <x-icon name="arrow-left" size="lg" />
        </a>
        <div>
            <h1 class="text-2xl font-semibold">{{ __('Nova Poshta') }}</h1>
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
                    <div class="opacity-50 text-xs uppercase tracking-wide">{{ __('API key') }}</div>
                    <div class="mt-1 flex items-center gap-2">
                        @if ($hasKey)
                            <span class="badge badge-success badge-sm">{{ __('set') }}</span>
                            <form method="POST" action="{{ route('admin.nova-poshta.check') }}">
                                @csrf
                                <button class="btn btn-ghost btn-sm">{{ __('Test it') }}</button>
                            </form>
                        @else
                            <span class="badge badge-warning badge-sm">{{ __('not set') }}</span>
                            @can('settings.manage')
                                <a href="{{ route('admin.settings.edit', 'torgash.novaposhta') }}" class="link text-xs">{{ __('module settings') }}</a>
                            @endcan
                        @endif
                    </div>
                </div>
                <div>
                    <div class="opacity-50 text-xs uppercase tracking-wide">{{ __('Settlements synced') }}</div>
                    <div class="mt-1">
                        {{ number_format($counts['settlements'], 0, '.', ' ') }}
                        @if ($settlementsSyncedAt)
                            <span class="opacity-50">· {{ $settlementsSyncedAt->diffForHumans() }}</span>
                        @endif
                    </div>
                    <div class="text-xs opacity-50">{{ __('and :count towns reachable by courier', ['count' => number_format($counts['cities'], 0, '.', ' ')]) }}</div>
                    {{-- Branches are cached per town, so the number grows as
                         customers name towns — or all at once, with the button
                         below. Shown because "are they in the database?" is the
                         first thing anyone asks. --}}
                    <div class="text-xs opacity-50">
                        {{ __(':branches branches in :towns towns', [
                            'branches' => number_format($counts['branches'], 0, '.', ' '),
                            'towns' => number_format($counts['branch_towns'], 0, '.', ' '),
                        ]) }}
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
                            // How each mapping was decided — a shop that sees
                            // "coordinates: 900" knows where to spot-check.
                            $methodLabels = [
                                'name_area_district' => __('name + oblast + raion'),
                                'name_area' => __('name + oblast'),
                                'postal' => __('postal index'),
                                'coords' => __('coordinates'),
                                'manual' => __('by hand'),
                            ];
                        @endphp
                        <div class="text-xs opacity-50">
                            @foreach ($byMethod as $method => $total)
                                {{ $methodLabels[$method] ?? $method }}: {{ number_format($total, 0, '.', ' ') }}@if (! $loop->last), @endif
                            @endforeach
                        </div>
                    @endif
                </div>
                <div>
                    @include('torgash.novaposhta::admin.partials.sender', ['sender' => $sender, 'query' => '', 'matches' => collect()])
                </div>
                <div>
                    <div class="opacity-50 text-xs uppercase tracking-wide">{{ __('Not offered Nova Poshta') }}</div>
                    <div class="mt-1 {{ $counts['unmatched'] > 0 ? 'text-warning' : '' }}">
                        {{ number_format($counts['unmatched'], 0, '.', ' ') }}
                    </div>
                    <div class="text-xs opacity-50">{{ __('a place with no match is simply not offered this carrier') }}</div>
                </div>
            </div>

            <div class="flex flex-wrap gap-2 items-center border-t border-base-300 pt-4">
                <form method="POST" action="{{ route('admin.nova-poshta.sync') }}">
                    @csrf
                    <button class="btn btn-primary btn-sm" @disabled(! $hasKey)>
                        <x-icon name="refresh-cw" size="sm" />
                        {{ $counts['settlements'] ? __('Re-sync the directory') : __('Sync the directory') }}
                    </button>
                </form>
                <form method="POST" action="{{ route('admin.nova-poshta.match') }}">
                    @csrf
                    <button class="btn btn-sm" @disabled($counts['settlements'] === 0)>
                        <x-icon name="link" size="sm" />
                        {{ __('Match with the registry') }}
                    </button>
                </form>
                <form method="POST" action="{{ route('admin.nova-poshta.warehouses') }}">
                    @csrf
                    <button class="btn btn-sm" @disabled($counts['mapped'] === 0)>
                        <x-icon name="building-2" size="sm" />
                        {{ __('Fetch all branches') }}
                    </button>
                </form>
                @can('tasks.manage')
                    <a href="{{ route('admin.tasks') }}" class="btn btn-ghost btn-sm">{{ __('Background tasks') }}</a>
                @endcan

                {{-- The mapping is the only thing here that cannot be rebuilt
                     from the carrier's API: 26 000 decisions, some of them made
                     by hand. So it can be taken away as a file and put back. --}}
                <div class="ml-auto flex flex-wrap items-center gap-2">
                    <a href="{{ route('admin.nova-poshta.map.export') }}" class="btn btn-ghost btn-sm" @disabled($counts['mapped'] === 0)>
                        <x-icon name="download" size="sm" /> {{ __('Save the mapping') }}
                    </a>
                    <form method="POST" action="{{ route('admin.nova-poshta.map.import') }}" enctype="multipart/form-data"
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
                {{ __('The carrier has no "find the town called X" — branches are asked for by its own id, so every place has to be tied to one before a customer types anything. Names alone cannot do it (Ukraine has 80 places called Іванівка), so matching goes name + oblast + raion, then the postal index, then coordinates. Raions are not much help: the carrier still uses the pre-2020 ones. What is left over is listed below — mostly places the carrier does not have at all.') }}
            </p>
        </div>
    </div>

    <div class="card bg-base-100 shadow-sm">
        <div class="card-body gap-4">
            @php
                $titles = [
                    'unmatched' => __('Places without a match'),
                    'manual' => __('Places bound by hand'),
                    'all' => __('Places and their Nova Poshta settlement'),
                ];
                // A sortable header: same URL, this column, and the direction
                // flipped when it is already the one being sorted by.
                $sortLink = function (string $column) use ($order) {
                    $active = $order['sort'] === $column;
                    $dir = $active && $order['dir'] === 'asc' ? 'desc' : 'asc';

                    return [
                        'url' => request()->fullUrlWithQuery(['sort' => $column, 'dir' => $dir, 'page' => null]),
                        'active' => $active,
                        'icon' => $active
                            ? ($order['dir'] === 'asc' ? 'arrow-up' : 'arrow-down')
                            : 'chevrons-up-down',
                    ];
                };
                $place = $sortLink('place');
                $region = $sortLink('region');
                $population = $sortLink('population');
            @endphp

            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="card-title text-base">{{ $titles[$show] }}</h2>
                <div class="flex flex-wrap items-center gap-2">
                    {{-- Three jobs, not one list: what still needs doing, what a
                         human already decided (the rows a re-run will not touch),
                         and everything — the only way to find a mapping that went
                         to the wrong namesake. --}}
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
                                <a href="{{ $place['url'] }}" class="inline-flex items-center gap-1 hover:text-base-content {{ $place['active'] ? 'text-base-content' : '' }}">
                                    {{ __('Place') }} <x-icon :name="$place['icon']" size="sm" class="opacity-50" />
                                </a>
                            </th>
                            <th>
                                <a href="{{ $region['url'] }}" class="inline-flex items-center gap-1 hover:text-base-content {{ $region['active'] ? 'text-base-content' : '' }}">
                                    {{ __('Region') }} <x-icon :name="$region['icon']" size="sm" class="opacity-50" />
                                </a>
                            </th>
                            <th>{{ __('Nova Poshta settlement') }}</th>
                            <th class="text-right">
                                <a href="{{ $population['url'] }}" class="inline-flex items-center gap-1 hover:text-base-content {{ $population['active'] ? 'text-base-content' : '' }}">
                                    {{ __('Population') }} <x-icon :name="$population['icon']" size="sm" class="opacity-50" />
                                </a>
                            </th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($places as $place)
                            @include('torgash.novaposhta::admin.partials.place-row', [
                                'place' => $place,
                                'map' => $maps[$place->id] ?? null,
                            ])
                        @empty
                            <tr>
                                <td colspan="5" class="text-sm opacity-60">
                                    @if ($search !== '')
                                        {{ __('Nothing matches.') }}
                                    @elseif ($show === 'manual')
                                        {{ __('Nothing has been bound by hand yet.') }}
                                    @else
                                        {{ __('Every place has a Nova Poshta settlement.') }}
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
