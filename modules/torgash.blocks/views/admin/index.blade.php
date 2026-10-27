@extends('admin.layout')

@section('title', __('Blocks'))

@section('content')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <p class="text-sm opacity-70 max-w-3xl">
                    {{ __('The places on the storefront a block can be put: the homepage, under a category, beside a product, the header, the phone menu. Open one to arrange its blocks — one under another, side by side as columns, or as tabs.') }}
                </p>

                {{-- The way back to what the theme intended. Here rather than on
                     each zone, because it is one screen answering "which zones,
                     and add or replace" — and because the zone you want back is
                     usually the one you emptied, which shows nothing to hang a
                     button on. --}}
                <a href="{{ route('admin.blocks.theme_defaults') }}" class="btn btn-outline btn-sm gap-1.5">
                    <x-icon name="rotate-ccw" size="sm" />
                    {{ __('Restore from the theme') }}
                </a>
            </div>

            @forelse ($grouped as $group => $zones)
                <div>
                    <h3 class="font-semibold mb-2">{{ __(\Illuminate\Support\Str::ucfirst($group)) }}</h3>
                    <ul class="divide-y divide-base-300 border border-base-300 rounded-box">
                        @foreach ($zones as $zone)
                            @php
                                $count = $counts[$zone['name']] ?? 0;
                                $on = ! in_array($zone['name'], $disabledZones, true);
                            @endphp
                            {{-- The switch sits BESIDE the link, not inside it: a form
                                 nested in an <a> is invalid and the click would be
                                 swallowed by the navigation. --}}
                            <li class="flex items-center gap-3 pr-3">
                                <a href="{{ route('admin.blocks.zone', $zone['name']) }}"
                                   class="flex items-center gap-3 p-3 flex-1 min-w-0 hover:bg-base-200/60">
                                    <div class="min-w-0 flex-1 {{ $on ? '' : 'opacity-50' }}">
                                        <div class="font-medium">
                                            {{ label_text($zone['label'] ?? null, null, $zone['name']) }}
                                        </div>
                                        <div class="text-xs font-mono opacity-50 mt-0.5">{{ $zone['name'] }}</div>
                                    </div>
                                    <span class="badge badge-ghost badge-sm shrink-0" title="{{ __('What kind of place it is') }}">{{ __(\App\Core\Zones\ZoneRegistry::SHAPES[$zone['shape'] ?? 'wide'] ?? '') }}</span>
                                    <span @class(['badge badge-sm shrink-0', 'badge-primary' => $count > 0 && $on, 'badge-ghost opacity-40' => $count === 0 || ! $on])>
                                        {{ $count }}
                                    </span>
                                    <x-icon name="chevron-right" size="sm" class="opacity-40 shrink-0" />
                                </a>
                                <form method="POST" action="{{ route('admin.blocks.zone.toggle', $zone['name']) }}" class="shrink-0 flex">
                                    @csrf @method('PATCH')
                                    <input type="checkbox" class="toggle toggle-sm toggle-success" @checked($on)
                                           title="{{ $on ? __('Disable zone') : __('Enable zone') }}"
                                           data-submit-on-change>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @empty
                <div class="text-center py-12 opacity-60">
                    <p>{{ __('No zones declared yet.') }}</p>
                </div>
            @endforelse
        </div>
    </div>
@endsection
