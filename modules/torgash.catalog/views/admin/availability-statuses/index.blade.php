@extends('admin.layout')

@section('title', __('Availability statuses'))
@section('title_hint', __('Whether a product can be bought right now — a second axis beside its Status (active / hidden). Chosen on each product, beside its stock.'))
{{-- No states to filter by: the action stands level with the heading. --}}
@section('title_actions')
    <a href="{{ route('admin.catalog.availability-statuses.create') }}" class="btn btn-primary">+ {{ __('New status') }}</a>
@endsection

@section('content')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-error">{{ session('error') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-4">

            {{-- The weights below do nothing while the tumbler is off, and a column
                 of numbers that silently does nothing is the one thing this screen
                 must not be — so it says which of the two it is, with the way to
                 the switch. --}}
            <div @class(['alert text-sm', 'alert-info' => $rankingOn, 'alert-warning' => ! $rankingOn])>
                <x-icon :name="$rankingOn ? 'arrow-down-wide-narrow' : 'circle-alert'" />
                <div>
                    @if ($rankingOn)
                        {{ __('Listings are ordered by these weights first: a higher weight comes earlier, equal weights are mixed and ordered by the sort the visitor chose.') }}
                    @else
                        {{ __('Ordering by availability is switched off, so the weights are not used yet.') }}
                    @endif
                    @can('settings.manage')
                        <a href="{{ route('admin.settings.edit', 'torgash.catalog') }}#setting-out_of_stock_last" class="link">{{ __('Catalogue settings') }}</a>
                    @endcan
                </div>
            </div>

            <form method="GET" class="flex flex-wrap items-center gap-2">
                <label class="input input-sm grow min-w-48 gap-2">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="grow" placeholder="{{ __('Search by name or code…') }}">
                </label>
                @if ($filters['q'] !== '')
                    <a href="{{ route('admin.catalog.availability-statuses.index') }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
            </form>

            <form method="POST" action="{{ route('admin.catalog.availability-statuses.weights') }}" class="flex flex-col gap-3">
                @csrf @method('PUT')
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('Status') }}</th>
                                <th>{{ __('Kind') }}</th>
                                <th class="text-center">{{ __('Can be bought') }}</th>
                                <th class="text-center">{{ __('Counts stock') }}</th>
                                <th class="text-center">{{ __('Below zero') }}</th>
                                <th class="text-end">{{ __('Products') }}</th>
                                <th class="w-32">{{ __('Weight') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($rows as $row)
                                <tr class="hover:bg-base-200/50">
                                    <td>
                                        <a href="{{ route('admin.catalog.availability-statuses.edit', $row['key']) }}" class="font-medium link link-hover">{{ $row['name'] }}</a>
                                        <div class="text-xs opacity-50 font-mono">{{ $row['key'] }}</div>
                                    </td>
                                    <td>
                                        <div class="flex flex-wrap items-center gap-1">
                                            @if ($row['source'] === 'shop')
                                                <span class="badge badge-sm badge-ghost whitespace-nowrap">{{ __('your own') }}</span>
                                            @else
                                                <span class="badge badge-sm badge-ghost whitespace-nowrap">{{ __('built in') }}</span>
                                            @endif
                                            @if ($row['renamed'])
                                                <span class="badge badge-sm badge-ghost whitespace-nowrap">{{ __('renamed') }}</span>
                                            @endif
                                        </div>
                                    </td>
                                    @foreach (['orderable', 'tracks_stock', 'allow_negative'] as $fact)
                                        <td class="text-center">
                                            @if ($fact === 'allow_negative' && ! $row['tracks_stock'])
                                                <span class="opacity-30">—</span>
                                            @elseif ($row[$fact])
                                                <x-icon name="check" class="inline-block text-success" />
                                            @else
                                                <x-icon name="x" class="inline-block opacity-40" />
                                            @endif
                                        </td>
                                    @endforeach
                                    <td class="text-end tabular-nums">
                                        {{-- The count opens the products that carry it. --}}
                                        @if ($row['products'] > 0)
                                            <a href="{{ route('admin.catalog.products.index', ['availability' => $row['key']]) }}" class="link link-hover" title="{{ __('Show these products') }}">{{ $row['products'] }}</a>
                                        @else
                                            <span class="opacity-40">0</span>
                                        @endif
                                    </td>
                                    <td>
                                        {{-- Blank = derived from "can be bought", shown as the
                                             placeholder so the number in force is always on
                                             screen. --}}
                                        <input type="number" name="weights[{{ $row['key'] }}]" step="1" min="-1000" max="1000"
                                               value="{{ $row['weighed'] ? $row['sort_weight'] : '' }}"
                                               placeholder="{{ $row['sort_weight'] }}"
                                               class="input input-bordered input-sm w-24 tabular-nums">
                                    </td>
                                    <td class="text-end whitespace-nowrap">
                                        <div class="inline-flex items-center gap-1">
                                            <a href="{{ route('admin.catalog.availability-statuses.edit', $row['key']) }}"
                                               class="btn btn-ghost btn-sm btn-square" title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}">
                                                <x-icon name="pencil" />
                                            </a>
                                            <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                            @if ($row['source'] === 'shop' && $row['products'] === 0)
                                                {{-- Beside the weights form, never inside it: a nested
                                                     form would leak _method=DELETE into "Save weights". --}}
                                                <button form="delete-status-{{ $row['key'] }}" class="btn btn-ghost btn-sm btn-square text-error"
                                                        title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}">
                                                    <x-icon name="trash-2" />
                                                </button>
                                            @else
                                                <span class="btn btn-ghost btn-sm btn-square btn-disabled"
                                                      title="{{ $row['source'] === 'shop' ? __('Set on products — give them another status first.') : __('A built-in status cannot be deleted — only renamed and weighed.') }}">
                                                    <x-icon name="trash-2" class="opacity-40" />
                                                </span>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="text-center opacity-50 py-6">{{ __('Nothing matches this search.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-wrap items-center gap-3 border-t border-base-300 pt-3">
                    <button class="btn btn-primary btn-sm">{{ __('Save weights') }}</button>
                    <span class="text-xs opacity-60">
                        {{ __('A blank weight is 10 for a status that can be bought and 0 for one that cannot. A product whose stock ran out ranks as Out of stock, because that is what it can be bought as.') }}
                    </span>
                </div>
            </form>
        </div>
    </div>

    @foreach ($rows as $row)
        @if ($row['source'] === 'shop' && $row['products'] === 0)
            <form id="delete-status-{{ $row['key'] }}" method="POST" class="hidden"
                  action="{{ route('admin.catalog.availability-statuses.destroy', $row['key']) }}"
                  data-confirm="{{ __('Delete this status?') }}">
                @csrf @method('DELETE')
            </form>
        @endif
    @endforeach

    @if ($orphans !== [])
        <div class="alert alert-warning text-sm flex-col items-start gap-1">
            <div class="font-semibold">{{ __('Products carry a status nobody answers for any more') }}</div>
            <div>{{ __('Its module was removed or the status deleted, so these products cannot be bought and rank last. Give them a status that exists.') }}</div>
            <div class="flex flex-wrap gap-2">
                @foreach ($orphans as $orphanKey => $n)
                    <span class="badge badge-sm badge-ghost font-mono">{{ $orphanKey }} · {{ $n }}</span>
                @endforeach
            </div>
        </div>
    @endif
@endsection
