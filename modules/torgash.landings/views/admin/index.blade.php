@extends('admin.layout')

@section('title', __('Product landings'))

@section('content')
    @php
        $indexPlacements = [
            'before' => __('Above the description'),
            'instead' => __('Instead of the description'),
            'after' => __('Below the description'),
            'tab' => __('A tab of its own'),
        ];
        $indexStates = [
            'running' => [__('Shown now'), 'badge-success'],
            'scheduled' => [__('Waits for its date'), 'badge-info'],
            'ended' => [__('Its dates are over'), 'badge-ghost'],
            'off' => [__('Switched off'), 'badge-ghost'],
        ];
        $indexRules = app(\App\Core\Display\DisplayRules::class);
    @endphp

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-4">
            <p class="text-sm opacity-70 max-w-3xl">
                {{ __('HTML and CSS pages with their own pictures on the product page: above the description, below it, instead of it, or as a tab. The description itself stays the product\'s — for feeds, exports and search engines.') }}
            </p>

            <div class="flex flex-wrap items-center gap-2">
                <form method="GET" class="join">
                    <input name="q" value="{{ request('q') }}" placeholder="{{ __('Search by name') }}" class="input input-bordered input-sm join-item w-56">
                    <button class="btn btn-sm join-item" aria-label="{{ __('Search') }}"><x-icon name="search" size="sm" /></button>
                </form>
                <div class="grow"></div>
                <form method="POST" action="{{ route('admin.landings.import.new') }}" enctype="multipart/form-data" class="join">
                    @csrf
                    <input type="file" name="archive" accept=".zip,application/zip" required class="file-input file-input-sm join-item w-56" aria-label="{{ __('ZIP archive') }}">
                    <button class="btn btn-sm join-item gap-1"><x-icon name="file-archive" size="sm" /> {{ __('New from a ZIP') }}</button>
                </form>
                <a href="{{ route('admin.landings.create') }}" class="btn btn-primary btn-sm gap-1"><x-icon name="plus" size="sm" /> {{ __('New landing') }}</a>
            </div>

            @if ($landings->isEmpty())
                <div class="text-sm opacity-60 py-6 text-center">{{ __('No landings yet.') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('Name') }}</th>
                                <th>{{ __('Where') }}</th>
                                <th>{{ __('For which products') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th class="text-right"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($landings as $indexLanding)
                                @php
                                    [$stateLabel, $stateClass] = $indexStates[$indexLanding->state()];
                                    $indexCounts = $counts[$indexLanding->id] ?? collect();
                                    $indexWho = collect($indexLanding->rules ?? [])->map(fn ($rule) => $indexRules->describe($rule))->filter();
                                @endphp
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.landings.edit', $indexLanding) }}" class="link link-hover font-medium">{{ $indexLanding->name }}</a>
                                        <div class="text-xs opacity-60">{{ collect(array_keys((array) $indexLanding->html))->map(fn ($l) => strtoupper($l))->implode(' · ') }}</div>
                                    </td>
                                    <td class="text-sm whitespace-nowrap">
                                        {{ $indexPlacements[$indexLanding->placement] ?? $indexLanding->placement }}
                                        @if ($indexLanding->placement === 'tab')
                                            <span class="opacity-60">· {{ $indexLanding->t('tab_title', admin_content_locale()) ?: $indexLanding->name }}</span>
                                        @endif
                                    </td>
                                    <td class="text-sm">
                                        @if ($indexWho->isNotEmpty())
                                            <div>{{ $indexWho->implode($indexLanding->rules_match === 'any' ? ' '.__('or').' ' : '; ') }}</div>
                                        @endif
                                        @if ($indexCounts->get('picked'))
                                            <div>{{ trans_choice('+ :count product picked|+ :count products picked', $indexCounts->get('picked')) }}</div>
                                        @endif
                                        @if ($indexWho->isEmpty() && ! $indexCounts->get('picked'))
                                            <div>{{ __('Every product') }}</div>
                                        @endif
                                        @if ($indexCounts->get('excluded'))
                                            <div class="opacity-60">{{ trans_choice('except :count product|except :count products', $indexCounts->get('excluded')) }}</div>
                                        @endif
                                    </td>
                                    <td>
                                        {{-- The badge says the STATE (it may be on and still waiting
                                             for its date); the choices are on and off. --}}
                                        <x-admin.status-menu :label="$stateLabel" :badge="$stateClass"
                                                             :options="['1' => __('Switched on'), '0' => __('Switched off')]" :current="$indexLanding->enabled ? '1' : '0'"
                                                             :url="route('admin.landings.toggle', $indexLanding)" field="enabled" method="post" />
                                        @if ($indexLanding->starts_at || $indexLanding->ends_at)
                                            <div class="text-xs opacity-60 mt-1 whitespace-nowrap">{{ $indexLanding->starts_at?->format('d.m.Y') ?? '…' }} — {{ $indexLanding->ends_at?->format('d.m.Y') ?? '…' }}</div>
                                        @endif
                                    </td>
                                    <td class="text-right whitespace-nowrap">
                                        <a href="{{ route('admin.landings.edit', $indexLanding) }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Edit') }}"><x-icon name="pencil" size="sm" /></a>
                                        <button form="landing-delete-{{ $indexLanding->id }}" class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Delete') }}"><x-icon name="trash-2" size="sm" /></button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    @foreach ($landings as $indexLanding)
        <form method="POST" id="landing-delete-{{ $indexLanding->id }}" action="{{ route('admin.landings.destroy', $indexLanding) }}"
              data-confirm="{{ __('Delete this landing and its files?') }}">@csrf @method('DELETE')</form>
    @endforeach
@endsection
