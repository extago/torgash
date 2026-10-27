@extends('admin.layout')

@section('title', __('Taxes'))

@section('content')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    {{-- One Alpine root: the bulk bar and every row's checkbox share
         `selected` — the categories list's own arrangement. --}}
    <div class="card bg-base-100 border border-base-300" x-data="{ selected: [] }">
        <div class="card-body gap-4">
            <div class="flex justify-between items-start gap-3 flex-wrap">
                <p class="text-sm opacity-70 max-w-3xl">
                    {{ __('VAT and sales tax. A tax is either included in the price — the price on the shelf is final and the tax is extracted from it — or added on top at checkout; a shop can have both. Until a tax exists, prices are charged exactly as entered.') }}
                </p>
                <a href="{{ route('admin.taxes.create') }}" class="btn btn-primary btn-sm">+ {{ __('New tax') }}</a>
            </div>

            <form method="GET" class="flex flex-wrap items-center gap-2">
                <label class="input input-sm w-full sm:w-72 gap-2">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="grow" placeholder="{{ __('Name or code…') }}">
                </label>
                <div class="join">
                    @foreach (['' => __('All'), 'enabled' => __('Enabled'), 'disabled' => __('Disabled')] as $statusKey => $statusLabel)
                        <button type="submit" name="status" value="{{ $statusKey }}"
                                @class(['btn btn-sm join-item', 'btn-active' => (string) $filters['status'] === (string) $statusKey])>
                            {{ $statusLabel }} <span class="opacity-50">{{ $statusCounts[$statusKey] }}</span>
                        </button>
                    @endforeach
                </div>
                @if ($filters['q'] !== '' || $filters['status'])
                    <a href="{{ route('admin.taxes.index') }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
            </form>

            <div class="min-h-12 flex items-center">
                <p x-show="!selected.length" class="text-xs opacity-50">
                    {{ __('Tick taxes to switch several on or off at once, or delete them.') }}
                </p>
                <form method="POST" action="{{ route('admin.taxes.bulk') }}"
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
                            data-confirm="{{ __('Delete the selected taxes? Orders that already charged them keep their own copy.') }}">
                        <x-icon name="trash-2" size="sm" /> {{ __('Delete') }}
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm ml-auto" x-on:click="selected = []">{{ __('Clear selection') }}</button>
                </form>
            </div>

            @if ($taxes->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="percent" size="xl" class="opacity-40" />
                    <p class="mt-2">
                        {{ $filters['q'] !== '' || $filters['status'] ? __('No taxes match this search.') : __('No taxes yet. Until one exists, prices are charged exactly as entered.') }}
                    </p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="w-10">
                                    <input type="checkbox" class="checkbox checkbox-sm" aria-label="{{ __('Select all on this page') }}"
                                           x-on:change="selected = $event.target.checked ? @js($taxes->pluck('id')->map(fn ($id) => (string) $id)) : []"
                                           :checked="selected.length === {{ $taxes->count() }}">
                                </th>
                                <th>{{ __('Name') }}</th>
                                <th>{{ __('Rate') }}</th>
                                <th>{{ __('Charged') }}</th>
                                <th>{{ __('Applies to') }}</th>
                                <th class="text-end">{{ __('Orders') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($taxes as $tax)
                                @php $charged = (int) ($orderCounts[$tax->id] ?? 0); @endphp
                                <tr class="hover:bg-base-200/50" :class="selected.includes('{{ $tax->id }}') && 'bg-primary/5'">
                                    <td>
                                        <input type="checkbox" class="checkbox checkbox-sm" value="{{ $tax->id }}" x-model="selected"
                                               aria-label="{{ $tax->t('name', admin_content_locale()) }}">
                                    </td>
                                    <td>
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <a href="{{ route('admin.taxes.edit', $tax) }}"
                                               @class(['font-medium link link-hover', 'opacity-60' => ! $tax->enabled])>{{ $tax->t('name', admin_content_locale()) ?: $tax->code }}</a>
                                            @if ($tax->priority > 0)
                                                <span class="badge badge-sm badge-ghost whitespace-nowrap" title="{{ __('Priority') }}">{{ __('priority') }} {{ $tax->priority }}</span>
                                            @endif
                                        </div>
                                        <div class="text-xs opacity-50 font-mono">{{ $tax->code }}</div>
                                    </td>
                                    <td class="whitespace-nowrap">
                                        <span class="font-medium tabular-nums">{{ $tax->only_listed ? '—' : $tax->rateLabel() }}</span>
                                        @if ($tax->rates_count > 0)
                                            <div class="text-xs opacity-60 inline-flex items-center gap-1">
                                                <x-icon name="map-pin" size="sm" />
                                                {{ trans_choice(':count destination|:count destinations', $tax->rates_count) }}
                                                @if ($tax->only_listed)
                                                    · {{ __('only there') }}
                                                @endif
                                            </div>
                                        @endif
                                    </td>
                                    <td class="text-sm">
                                        <span class="inline-flex items-center gap-1.5">
                                            <x-icon :name="$tax->price_includes_tax ? 'tag' : 'circle-plus'" size="sm" class="opacity-60" />
                                            {{ $tax->price_includes_tax ? __('included in the price') : __('added at checkout') }}
                                        </span>
                                    </td>
                                    <td class="text-sm">
                                        {{ $tax->applies_by_default ? __('all products') : __('selected products') }}
                                        @if ($tax->applies_to_shipping)
                                            <span class="badge badge-sm badge-ghost whitespace-nowrap">{{ __('+ shipping') }}</span>
                                        @endif
                                        @if (($usage[$tax->id] ?? 0) > 0)
                                            <div class="text-xs opacity-50">{{ __(':n with their own choice', ['n' => $usage[$tax->id]]) }}</div>
                                        @endif
                                    </td>
                                    <td class="text-end tabular-nums">
                                        @if ($charged)
                                            {{ $charged }}
                                        @else
                                            <span class="opacity-40">0</span>
                                        @endif
                                    </td>
                                    @include('torgash.taxes::admin._status', ['tax' => $tax])
                                    <td class="text-end whitespace-nowrap">
                                        {{-- Edit, and — set apart — delete. A tax has no page of
                                             its own, so there is no preview. --}}
                                        <div class="inline-flex items-center gap-1">
                                            <a href="{{ route('admin.taxes.edit', $tax) }}" class="btn btn-ghost btn-sm btn-square"
                                               title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}">
                                                <x-icon name="pencil" />
                                            </a>
                                            <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                            <form method="POST" action="{{ route('admin.taxes.destroy', $tax) }}" class="inline"
                                                  data-confirm="{{ __('Delete this tax? Orders that already charged it keep their own copy.') }}">
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
