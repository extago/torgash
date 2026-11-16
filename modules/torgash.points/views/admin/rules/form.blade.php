@extends('admin.layout')

@section('title', $rule->exists ? ($rule->name ?: __('Rule').' #'.$rule->id) : __('New rule'))
@section('own_heading', '1')

@section('content')
    @php
        $ruleNumber = fn ($value) => $value === null ? '' : rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
        $ruleMajor = fn (?int $minor) => $minor ? rtrim(rtrim(number_format($minor / 100, 2, '.', ''), '0'), '.') : '';
        $ruleEarn = old('earn_mode', in_array($rule->earn_mode, \Torgash\Points\Rules::EARN_MODES, true) ? $rule->earn_mode : '');
        $ruleSpend = old('spend_mode', in_array($rule->spend_mode, \Torgash\Points\Rules::SPEND_MODES, true) ? $rule->spend_mode : '');
        $ruleGroups = array_map('intval', (array) old('group_ids', $conditions['groups'] ?? []));
    @endphp

    <form method="POST" action="{{ $rule->exists ? route('admin.points.rules.update', $rule) : route('admin.points.rules.store') }}" class="flex flex-col gap-4">
        @csrf
        @if ($rule->exists) @method('PUT') @endif

        <x-admin.form-bar>
            <a href="{{ route('admin.points.rules.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to the rules') }}" aria-label="{{ __('Back to the rules') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ __('Bonus points') }} · {{ __('Rules') }}</div>
                <div class="font-semibold truncate leading-tight">{{ $rule->exists ? ($rule->name ?: __('Rule').' #'.$rule->id) : __('New rule') }}</div>
            </div>
            <x-slot:actions>
                <a href="{{ route('admin.points.rules.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
                <button class="btn btn-primary btn-sm">{{ $rule->exists ? __('Save') : __('Create') }}</button>
            </x-slot:actions>
        </x-admin.form-bar>

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
        @endif

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem] items-start">
            <div class="flex flex-col gap-4 min-w-0">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('Which goods') }}</h3>
                        <p class="text-xs opacity-60 -mt-2">{{ __('A product must meet every condition filled in; inside one condition any of the chosen is enough. All empty — every product.') }}</p>

                        <x-admin.picker name="category_ids[]" :label="__('Categories')" :options="$categoryOptions"
                                        :selected="old('category_ids', $conditions['categories'] ?? [])" :empty="__('Any category')" />
                        <p class="text-xs opacity-60 -mt-2">{{ __('subcategories included') }}</p>

                        <x-admin.picker name="feature_value_ids[]" :label="__('Characteristic values')" :options="$featureValueOptions"
                                        :selected="old('feature_value_ids', $conditions['feature_values'] ?? [])" :empty="__('Any value')" />
                        <p class="text-xs opacity-60 -mt-2">{{ __('A brand is a characteristic too.') }}</p>

                        <div class="grid sm:grid-cols-2 gap-4">
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Price from') }}</legend>
                                <input type="number" step="0.01" min="0" name="price_min" value="{{ old('price_min', $ruleMajor($conditions['price_min'] ?? null)) }}"
                                       class="input input-bordered w-full" placeholder="0.00">
                            </fieldset>
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Price up to') }}</legend>
                                <input type="number" step="0.01" min="0" name="price_max" value="{{ old('price_max', $ruleMajor($conditions['price_max'] ?? null)) }}"
                                       class="input input-bordered w-full" placeholder="0.00">
                            </fieldset>
                        </div>
                        <p class="text-xs opacity-60 -mt-2">{{ __('The price of one piece as the customer pays it, in the shop\'s currency — with their own price and the chosen options.') }}</p>

                        <x-admin.picker name="product_ids[]" :label="__('Only these products')" :options="$productOptions"
                                        :selected="old('product_ids', $conditions['products'] ?? [])" :empty="__('Any product')"
                                        :placeholder="__('Name or SKU…')" :source="route('admin.catalog.products.lookup')" />

                        @if ($groups->isNotEmpty())
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Only for customers of') }}</legend>
                                <div class="flex flex-wrap gap-x-5 gap-y-2">
                                    @foreach ($groups as $group)
                                        <label class="flex items-center gap-2 text-sm">
                                            <input type="checkbox" name="group_ids[]" value="{{ $group->id }}" class="checkbox checkbox-sm" @checked(in_array($group->id, $ruleGroups, true))>
                                            {{ $group->t('name') }}
                                        </label>
                                    @endforeach
                                </div>
                                <p class="label text-xs opacity-60 whitespace-normal">{{ __('None ticked — everyone, guests\' cards included.') }}</p>
                            </fieldset>
                        @endif
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300" x-data="{ earn: @js($ruleEarn), spend: @js($ruleSpend) }">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('What the rule decides') }}</h3>
                        <p class="text-xs opacity-60 -mt-2">{{ __('A question left "not decided here" goes on down the list: a rule about earning never settles paying, and the other way round.') }}</p>

                        <div class="grid md:grid-cols-2 gap-4">
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Earns') }}</legend>
                                <select name="earn_mode" x-model="earn" class="select select-bordered w-full" aria-label="{{ __('Earns') }}">
                                    <option value="">{{ __('Not decided here') }}</option>
                                    <option value="multiplier">{{ __('The shop\'s rate, multiplied') }}</option>
                                    <option value="percent">{{ __('A percent of the price, in points') }}</option>
                                    <option value="fixed">{{ __('A fixed number of points per piece') }}</option>
                                    <option value="none">{{ __('Nothing') }}</option>
                                </select>
                                <label class="input input-bordered w-full mt-2" x-show="earn !== '' && earn !== 'none'" x-cloak>
                                    <span class="opacity-50" x-text="earn === 'multiplier' ? '×' : (earn === 'percent' ? '%' : '+')"></span>
                                    <input name="earn_value" type="number" step="0.01" min="0" class="grow" aria-label="{{ __('Value') }}"
                                           :disabled="earn === '' || earn === 'none'" value="{{ old('earn_value', $ruleEarn !== '' ? $ruleNumber($rule->earn_value) : '') }}">
                                </label>
                                <p class="label text-xs opacity-60 whitespace-normal">{{ __('×0.5 earns half the shop\'s rate; 5% of a price of 1 000 is 50 points; a fixed number is given for every piece. The order\'s discounts lower all three alike.') }}</p>
                            </fieldset>

                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Paying with points') }}</legend>
                                <select name="spend_mode" x-model="spend" class="select select-bordered w-full" aria-label="{{ __('Paying with points') }}">
                                    <option value="">{{ __('Not decided here') }}</option>
                                    <option value="allowed">{{ __('Allowed, within the shop\'s share') }}</option>
                                    <option value="limit">{{ __('Allowed, up to its own share') }}</option>
                                    <option value="forbidden">{{ __('Not allowed') }}</option>
                                </select>
                                <label class="input input-bordered w-full mt-2" x-show="spend === 'limit'" x-cloak>
                                    <input name="spend_percent" type="number" step="1" min="0" max="100" class="grow" aria-label="{{ __('Share, %') }}"
                                           :disabled="spend !== 'limit'" value="{{ old('spend_percent', $ruleSpend === 'limit' ? (int) $rule->spend_percent : '') }}">
                                    <span class="opacity-50">%</span>
                                </label>
                                <p class="label text-xs opacity-60 whitespace-normal">{{ __('How much of these goods\' price points may take off in a basket. The shop\'s share is in the module\'s settings.') }}</p>
                            </fieldset>
                        </div>
                    </div>
                </section>
            </div>

            <div class="flex flex-col gap-4">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Name') }}</legend>
                            <input name="name" maxlength="190" class="input input-bordered w-full" value="{{ old('name', $rule->name) }}" placeholder="{{ __('Expensive goods') }}">
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('For the staff: it is printed on a product\'s Bonus points tab as the reason.') }}</p>
                        </fieldset>
                        <label class="flex items-center justify-between gap-3 text-sm">
                            {{ __('Enabled') }}
                            <input type="hidden" name="enabled" value="0">
                            <input type="checkbox" name="enabled" value="1" class="toggle toggle-sm toggle-success" @checked(old('enabled', $rule->enabled))>
                        </label>
                        @unless ($rule->exists)
                            <p class="text-xs opacity-60">{{ __('A new rule is put above the first one that would leave it nothing — a subcategory\'s above its category\'s — otherwise at the end. Drag it in the list if it should stand elsewhere.') }}</p>
                        @endunless
                    </div>
                </section>
            </div>
        </div>
    </form>
@endsection
