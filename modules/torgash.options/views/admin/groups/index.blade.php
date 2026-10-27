@extends('admin.layout')

@section('title', __('Option groups'))

@section('content')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @php
        $locale = admin_content_locale();
        // A tab keeps the other filters: switching "shared" must not forget the search.
        $tabUrl = fn (array $change) => route('admin.options.groups.index', array_filter(array_merge($filters, $change), fn ($v) => $v !== null && $v !== ''));
    @endphp

    {{-- One Alpine root: the bulk bar and every row's checkbox share
         `selected` — the categories list's own arrangement. --}}
    <div class="card bg-base-100 border border-base-300" x-data="{ selected: [] }">
        <div class="card-body gap-4">
            <div class="flex justify-between items-start gap-3 flex-wrap">
                <p class="text-sm opacity-70 max-w-3xl">
                    {{ __('What a product offers beyond itself: a processor to pick, licences to tick, a warranty, an installation. A group with no product of its own is placed by conditions — a category with everything under it, a characteristic — and prices itself by formula; a group written for one product is edited on that product.') }}
                </p>
                <a href="{{ route('admin.options.groups.create') }}" class="btn btn-primary btn-sm">+ {{ __('New group') }}</a>
            </div>

            <form method="GET" class="flex flex-wrap items-center gap-2">
                @foreach (['whose', 'status'] as $kept)
                    @if ($filters[$kept])
                        <input type="hidden" name="{{ $kept }}" value="{{ $filters[$kept] }}">
                    @endif
                @endforeach
                <label class="input input-sm w-full sm:w-72 gap-2">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="grow" placeholder="{{ __('Search by name or product…') }}">
                </label>
                <div class="join">
                    @foreach (['' => [__('All'), $counts->all_n], 'shared' => [__('Shared'), $counts->shared_n], 'product' => [__('One product\'s'), $counts->product_n]] as $whoseKey => [$whoseText, $whoseN])
                        <a href="{{ $tabUrl(['whose' => $whoseKey]) }}"
                           @class(['btn btn-sm join-item', 'btn-active' => (string) $filters['whose'] === $whoseKey])>
                            {{ $whoseText }} <span class="opacity-50">{{ $whoseN }}</span>
                        </a>
                    @endforeach
                </div>
                <div class="join">
                    @foreach (['' => [__('Any status'), null], 'enabled' => [__('Enabled'), $counts->enabled_n], 'disabled' => [__('Disabled'), $counts->disabled_n]] as $statusKey => [$statusText, $statusN])
                        <a href="{{ $tabUrl(['status' => $statusKey]) }}"
                           @class(['btn btn-sm join-item', 'btn-active' => (string) $filters['status'] === $statusKey])>
                            {{ $statusText }} @if ($statusN !== null)<span class="opacity-50">{{ $statusN }}</span>@endif
                        </a>
                    @endforeach
                </div>
                @if ($filters['q'] !== '' || $filters['whose'] || $filters['status'])
                    <a href="{{ route('admin.options.groups.index') }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
            </form>

            <div class="min-h-12 flex items-center">
                <p x-show="!selected.length" class="text-xs opacity-50">
                    {{ __('Tick options to switch several on or off at once, or delete them.') }}
                </p>
                <form method="POST" action="{{ route('admin.options.groups.bulk') }}"
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
                            data-confirm="{{ __('Delete the selected options and their choices?') }}">
                        <x-icon name="trash-2" size="sm" /> {{ __('Delete') }}
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm ml-auto" x-on:click="selected = []">{{ __('Clear selection') }}</button>
                </form>
            </div>

            @if ($groups->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="list-checks" size="xl" class="opacity-40" />
                    <p class="mt-2">{{ $filters['q'] !== '' || $filters['whose'] || $filters['status'] ? __('No options match this search.') : __('No option groups yet.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="w-10">
                                    <input type="checkbox" class="checkbox checkbox-sm" aria-label="{{ __('Select all on this page') }}"
                                           x-on:change="selected = $event.target.checked ? @js($groups->pluck('id')->map(fn ($id) => (string) $id)) : []"
                                           :checked="selected.length === {{ $groups->count() }}">
                                </th>
                                <th>{{ __('Option') }}</th>
                                <th>{{ __('Where') }}</th>
                                <th>{{ __('Shown as') }}</th>
                                <th class="text-end">{{ __('Choices') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($groups as $group)
                                <tr class="hover:bg-base-200/50" :class="selected.includes('{{ $group->id }}') && 'bg-primary/5'">
                                    <td>
                                        <input type="checkbox" class="checkbox checkbox-sm" value="{{ $group->id }}" x-model="selected"
                                               aria-label="{{ $group->admin_name }}">
                                    </td>
                                    <td>
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <a href="{{ route('admin.options.groups.edit', $group) }}" @class(['font-medium link link-hover', 'opacity-60' => ! $group->enabled])>
                                                {{ $group->admin_name }}
                                            </a>
                                            @if ($group->required)
                                                <span class="badge badge-xs badge-outline badge-warning whitespace-nowrap">{{ __('Required') }}</span>
                                            @endif
                                            @if ($group->depends_on_group_id)
                                                <span class="badge badge-xs badge-ghost whitespace-nowrap" title="{{ __('Asked only after another option is answered') }}">{{ __('depends') }}</span>
                                            @endif
                                        </div>
                                        <div class="text-xs opacity-50">{{ __('The customer sees:') }} {{ $group->t('name', $locale) ?: '—' }}</div>
                                    </td>
                                    <td class="text-sm">
                                        @if ($group->product)
                                            <a href="{{ route('admin.catalog.products.edit', $group->product) }}" class="link link-hover inline-flex items-center gap-1">
                                                <x-icon name="package" size="sm" class="opacity-50" />
                                                {{ $group->product->t('name', $locale) ?: $group->product->sku }}
                                            </a>
                                        @elseif (empty($group->rules))
                                            <span class="opacity-70">{{ __('Every product') }}</span>
                                        @else
                                            <span class="opacity-70">{{ trans_choice(':count condition|:count conditions', count($group->rules), ['count' => count($group->rules)]) }}</span>
                                        @endif
                                    </td>
                                    <td class="text-sm opacity-70">{{ $group->typeLabel() }}</td>
                                    <td class="text-end tabular-nums">
                                        @if ($group->choices_count)
                                            {{ $group->choices_count }}
                                        @else
                                            {{-- An option with no choices is not offered — worth a word. --}}
                                            <span class="text-warning" title="{{ __('An option with no choices is not offered at all.') }}">0</span>
                                        @endif
                                    </td>
                                    @include('torgash.options::admin.groups._status', ['group' => $group])
                                    <td class="text-end whitespace-nowrap">
                                        <div class="inline-flex items-center gap-1">
                                            <a href="{{ route('admin.options.groups.edit', $group) }}"
                                               class="btn btn-ghost btn-sm btn-square" title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}">
                                                <x-icon name="pencil" />
                                            </a>
                                            <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                            {{-- Beside the table, never inside a row's own form. --}}
                                            <button form="delete-group-{{ $group->id }}" class="btn btn-ghost btn-sm btn-square text-error"
                                                    title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}">
                                                <x-icon name="trash-2" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                {{ $groups->links() }}
            @endif
        </div>
    </div>

    @foreach ($groups as $group)
        <form id="delete-group-{{ $group->id }}" method="POST" class="hidden"
              action="{{ route('admin.options.groups.destroy', $group) }}"
              data-confirm="{{ __('Delete this option and its choices?') }}">
            @csrf @method('DELETE')
        </form>
    @endforeach
@endsection
