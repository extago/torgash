@extends('admin.layout')

@section('title', __('Price types'))
@section('title_hint', __('Named prices worked out from the base one: wholesale, dealer, club. The base type is the price the storefront already charges. Another type is shown to the customer groups it names, can be advertised beside the regular price, and can be the price an export or a feed is pinned to.'))

@section('content')
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @php $locale = admin_content_locale(); @endphp

    {{-- The states as one row of tabs over the card, the screen's action at
         its end (app.css, `admin-tabs`); LINKS carrying the search. --}}
    <div class="admin-tabs-row">
        <nav class="admin-tabs" aria-label="{{ __('Price types') }}">
            @foreach (['' => __('All'), 'enabled' => __('Enabled'), 'disabled' => __('Disabled')] as $statusKey => $statusLabel)
                <a href="{{ route('admin.catalog.price-types.index', array_filter(['status' => $statusKey, 'q' => $filters['q']], fn ($v) => $v !== null && $v !== '')) }}"
                   @class(['admin-tab', 'is-active' => (string) $filters['status'] === (string) $statusKey])>
                    {{ $statusLabel }} <span class="admin-tab-count">{{ $statusCounts[$statusKey] }}</span>
                </a>
            @endforeach
        </nav>
        <div class="admin-tabs-actions">
            <a href="{{ route('admin.catalog.price-types.create') }}" class="btn btn-primary">+ {{ __('New price type') }}</a>
        </div>
    </div>

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-4">
            <form method="GET" class="flex flex-wrap items-center gap-2">
                @if ($filters['status'])
                    <input type="hidden" name="status" value="{{ $filters['status'] }}">
                @endif
                <label class="input input-sm grow min-w-48 gap-2">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="grow" placeholder="{{ __('Search by name or code…') }}">
                </label>
                @if ($filters['q'] !== '' || $filters['status'])
                    <a href="{{ route('admin.catalog.price-types.index') }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
            </form>

            @if ($priceTypes->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="badge-percent" size="xl" class="opacity-40" />
                    <p class="mt-2">{{ $filters['q'] !== '' || $filters['status'] ? __('No price types match this search.') : __('No price types yet.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('Name') }}</th>
                                <th>{{ __('Price') }}</th>
                                <th>{{ __('Who gets it') }}</th>
                                <th>{{ __('On the storefront') }}</th>
                                <th class="text-end">{{ __('Sort order') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($priceTypes as $priceType)
                                <tr class="hover:bg-base-200/50">
                                    <td>
                                        <div class="flex items-center gap-2">
                                            <a href="{{ route('admin.catalog.price-types.edit', $priceType) }}" @class(['font-medium link link-hover', 'opacity-60' => ! $priceType->enabled])>
                                                {{ $priceType->t('name', $locale) ?: '—' }}
                                            </a>
                                            @if ($priceType->is_base)
                                                <span class="badge badge-sm badge-primary">{{ __('base') }}</span>
                                            @endif
                                        </div>
                                        <div class="text-xs opacity-50 font-mono">{{ $priceType->code }}</div>
                                    </td>
                                    <td class="opacity-80">
                                        @if ($priceType->is_base)
                                            <span class="opacity-60">{{ __('the storefront\'s own') }}</span>
                                        @else
                                            @if ($priceType->adjustment_percent !== null)
                                                <span class="tabular-nums">{{ $priceType->adjustment_percent > 0 ? '+' : '' }}{{ $priceType->adjustment_percent }}%</span>
                                            @else
                                                <span class="opacity-60">{{ __('as base') }}</span>
                                            @endif
                                            @if ($priceType->markup_rules_count)
                                                <div class="text-xs opacity-60">{{ trans_choice(':count markup rule|:count markup rules', $priceType->markup_rules_count, ['count' => $priceType->markup_rules_count]) }}</div>
                                            @endif
                                        @endif
                                    </td>
                                    <td class="text-sm opacity-80">
                                        @if ($priceType->is_base)
                                            {{ __('everyone') }}
                                        @else
                                            {{ $priceType->customerGroups->isEmpty() ? __('every group') : $priceType->customerGroups->map(fn ($g) => $g->t('name', $locale))->implode(', ') }}
                                            @if ($priceType->requires_auth)
                                                <div class="text-xs opacity-60">{{ __('signed in only') }}</div>
                                            @endif
                                        @endif
                                    </td>
                                    <td class="text-sm">
                                        @if (! $priceType->is_base && $priceType->show_on_storefront)
                                            <span class="inline-flex items-center gap-1"><x-icon name="eye" size="sm" class="opacity-60" /> {{ __('beside the price') }}</span>
                                        @else
                                            <span class="opacity-40">—</span>
                                        @endif
                                    </td>
                                    <td class="text-end tabular-nums opacity-70">{{ $priceType->sort }}</td>
                                    @include('torgash.catalog::admin.price-types._status', ['priceType' => $priceType])
                                    <td class="text-end whitespace-nowrap">
                                        <div class="inline-flex items-center gap-1">
                                            <a href="{{ route('admin.catalog.price-types.edit', $priceType) }}"
                                               class="btn btn-ghost btn-sm btn-square" title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}">
                                                <x-icon name="pencil" />
                                            </a>
                                            <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                            @if ($priceType->is_base)
                                                {{-- The base type IS the storefront's price; there is nothing to delete. --}}
                                                <span class="btn btn-ghost btn-sm btn-square btn-disabled" title="{{ __('The base price type cannot be deleted.') }}">
                                                    <x-icon name="trash-2" class="opacity-40" />
                                                </span>
                                            @else
                                                <form method="POST" action="{{ route('admin.catalog.price-types.destroy', $priceType) }}" class="inline"
                                                      data-confirm="{{ __('Delete this price type? Export profiles pinned to it fall back to the base price.') }}">
                                                    @csrf @method('DELETE')
                                                    <button class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}">
                                                        <x-icon name="trash-2" />
                                                    </button>
                                                </form>
                                            @endif
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
