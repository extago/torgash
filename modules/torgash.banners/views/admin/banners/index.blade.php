@extends('admin.layout')

@section('title', __('Banners'))

@section('content')
    @include('torgash.banners::admin._nav', ['bannersScreen' => 'banners'])

    @php
        $stateTabs = ['' => __('All'), 'running' => __('Being shown'), 'scheduled' => __('Starts later'), 'ended' => __('Ended'), 'off' => __('Off')];
        $isFiltered = $filters['q'] !== '' || $filters['state'] !== '';
    @endphp

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    <div class="card bg-base-100 border border-base-300" x-data="{ selected: [] }">
        <div class="card-body gap-4">
            <div class="flex justify-between items-start gap-3 flex-wrap">
                <p class="text-sm opacity-70 max-w-3xl">
                    {{ __('A banner is what is typed into its template\'s fields — words, pictures, a link, a colour. It is shown by the sliders it stands in, between its dates and under its conditions.') }}
                </p>
                <a href="{{ route('admin.banners.create') }}" class="btn btn-primary btn-sm">+ {{ __('New banner') }}</a>
            </div>

            <form method="GET" class="flex flex-wrap items-center gap-2">
                <label class="input input-sm w-full sm:w-72 gap-2">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="grow" placeholder="{{ __('Search by name or words…') }}">
                </label>
                <input type="hidden" name="state" value="{{ $filters['state'] }}">
                @if ($isFiltered)
                    <a href="{{ route('admin.banners.index') }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
            </form>

            <div class="join flex-wrap -mt-1">
                @foreach ($stateTabs as $stateKey => $stateLabel)
                    <a href="{{ request()->fullUrlWithQuery(['state' => $stateKey ?: null]) }}"
                       @class(['btn btn-sm join-item', 'btn-active' => $filters['state'] === $stateKey])>
                        {{ $stateLabel }} <span class="opacity-50">{{ $counts[$stateKey] }}</span>
                    </a>
                @endforeach
            </div>

            <div class="min-h-12 flex items-center">
                <p x-show="!selected.length" class="text-xs opacity-50">
                    {{ __('Tick banners to switch several on or off at once, or delete them.') }}
                </p>
                <form method="POST" action="{{ route('admin.banners.bulk') }}"
                      x-show="selected.length" x-cloak
                      class="w-full flex flex-wrap items-center gap-2 bg-base-200 rounded-box p-2">
                    @csrf
                    <template x-for="id in selected" :key="id">
                        <input type="hidden" name="ids[]" :value="id">
                    </template>
                    <span class="text-sm px-1"><span x-text="selected.length"></span> {{ __('selected') }}</span>
                    <div class="join">
                        <button name="action" value="enable" class="btn btn-sm join-item">{{ __('Enable') }}</button>
                        <button name="action" value="disable" class="btn btn-sm join-item">{{ __('Disable') }}</button>
                    </div>
                    <button name="action" value="delete" class="btn btn-sm btn-error btn-outline gap-1.5"
                            data-confirm="{{ __('Delete the selected banners?') }}">
                        <x-icon name="trash-2" size="sm" /> {{ __('Delete') }}
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm ml-auto" x-on:click="selected = []">{{ __('Clear selection') }}</button>
                </form>
            </div>

            @if ($banners->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="image" size="xl" class="opacity-40" />
                    <p class="mt-2">{{ $isFiltered ? __('No banners match this search.') : __('Nothing here yet. Make a banner, put it in a slider, and place the slider with a block.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="w-10">
                                    <input type="checkbox" class="checkbox checkbox-sm" aria-label="{{ __('Select all on this page') }}"
                                           x-on:change="selected = $event.target.checked ? @js($banners->pluck('id')->map(fn ($id) => (string) $id)) : []"
                                           :checked="selected.length === {{ $banners->count() }}">
                                </th>
                                <th class="w-24"></th>
                                <th>{{ __('Name') }}</th>
                                <th>{{ __('Sliders') }}</th>
                                <th>{{ __('Shown when') }}</th>
                                <th class="text-end" title="{{ __('Clicks on the banner\'s links over the last 30 days') }}">{{ __('Clicks, 30 days') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($banners as $banner)
                                @php
                                    // A picture of its own, or its colour, or its icon — whatever tells two rows apart.
                                    $rowThumb = $banner->thumbUrl();
                                    $rowColour = collect((array) $banner->values)->first(fn ($value) => is_string($value) && preg_match('/^#[0-9a-f]{6}$/i', $value));
                                    $rowIcon = (string) ($banner->values['icon'] ?? '');
                                    $rowIcon = preg_match('/^[a-z0-9-]+$/', $rowIcon) && is_file(resource_path('icons/lucide/'.$rowIcon.'.svg')) ? $rowIcon : '';
                                    $rowRules = count($banner->rules ?? []);
                                @endphp
                                <tr class="hover:bg-base-200/50" :class="selected.includes('{{ $banner->id }}') && 'bg-primary/5'">
                                    <td>
                                        <input type="checkbox" class="checkbox checkbox-sm" value="{{ $banner->id }}" x-model="selected" aria-label="{{ $banner->name }}">
                                    </td>
                                    <td>
                                        <a href="{{ route('admin.banners.edit', $banner) }}"
                                           class="block w-20 aspect-video rounded-lg overflow-hidden bg-base-200 border border-base-300"
                                           @if ($rowColour && ! $rowThumb) style="background: {{ $rowColour }}" @endif>
                                            @if ($rowThumb)
                                                <img src="{{ $rowThumb }}" alt="" class="w-full h-full object-cover" loading="lazy">
                                            @elseif ($rowIcon !== '')
                                                <span class="w-full h-full flex items-center justify-center opacity-60"><x-icon :name="$rowIcon" size="md" /></span>
                                            @endif
                                        </a>
                                    </td>
                                    <td class="max-w-md">
                                        <a href="{{ route('admin.banners.edit', $banner) }}"
                                           @class(['font-medium link link-hover', 'opacity-60' => ! $banner->enabled])>{{ $banner->name }}</a>
                                        <div class="text-xs opacity-50">{{ $banner->template?->label() }}</div>
                                    </td>
                                    <td class="text-sm">
                                        @forelse ($banner->sliders as $rowSlider)
                                            <a href="{{ route('admin.banners.sliders.edit', $rowSlider) }}" class="badge badge-ghost badge-sm whitespace-nowrap">{{ $rowSlider->name }}</a>
                                        @empty
                                            <span class="text-xs text-warning">{{ __('In no slider — not shown') }}</span>
                                        @endforelse
                                    </td>
                                    <td class="text-sm opacity-70 whitespace-nowrap">
                                        {{ $rowRules ? trans_choice('{1}one condition|[2,*]:count conditions', $rowRules, ['count' => $rowRules]) : __('always') }}
                                    </td>
                                    <td class="text-end tabular-nums text-sm">{{ $clicks[$banner->id]['month'] ?? 0 }}</td>
                                    @include('torgash.banners::admin.banners._status', ['banner' => $banner])
                                    <td class="text-end whitespace-nowrap">
                                        <div class="inline-flex items-center gap-1">
                                            <form method="POST" action="{{ route('admin.banners.duplicate', $banner) }}" class="inline">
                                                @csrf
                                                <button class="btn btn-ghost btn-sm btn-square" title="{{ __('Duplicate') }}" aria-label="{{ __('Duplicate') }}"><x-icon name="copy" /></button>
                                            </form>
                                            <a href="{{ route('admin.banners.edit', $banner) }}" class="btn btn-ghost btn-sm btn-square"
                                               title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"><x-icon name="pencil" /></a>
                                            <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                            <form method="POST" action="{{ route('admin.banners.destroy', $banner) }}" class="inline"
                                                  data-confirm="{{ __('Delete this banner?') }}">
                                                @csrf @method('DELETE')
                                                <button class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}"><x-icon name="trash-2" /></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
