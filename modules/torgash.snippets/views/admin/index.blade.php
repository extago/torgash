@extends('admin.layout')

@section('title', __('Code snippets'))

@section('content')
    @php
        $placeTabs = ['' => __('All'), 'head' => __('Page head'), 'body_start' => __('Start of the page body'), 'footer' => __('End of the page')];
        $isFiltered = $filters['q'] !== '' || $filters['place'] !== '';
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
                    {{ __('Counters, pixels, chat widgets and verification tags. The code is printed on the storefront exactly as it is written here — never in the admin panel.') }}
                </p>
                <a href="{{ route('admin.snippets.create') }}" class="btn btn-primary btn-sm">+ {{ __('New snippet') }}</a>
            </div>

            <form method="GET" class="flex flex-wrap items-center gap-2">
                <label class="input input-sm w-full sm:w-72 gap-2">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="grow" placeholder="{{ __('Search by name or in the code…') }}">
                </label>
                <input type="hidden" name="place" value="{{ $filters['place'] }}">
                @if ($isFiltered)
                    <a href="{{ route('admin.snippets.index') }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
            </form>

            <div class="join flex-wrap -mt-1">
                @foreach ($placeTabs as $placeKey => $placeLabel)
                    <a href="{{ request()->fullUrlWithQuery(['place' => $placeKey ?: null]) }}"
                       @class(['btn btn-sm join-item', 'btn-active' => $filters['place'] === $placeKey])>
                        {{ $placeLabel }} <span class="opacity-50">{{ $placeCounts[$placeKey] }}</span>
                    </a>
                @endforeach
            </div>

            <div class="min-h-12 flex items-center">
                <p x-show="!selected.length" class="text-xs opacity-50">
                    {{ __('Tick snippets to switch several on or off at once, or delete them.') }}
                </p>
                <form method="POST" action="{{ route('admin.snippets.bulk') }}"
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
                            data-confirm="{{ __('Delete the selected snippets?') }}">
                        <x-icon name="trash-2" size="sm" /> {{ __('Delete') }}
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm ml-auto" x-on:click="selected = []">{{ __('Clear selection') }}</button>
                </form>
            </div>

            @if ($snippets->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="code-xml" size="xl" class="opacity-40" />
                    <p class="mt-2">{{ $isFiltered ? __('No snippets match this search.') : __('Nothing here yet. A snippet is a piece of code, a place on the page, and the rules for when it is shown.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="w-10">
                                    <input type="checkbox" class="checkbox checkbox-sm" aria-label="{{ __('Select all on this page') }}"
                                           x-on:change="selected = $event.target.checked ? @js($snippets->pluck('id')->map(fn ($id) => (string) $id)) : []"
                                           :checked="selected.length === {{ $snippets->count() }}">
                                </th>
                                <th>{{ __('Name') }}</th>
                                <th>{{ __('Where on the page') }}</th>
                                <th>{{ __('For consent') }}</th>
                                <th>{{ __('Shown when') }}</th>
                                <th>{{ __('Storefront') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($snippets as $snippet)
                                @php
                                    // The first line of the code, so two "Pixel" rows can be told apart at a glance.
                                    // A bare opening tag says nothing, so the first line that is more than one.
                                    $codeLine = collect(preg_split('/\R/', (string) $snippet->code))->map(fn ($line) => trim($line))
                                        ->first(fn ($line) => $line !== '' && ! preg_match('#^</?(script|noscript|style)>$#i', $line)) ?? '';
                                    $codeLine = \Illuminate\Support\Str::limit($codeLine, 70);
                                    $ruleCount = count($snippet->rules ?? []);
                                @endphp
                                <tr class="hover:bg-base-200/50" :class="selected.includes('{{ $snippet->id }}') && 'bg-primary/5'">
                                    <td>
                                        <input type="checkbox" class="checkbox checkbox-sm" value="{{ $snippet->id }}" x-model="selected" aria-label="{{ $snippet->name }}">
                                    </td>
                                    <td class="max-w-md">
                                        <a href="{{ route('admin.snippets.edit', $snippet) }}"
                                           @class(['font-medium link link-hover', 'opacity-60' => ! $snippet->enabled])>{{ $snippet->name }}</a>
                                        @if ($codeLine !== '')
                                            <div class="text-xs opacity-50 font-mono truncate">{{ $codeLine }}</div>
                                        @endif
                                    </td>
                                    <td class="text-sm whitespace-nowrap">{{ $snippet->placementLabel() }}</td>
                                    <td class="text-sm whitespace-nowrap">
                                        {{ $snippet->categoryLabel() }}
                                        @if ($snippet->on_click)
                                            <span class="badge badge-ghost badge-sm ml-1">{{ __('on click') }}</span>
                                        @endif
                                    </td>
                                    <td class="text-sm opacity-70 whitespace-nowrap">
                                        {{ $ruleCount ? trans_choice('{1}one condition|[2,*]:count conditions', $ruleCount, ['count' => $ruleCount]) : __('every page') }}
                                    </td>
                                    <td class="text-sm opacity-70 whitespace-nowrap">{{ $snippet->storefront_id ? ($storefronts[$snippet->storefront_id] ?? '#'.$snippet->storefront_id) : __('All storefronts') }}</td>
                                    @include('torgash.snippets::admin._status', ['snippet' => $snippet])
                                    <td class="text-end whitespace-nowrap">
                                        <div class="inline-flex items-center gap-1">
                                            <form method="POST" action="{{ route('admin.snippets.duplicate', $snippet) }}" class="inline">
                                                @csrf
                                                <button class="btn btn-ghost btn-sm btn-square" title="{{ __('Duplicate') }}" aria-label="{{ __('Duplicate') }}"><x-icon name="copy" /></button>
                                            </form>
                                            <a href="{{ route('admin.snippets.edit', $snippet) }}" class="btn btn-ghost btn-sm btn-square"
                                               title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"><x-icon name="pencil" /></a>
                                            <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                            <form method="POST" action="{{ route('admin.snippets.destroy', $snippet) }}" class="inline"
                                                  data-confirm="{{ __('Delete this snippet?') }}">
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
