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
        $indexRules = app(\App\Core\Display\DisplayRules::class);
        $isFiltered = $filters['q'] !== '' || $filters['state'] !== '';
    @endphp

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    {{-- One Alpine root: the bulk bar and every row's checkbox share
         `selected` — the product list's own arrangement. --}}
    <div class="card bg-base-100 border border-base-300" x-data="{ selected: [] }">
        <div class="card-body gap-4">
            <div class="flex justify-between items-start gap-3 flex-wrap">
                <p class="text-sm opacity-70 max-w-3xl">
                    {{ __('HTML and CSS pages with their own pictures on the product page: above the description, below it, instead of it, or as a tab. The description itself stays the product\'s — for feeds, exports and search engines.') }}
                </p>
                <div class="flex flex-wrap gap-2">
                    {{-- Choosing the file sends it: the archive becomes a landing, switched off until looked at. --}}
                    <form method="POST" action="{{ route('admin.landings.import.new') }}" enctype="multipart/form-data" x-data>
                        @csrf
                        <label class="btn btn-sm gap-1.5" title="{{ __('index.html, style.css and an assets folder — the landing is made switched off') }}">
                            <x-icon name="file-archive" size="sm" /> {{ __('New from a ZIP') }}
                            <input type="file" name="archive" accept=".zip,application/zip" class="hidden" x-on:change="$el.form.submit()">
                        </label>
                    </form>
                    <a href="{{ route('admin.landings.create') }}" class="btn btn-primary btn-sm">+ {{ __('New landing') }}</a>
                </div>
            </div>

            <form method="GET" class="flex flex-wrap items-center gap-2">
                <label class="input input-sm w-full sm:w-72 gap-2">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="grow" placeholder="{{ __('Search by name') }}">
                </label>
                <div class="join flex-wrap">
                    @foreach (['' => __('All')] + \Torgash\Landings\Models\Landing::stateLabels() as $stateKey => $stateLabel)
                        <button type="submit" name="state" value="{{ $stateKey }}"
                                @class(['btn btn-sm join-item', 'btn-active' => $filters['state'] === $stateKey])>
                            {{ $stateLabel }} <span class="opacity-50">{{ $stateCounts[$stateKey] }}</span>
                        </button>
                    @endforeach
                </div>
                @if ($isFiltered)
                    <a href="{{ route('admin.landings.index') }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
            </form>

            <div class="min-h-12 flex items-center">
                <p x-show="!selected.length" class="text-xs opacity-50">
                    {{ __('Tick landings to switch several on or off at once, or delete them.') }}
                </p>
                <form method="POST" action="{{ route('admin.landings.bulk') }}"
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
                            data-confirm="{{ __('Delete the selected landings and their files?') }}">
                        <x-icon name="trash-2" size="sm" /> {{ __('Delete') }}
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm ml-auto" x-on:click="selected = []">{{ __('Clear selection') }}</button>
                </form>
            </div>

            @if ($landings->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="panels-top-left" size="xl" class="opacity-40" />
                    <p class="mt-2">{{ $isFiltered ? __('No landings match this search.') : __('No landings yet.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="w-10">
                                    <input type="checkbox" class="checkbox checkbox-sm" aria-label="{{ __('Select all on this page') }}"
                                           x-on:change="selected = $event.target.checked ? @js($landings->pluck('id')->map(fn ($id) => (string) $id)) : []"
                                           :checked="selected.length === {{ $landings->count() }}">
                                </th>
                                <th>{{ __('Name') }}</th>
                                <th>{{ __('Where') }}</th>
                                <th>{{ __('For which products') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($landings as $indexLanding)
                                @php
                                    $indexCounts = $counts[$indexLanding->id] ?? collect();
                                    $indexWho = collect($indexLanding->rules ?? [])->map(fn ($rule) => $indexRules->describe($rule))->filter();
                                @endphp
                                <tr class="hover:bg-base-200/50" :class="selected.includes('{{ $indexLanding->id }}') && 'bg-primary/5'">
                                    <td>
                                        <input type="checkbox" class="checkbox checkbox-sm" value="{{ $indexLanding->id }}" x-model="selected"
                                               aria-label="{{ $indexLanding->name }}">
                                    </td>
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
                                    @include('torgash.landings::admin._status', ['landing' => $indexLanding])
                                    <td class="text-end whitespace-nowrap">
                                        {{-- Look, edit, and - set apart - the trash, so the
                                             harmless click and the destructive one never sit
                                             side by side. --}}
                                        <div class="inline-flex items-center gap-1">
                                            <a href="{{ route('admin.landings.preview', $indexLanding) }}" target="_blank" rel="noopener"
                                               class="btn btn-ghost btn-sm btn-square" title="{{ __('On a product it is for, as saved — even while it is switched off') }}" aria-label="{{ __('Look at it') }}">
                                                <x-icon name="external-link" />
                                            </a>
                                            <a href="{{ route('admin.landings.edit', $indexLanding) }}"
                                               class="btn btn-ghost btn-sm btn-square" title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}">
                                                <x-icon name="pencil" />
                                            </a>
                                            <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                            <form method="POST" action="{{ route('admin.landings.destroy', $indexLanding) }}" class="inline"
                                                  data-confirm="{{ __('Delete this landing and its files?') }}">
                                                @csrf @method('DELETE')
                                                <button class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}">
                                                    <x-icon name="trash-2" />
                                                </button>
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
