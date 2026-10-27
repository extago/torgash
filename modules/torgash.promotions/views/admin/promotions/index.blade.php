@extends('admin.layout')

@section('title', __('Promotions'))

@section('content')
    @php
        use Torgash\Promotions\Conditions;
        use Torgash\Promotions\Models\PromotionTier;

        // One icon per discount type — the fastest way to scan a column of
        // tiers for "what kind of sale is this" without reading every line.
        $tierIcon = fn (string $type) => match ($type) {
            PromotionTier::PERCENT => 'percent',
            PromotionTier::FIXED => 'banknote',
            PromotionTier::TO_PERCENT => 'circle-percent',
            PromotionTier::TO_AMOUNT => 'tag',
            PromotionTier::FREE_SHIPPING => 'truck',
            PromotionTier::GIVE_GROUP => 'user-check',
            PromotionTier::FREE_PRODUCT => 'gift',
            default => 'circle',
        };

        $stateTabs = [
            '' => __('All'),
            'running' => __('running'),
            'scheduled' => __('scheduled'),
            'paused' => __('paused'),
            'ended' => __('ended'),
            'off' => __('off'),
        ];
        $isFiltered = $filters['q'] !== '' || $filters['state'] !== '' || $filters['zone'] !== '';
    @endphp

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
                    {{ __('Discounts that change a product\'s price in the catalogue, or take something off the order in the cart. Each promotion is a list of tiers tried top to bottom; dates and a repeating window say when it runs.') }}
                </p>
                <div class="flex items-center gap-2">
                    <a href="{{ route('admin.promotions.usage') }}" class="btn btn-sm gap-1.5"><x-icon name="list-checks" size="sm" /> {{ __('Usage log') }}</a>
                    <a href="{{ route('admin.promotions.create') }}" class="btn btn-primary btn-sm">+ {{ __('New promotion') }}</a>
                </div>
            </div>

            <form method="GET" class="flex flex-wrap items-center gap-2">
                <label class="input input-sm w-full sm:w-72 gap-2">
                    <x-icon name="search" size="sm" class="opacity-50" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="grow" placeholder="{{ __('Search by name…') }}">
                </label>
                <select name="zone" class="select select-sm w-auto" data-submit-on-change aria-label="{{ __('Zone') }}">
                    <option value="">{{ __('Catalogue and cart') }}</option>
                    <option value="catalog" @selected($filters['zone'] === 'catalog')>{{ __('Catalog') }}</option>
                    <option value="cart" @selected($filters['zone'] === 'cart')>{{ __('Cart') }}</option>
                </select>
                {{-- The state is part of what the form asks; the tabs below are
                     LINKS carrying every other filter — one control, not two. --}}
                <input type="hidden" name="state" value="{{ $filters['state'] }}">
                @if ($isFiltered)
                    <a href="{{ route('admin.promotions.index') }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
                @endif
            </form>

            <div class="join flex-wrap -mt-1">
                @foreach ($stateTabs as $stateKey => $stateLabel)
                    <a href="{{ request()->fullUrlWithQuery(['state' => $stateKey ?: null]) }}"
                       @class(['btn btn-sm join-item', 'btn-active' => $filters['state'] === $stateKey])>
                        {{ \Illuminate\Support\Str::ucfirst($stateLabel) }} <span class="opacity-50">{{ $stateCounts[$stateKey] }}</span>
                    </a>
                @endforeach
            </div>

            <div class="min-h-12 flex items-center">
                <p x-show="!selected.length" class="text-xs opacity-50">
                    {{ __('Tick promotions to switch several on or off at once, or delete them.') }}
                </p>
                <form method="POST" action="{{ route('admin.promotions.bulk') }}"
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
                            data-confirm="{{ __('Delete the selected promotions? Orders that used them keep what they were charged.') }}">
                        <x-icon name="trash-2" size="sm" /> {{ __('Delete') }}
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm ml-auto" x-on:click="selected = []">{{ __('Clear selection') }}</button>
                </form>
            </div>

            @if ($promotions->isEmpty())
                <div class="text-center py-12 opacity-60">
                    <x-icon name="badge-percent" size="xl" class="opacity-40" />
                    <p class="mt-2">{{ $isFiltered ? __('No promotions match this search.') : __('No promotions yet — prices and totals are exactly what you typed.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="w-10">
                                    <input type="checkbox" class="checkbox checkbox-sm" aria-label="{{ __('Select all on this page') }}"
                                           x-on:change="selected = $event.target.checked ? @js($promotions->pluck('id')->map(fn ($id) => (string) $id)) : []"
                                           :checked="selected.length === {{ $promotions->count() }}">
                                </th>
                                <th>{{ __('Name') }}</th>
                                <th>{{ __('Tiers') }}</th>
                                <th>{{ __('Combine') }}</th>
                                <th class="text-end">{{ __('Used') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($promotions as $promotion)
                                @php $promotionName = $promotion->t('name', admin_content_locale()) ?: __('(untitled)'); @endphp
                                <tr class="hover:bg-base-200/50 align-top" :class="selected.includes('{{ $promotion->id }}') && 'bg-primary/5'">
                                    <td>
                                        <input type="checkbox" class="checkbox checkbox-sm" value="{{ $promotion->id }}" x-model="selected" aria-label="{{ $promotionName }}">
                                    </td>
                                    <td class="min-w-56">
                                        <a href="{{ route('admin.promotions.edit', $promotion) }}"
                                           @class(['font-medium link link-hover', 'opacity-60' => in_array($promotion->status(), ['ended', 'off'], true)])>{{ $promotionName }}</a>
                                        <div class="flex items-center gap-1.5 mt-1 flex-wrap">
                                            <span class="badge badge-sm badge-ghost gap-1">
                                                <x-icon :name="$promotion->zone === 'catalog' ? 'tag' : 'shopping-cart'" size="sm" />
                                                {{ $promotion->zone === 'catalog' ? __('Catalog') : __('Cart') }}
                                            </span>
                                            @if ($promotion->priority)
                                                <span class="badge badge-sm badge-ghost" title="{{ __('Priority') }}">{{ __('priority') }} {{ $promotion->priority }}</span>
                                            @endif
                                            @if ($promotion->published && $promotion->slug)
                                                <span class="badge badge-sm badge-ghost gap-1" title="{{ __('Has a page on the storefront') }}"><x-icon name="globe" size="sm" /> {{ __('page') }}</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="text-sm">
                                        @forelse ($promotion->tiers as $tier)
                                            <div class="flex items-center gap-1.5">
                                                <x-icon :name="$tierIcon($tier->discount_type)" size="sm" class="opacity-50 shrink-0" />
                                                <span>
                                                    {{ Conditions::describe($tier->rules(), $promotion->zone) }}
                                                    —
                                                    <span class="font-medium">
                                                        @if ($tier->discount_type === PromotionTier::FREE_SHIPPING)
                                                            {{ __('free shipping') }}
                                                        @elseif ($tier->discount_type === PromotionTier::PERCENT)
                                                            −{{ $tier->discount_value }}%
                                                        @elseif ($tier->discount_type === PromotionTier::TO_PERCENT)
                                                            {{ __('price becomes :percent% of it', ['percent' => $tier->discount_value]) }}
                                                        @elseif ($tier->discount_type === PromotionTier::TO_AMOUNT)
                                                            {{ __('price becomes :price', ['price' => admin_price($tier->discount_value)]) }}
                                                        @elseif ($tier->discount_type === PromotionTier::GIVE_GROUP)
                                                            {{ __('upgrade to :group', ['group' => $groupNames[$tier->give_group_id] ?? '#'.$tier->give_group_id]) }}
                                                        @elseif ($tier->discount_type === PromotionTier::FREE_PRODUCT)
                                                            {{ __('gift: :sku ×:qty', ['sku' => $productSkus[$tier->free_product_id] ?? '#'.$tier->free_product_id, 'qty' => $tier->free_qty]) }}
                                                        @else
                                                            −{{ admin_price($tier->discount_value) }}
                                                        @endif
                                                    </span>
                                                </span>
                                            </div>
                                        @empty
                                            <span class="badge badge-sm badge-warning">{{ __('No tiers — matches nothing.') }}</span>
                                        @endforelse
                                    </td>
                                    <td class="text-sm whitespace-nowrap">
                                        {{ match($promotion->combine) {
                                            'stack_add' => __('Stacks (add)'),
                                            'stack_multiply' => __('Stacks (multiply)'),
                                            default => __('Replace'),
                                        } }}
                                    </td>
                                    {{-- Only the cart zone ever records a redemption (a catalog
                                         promotion changes a price and nobody "uses" it), so a
                                         count there would be a permanent zero pretending to
                                         mean something. --}}
                                    <td class="text-sm whitespace-nowrap text-end tabular-nums">
                                        @if ($promotion->zone === 'cart')
                                            <a href="{{ route('admin.promotions.usage', ['promotion' => $promotion->id]) }}"
                                               @class(['link link-hover', 'text-warning font-medium' => $promotion->usage_limit && $promotion->used_count >= $promotion->usage_limit])>
                                                {{ $promotion->used_count }}{{ $promotion->usage_limit ? ' / '.$promotion->usage_limit : '' }}
                                            </a>
                                        @else
                                            <span class="opacity-30">—</span>
                                        @endif
                                    </td>
                                    @include('torgash.promotions::admin.promotions._status', ['promotion' => $promotion])
                                    <td class="text-end whitespace-nowrap">
                                        {{-- Preview (the sale's page, when it has one), copy, edit —
                                             and, set apart, delete. --}}
                                        <div class="inline-flex items-center gap-1">
                                            @if ($promotion->published && $promotion->slug)
                                                <a href="{{ route('promotions.show', $promotion->slug) }}" target="_blank" rel="noopener"
                                                   class="btn btn-ghost btn-sm btn-square" title="{{ __('Open the page on the storefront') }}" aria-label="{{ __('Open the page on the storefront') }}">
                                                    <x-icon name="external-link" />
                                                </a>
                                            @else
                                                <span class="btn btn-ghost btn-sm btn-square invisible" aria-hidden="true"></span>
                                            @endif
                                            <form method="POST" action="{{ route('admin.promotions.duplicate', $promotion) }}" class="inline">
                                                @csrf
                                                <button class="btn btn-ghost btn-sm btn-square" title="{{ __('Make a copy') }}" aria-label="{{ __('Make a copy') }}">
                                                    <x-icon name="copy" />
                                                </button>
                                            </form>
                                            <a href="{{ route('admin.promotions.edit', $promotion) }}" class="btn btn-ghost btn-sm btn-square"
                                               title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}">
                                                <x-icon name="pencil" />
                                            </a>
                                            <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                            <form method="POST" action="{{ route('admin.promotions.destroy', $promotion) }}" class="inline"
                                                  data-confirm="{{ __('Delete this promotion?') }}">
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
