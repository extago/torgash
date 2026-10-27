@extends('admin.layout')

@section('title', __('Product info blocks'))

@section('content')
    @php
        $stateTabs = ['' => __('All'), 'on' => __('Card shown'), 'off' => __('Card hidden')];
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
                    {{ __('Accordion cards shown below the buy panel on the product page — delivery, payment, warranty, whatever helps close the sale. Multiple cards can be open at once.') }}
                </p>
                <a href="{{ route('admin.motivation.create') }}" class="btn btn-primary btn-sm">+ {{ __('New card') }}</a>
            </div>

            <form method="GET" class="flex flex-wrap items-center gap-2">
                <label class="input input-sm w-full sm:w-72 gap-2">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="grow" placeholder="{{ __('Search by title…') }}">
                </label>
                <input type="hidden" name="state" value="{{ $filters['state'] }}">
                @if ($isFiltered)
                    <a href="{{ route('admin.motivation.index') }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
            </form>

            <div class="join flex-wrap -mt-1">
                @foreach ($stateTabs as $stateKey => $stateLabel)
                    <a href="{{ request()->fullUrlWithQuery(['state' => $stateKey ?: null]) }}"
                       @class(['btn btn-sm join-item', 'btn-active' => $filters['state'] === $stateKey])>
                        {{ $stateLabel }} <span class="opacity-50">{{ $stateCounts[$stateKey] }}</span>
                    </a>
                @endforeach
            </div>

            <div class="min-h-12 flex items-center">
                <p x-show="!selected.length" class="text-xs opacity-50">
                    {{ __('Tick cards to show or hide several at once, or delete them.') }}
                </p>
                <form method="POST" action="{{ route('admin.motivation.bulk') }}"
                      x-show="selected.length" x-cloak
                      class="w-full flex flex-wrap items-center gap-2 bg-base-200 rounded-box p-2">
                    @csrf
                    <template x-for="id in selected" :key="id">
                        <input type="hidden" name="ids[]" :value="id">
                    </template>
                    <span class="text-sm px-1"><span x-text="selected.length"></span> {{ __('selected') }}</span>
                    <div class="join">
                        <button name="action" value="enable" class="btn btn-sm join-item">{{ __('Show') }}</button>
                        <button name="action" value="disable" class="btn btn-sm join-item">{{ __('Hide') }}</button>
                    </div>
                    <button name="action" value="delete" class="btn btn-sm btn-error btn-outline gap-1.5"
                            data-confirm="{{ __('Delete the selected cards?') }}">
                        <x-icon name="trash-2" size="sm" /> {{ __('Delete') }}
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm ml-auto" x-on:click="selected = []">{{ __('Clear selection') }}</button>
                </form>
            </div>

            @if ($items->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="panel-top-open" size="xl" class="opacity-40" />
                    <p class="mt-2">{{ $isFiltered ? __('No cards match this search.') : __('No cards yet.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="w-10">
                                    <input type="checkbox" class="checkbox checkbox-sm" aria-label="{{ __('Select all on this page') }}"
                                           x-on:change="selected = $event.target.checked ? @js($items->pluck('id')->map(fn ($id) => (string) $id)) : []"
                                           :checked="selected.length === {{ $items->count() }}">
                                </th>
                                <th class="w-16">{{ __('Sort order') }}</th>
                                <th>{{ __('Title') }}</th>
                                <th>{{ __('Content') }}</th>
                                <th>{{ __('Shown on') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($items as $item)
                                @php
                                    $scopeParts = [];
                                    if ($item->categories->isNotEmpty()) {
                                        $scopeParts[] = ($item->exclude_categories ? __('all but :n categories', ['n' => $item->categories->count()]) : __(':n categories', ['n' => $item->categories->count()]));
                                    }
                                    if ($item->products->isNotEmpty()) {
                                        $scopeParts[] = ($item->exclude_products ? __('all but :n products', ['n' => $item->products->count()]) : __(':n products', ['n' => $item->products->count()]));
                                    }
                                    if ($item->customerGroups->isNotEmpty()) {
                                        $scopeParts[] = __(':n groups', ['n' => $item->customerGroups->count()]);
                                    }
                                @endphp
                                <tr class="hover:bg-base-200/50" :class="selected.includes('{{ $item->id }}') && 'bg-primary/5'">
                                    <td>
                                        <input type="checkbox" class="checkbox checkbox-sm" value="{{ $item->id }}" x-model="selected" aria-label="{{ $item->t('name', admin_content_locale()) }}">
                                    </td>
                                    <td class="tabular-nums opacity-60">{{ $item->sort }}</td>
                                    <td>
                                        <a href="{{ route('admin.motivation.edit', $item) }}" class="flex items-center gap-3 group">
                                            <span class="w-10 h-10 shrink-0 rounded-box bg-base-200 grid place-items-center">
                                                @if ($item->icon_type === 'lucide' && $item->icon_name)
                                                    <x-icon :name="$item->icon_name" size="lg" class="{{ $item->icon_color ? 'text-'.$item->icon_color : 'opacity-60' }}" />
                                                @elseif ($item->icon_type === 'image' && $item->iconUrl())
                                                    <img src="{{ $item->iconUrl() }}" class="h-6 w-6 object-contain" alt="">
                                                @else
                                                    <x-icon name="panel-top-open" class="opacity-25" />
                                                @endif
                                            </span>
                                            <span @class(['font-medium group-hover:underline', 'opacity-60' => ! $item->enabled])>{{ $item->t('name', admin_content_locale()) ?: '#'.$item->id }}</span>
                                        </a>
                                    </td>
                                    <td class="text-sm opacity-70">
                                        {{ match ($item->content_type) {
                                            'html' => __('Custom text'),
                                            'categories' => __("Product's categories"),
                                            'payment_methods' => __('Payment methods'),
                                            'shipping_methods' => __('Shipping methods'),
                                            default => $item->content_type,
                                        } }}
                                    </td>
                                    <td class="text-sm opacity-70">{{ $scopeParts ? implode(', ', $scopeParts) : __('everywhere') }}</td>
                                    @include('torgash.motivation::admin._status', ['item' => $item])
                                    <td class="text-end whitespace-nowrap">
                                        <div class="inline-flex items-center gap-1">
                                            <form method="POST" action="{{ route('admin.motivation.duplicate', $item) }}" class="inline">
                                                @csrf
                                                <button class="btn btn-ghost btn-sm btn-square" title="{{ __('Duplicate') }}" aria-label="{{ __('Duplicate') }}"><x-icon name="copy" /></button>
                                            </form>
                                            <a href="{{ route('admin.motivation.edit', $item) }}" class="btn btn-ghost btn-sm btn-square"
                                               title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"><x-icon name="pencil" /></a>
                                            <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                            <form method="POST" action="{{ route('admin.motivation.destroy', $item) }}" class="inline"
                                                  data-confirm="{{ __('Delete this card?') }}">
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
