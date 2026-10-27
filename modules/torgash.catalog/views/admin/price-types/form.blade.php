@extends('admin.layout')

@section('title', $priceType->exists ? __('Edit price type') : __('New price type'))
@section('own_heading', '1')

@section('content')
    @php
        $locale = admin_content_locale();
        $selectedGroups = array_map('intval', old('customer_groups', $priceType->exists ? $priceType->customerGroups->pluck('id')->all() : []));
    @endphp

    <form method="POST"
          action="{{ $priceType->exists ? route('admin.catalog.price-types.update', $priceType) : route('admin.catalog.price-types.store') }}"
          class="flex flex-col gap-4"
          x-data="{ advertised: {{ old('show_on_storefront', $priceType->show_on_storefront ?? false) ? 'true' : 'false' }} }">
        @csrf
        @if ($priceType->exists) @method('PUT') @endif

        <div class="sticky top-16 z-20 -mx-6 px-6 py-3 bg-base-200/95 backdrop-blur border-b border-base-300 flex items-center gap-3">
            <a href="{{ route('admin.catalog.price-types.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to price types') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ $priceType->exists ? __('Edit price type') : __('New price type') }}</div>
                <div class="font-semibold truncate leading-tight">{{ $priceType->exists ? ($priceType->t('name', $locale) ?: $priceType->code) : __('New price type') }}</div>
            </div>

            @if ($priceType->exists && (($prevType ?? null) || ($nextType ?? null)))
                <div class="join">
                    @if ($prevType ?? null)
                        <a href="{{ route('admin.catalog.price-types.edit', $prevType) }}" class="btn btn-sm join-item" title="{{ __('Previous price type') }}">
                            <x-icon name="chevron-left" />
                        </a>
                    @else
                        <span class="btn btn-sm join-item btn-disabled"><x-icon name="chevron-left" /></span>
                    @endif
                    @if ($nextType ?? null)
                        <a href="{{ route('admin.catalog.price-types.edit', $nextType) }}" class="btn btn-sm join-item" title="{{ __('Next price type') }}">
                            <x-icon name="chevron-right" />
                        </a>
                    @else
                        <span class="btn btn-sm join-item btn-disabled"><x-icon name="chevron-right" /></span>
                    @endif
                </div>
            @endif

            @if ($priceType->exists)
                {{-- Actions that submit elsewhere use the HTML "form" attribute and
                     standalone forms below: a nested <form> would leak its
                     _method=DELETE into this one's submit. --}}
                <div class="dropdown dropdown-end">
                    <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}">
                        <x-icon name="settings" size="lg" />
                    </div>
                    <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-60 mt-2 p-2 z-50 border border-base-300">
                        <li>
                            <button type="submit" name="after_save" value="close">
                                <x-icon name="check" /> {{ __('Save and close') }}
                            </button>
                        </li>
                        @unless ($priceType->is_base)
                            <li>
                                <a href="{{ route('admin.catalog.price-types.rules.create', $priceType) }}">
                                    <x-icon name="plus" /> {{ __('Add a markup rule') }}
                                </a>
                            </li>
                            <li class="menu-title p-0 my-1" role="separator"><span class="block h-px bg-base-300"></span></li>
                            <li>
                                <button type="submit" form="price-type-delete" class="text-error"
                                        data-confirm="{{ __('Delete this price type? Export profiles pinned to it fall back to the base price.') }}">
                                    <x-icon name="trash-2" /> {{ __('Delete this price type') }}
                                </button>
                            </li>
                        @endunless
                    </ul>
                </div>
            @endif
            <a href="{{ route('admin.catalog.price-types.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
            <button class="btn btn-primary btn-sm">{{ $priceType->exists ? __('Save') : __('Create') }}</button>
        </div>

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
        @endif

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem] items-start">
            {{-- Left: what it is called and how it is worked out. --}}
            <div class="flex flex-col gap-4 min-w-0">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('Basics') }}</h3>
                        <x-admin.translatable-field name="name" label="{{ __('Name') }}" :value="$priceType->name ?? []" required placeholder="{{ __('e.g. Wholesale') }}" />
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Code') }}</legend>
                            <input name="code" value="{{ old('code', $priceType->code) }}" class="input input-bordered w-full font-mono"
                                   placeholder="{{ __('from the name, e.g. WHOLESALE') }}" @disabled($priceType->is_base)>
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('What an export, a feed and 1C call this price. Kept when the name changes.') }}</p>
                        </fieldset>
                    </div>
                </section>

                @if ($priceType->is_base)
                    <section class="card bg-base-100 border border-base-300">
                        <div class="card-body gap-2">
                            <h3 class="font-semibold">{{ __('Price') }}</h3>
                            <p class="text-sm opacity-70">{{ __('The base type is always the price the storefront charges — with the customer group\'s discount and the quantity tiers. It has no adjustment and no rules of its own.') }}</p>
                        </div>
                    </section>
                @else
                    <section class="card bg-base-100 border border-base-300">
                        <div class="card-body gap-4">
                            <h3 class="font-semibold">{{ __('Price') }}</h3>
                            <fieldset class="fieldset p-0 max-w-xs"><legend class="fieldset-legend">{{ __('Difference from the base price') }}</legend>
                                <label class="input input-bordered w-full">
                                    <input type="number" name="adjustment_percent" value="{{ old('adjustment_percent', $priceType->adjustment_percent) }}"
                                           class="grow" placeholder="-20">
                                    <span class="opacity-50">%</span>
                                </label>
                            </fieldset>
                            <p class="text-xs opacity-60 -mt-2">
                                {{ __('-20 is 20% below the base price; empty is the base price itself. What decides first, top to bottom: a price typed for this type on the product, a quantity tier of this type, a markup rule below, and only then this percentage.') }}
                            </p>
                        </div>
                    </section>

                    <section class="card bg-base-100 border border-base-300" id="rules">
                        <div class="card-body gap-3">
                            <div class="flex items-center justify-between gap-3 flex-wrap">
                                <h3 class="font-semibold">
                                    {{ __('Markup rules') }}
                                    @if ($priceType->exists && $priceType->markupRules->isNotEmpty())
                                        <span class="badge badge-ghost badge-sm ml-1">{{ $priceType->markupRules->count() }}</span>
                                    @endif
                                </h3>
                                @if ($priceType->exists)
                                    <a href="{{ route('admin.catalog.price-types.rules.create', $priceType) }}" class="btn btn-sm gap-1.5">
                                        <x-icon name="plus" size="sm" /> {{ __('Add rule') }}
                                    </a>
                                @endif
                            </div>
                            <p class="text-xs opacity-60">
                                {{ __('A price from the COST price: the first enabled rule, top to bottom, whose conditions a product meets. A product with no cost price, or matching no rule, gets the percentage above.') }}
                            </p>

                            @if (! $priceType->exists)
                                <p class="text-sm opacity-50">{{ __('Rules can be added once the price type is saved.') }}</p>
                            @elseif ($priceType->markupRules->isEmpty())
                                <p class="text-sm opacity-50">{{ __('No rules — the percentage above applies to every product.') }}</p>
                            @else
                                <div class="overflow-x-auto">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th class="w-10">#</th>
                                                <th>{{ __('Rule') }}</th>
                                                <th>{{ __('For') }}</th>
                                                <th>{{ __('Price') }}</th>
                                                <th>{{ __('Rounding') }}</th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($priceType->markupRules as $rule)
                                                @php $said = $rule->describe(); @endphp
                                                <tr @class(['opacity-50' => ! $rule->enabled])>
                                                    <td class="tabular-nums opacity-60">{{ $rule->sort }}</td>
                                                    <td>
                                                        <a href="{{ route('admin.catalog.price-types.rules.edit', [$priceType, $rule]) }}" class="link link-hover font-medium">
                                                            {{ $rule->t('name', $locale) ?: __('Rule :n', ['n' => $loop->iteration]) }}
                                                        </a>
                                                        @unless ($rule->enabled)
                                                            <span class="badge badge-ghost badge-xs ml-1">{{ __('Disabled') }}</span>
                                                        @endunless
                                                    </td>
                                                    <td class="text-sm">{{ $said['when'] }}</td>
                                                    <td class="text-sm whitespace-nowrap">{{ $said['markup'] }}</td>
                                                    <td class="text-sm">{{ $said['rounding'] }}</td>
                                                    <td class="text-end whitespace-nowrap">
                                                        <div class="inline-flex items-center gap-1">
                                                            <a href="{{ route('admin.catalog.price-types.rules.edit', [$priceType, $rule]) }}"
                                                               class="btn btn-ghost btn-sm btn-square" title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}">
                                                                <x-icon name="pencil" />
                                                            </a>
                                                            <span class="w-px h-5 shrink-0 bg-base-300 mx-1.5" aria-hidden="true"></span>
                                                            <button type="submit" form="rule-delete-{{ $rule->id }}" class="btn btn-ghost btn-sm btn-square text-error"
                                                                    title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}"
                                                                    data-confirm="{{ __('Delete this rule?') }}">
                                                                <x-icon name="trash-2" />
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    </section>
                @endif
            </div>

            {{-- Right: whether it is on, who gets it, and whether the shop says so. --}}
            <div class="flex flex-col gap-4">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Status') }}</h3>
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" name="enabled" value="1" class="toggle toggle-sm toggle-primary" @checked(old('enabled', $priceType->enabled ?? true))>
                            <span class="label-text">{{ __('Enabled') }}</span>
                        </label>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Sort order') }}</legend>
                            <input name="sort" type="number" value="{{ old('sort', $priceType->sort ?? 0) }}" class="input input-bordered w-full">
                        </fieldset>
                    </div>
                </section>

                @unless ($priceType->is_base)
                    <section class="card bg-base-100 border border-base-300">
                        <div class="card-body gap-3">
                            <h3 class="font-semibold">{{ __('Who gets it') }}</h3>
                            <div class="flex flex-col gap-2">
                                @forelse ($customerGroups as $group)
                                    <label class="label cursor-pointer justify-start gap-2">
                                        <input type="checkbox" name="customer_groups[]" value="{{ $group->id }}" class="checkbox checkbox-sm"
                                               @checked(in_array($group->id, $selectedGroups, true))>
                                        <span class="label-text">{{ $group->t('name', $locale) }}</span>
                                    </label>
                                @empty
                                    <span class="text-xs opacity-50">{{ __('No customer groups yet.') }}</span>
                                @endforelse
                            </div>
                            <p class="text-xs opacity-60">{{ __('None ticked — every group.') }}</p>
                            <label class="label cursor-pointer justify-start gap-2 border-t border-base-300 pt-3">
                                <input type="checkbox" name="requires_auth" value="1" class="checkbox checkbox-sm"
                                       @checked(old('requires_auth', $priceType->requires_auth ?? false))>
                                <span class="label-text">{{ __('Only to a signed-in customer') }}</span>
                            </label>
                        </div>
                    </section>

                    <section class="card bg-base-100 border border-base-300">
                        <div class="card-body gap-3">
                            <h3 class="font-semibold">{{ __('On the storefront') }}</h3>
                            <label class="label cursor-pointer justify-start gap-3 items-start">
                                <input type="checkbox" name="show_on_storefront" value="1" x-model="advertised" class="toggle toggle-primary toggle-sm mt-0.5">
                                <span class="label-text whitespace-normal">{{ __('Show it beside the regular price on the product page') }}</span>
                            </label>
                            <p class="text-xs opacity-60">{{ __('A "club price": every visitor sees it, whoever may buy at it, and only where it is really lower.') }}</p>
                            <div x-show="advertised" x-cloak>
                                <x-admin.translatable-field name="storefront_note" label="{{ __('Note under it') }}" :value="$priceType->storefront_note ?? []"
                                                             placeholder="{{ __('e.g. How to get this price?') }}" />
                            </div>
                        </div>
                    </section>
                @endunless
            </div>
        </div>
    </form>

    @if ($priceType->exists && ! $priceType->is_base)
        <form id="price-type-delete" method="POST" action="{{ route('admin.catalog.price-types.destroy', $priceType) }}" class="hidden">
            @csrf @method('DELETE')
        </form>
        @foreach ($priceType->markupRules as $rule)
            <form id="rule-delete-{{ $rule->id }}" method="POST" action="{{ route('admin.catalog.price-types.rules.destroy', [$priceType, $rule]) }}" class="hidden">
                @csrf @method('DELETE')
            </form>
        @endforeach
    @endif
@endsection
