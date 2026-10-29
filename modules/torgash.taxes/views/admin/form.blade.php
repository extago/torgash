@extends('admin.layout')

@section('title', $tax->exists ? __('Edit tax') : __('New tax'))
@section('own_heading', '1')

@section('content')
    @php
        $includes = (bool) old('price_includes_tax', $tax->price_includes_tax ?? true);
    @endphp

    <form method="POST" action="{{ $tax->exists ? route('admin.taxes.update', $tax) : route('admin.taxes.store') }}"
          class="flex flex-col gap-4"
          x-data="{ rateType: '{{ old('rate_type', $tax->rate_type ?? 'percent') }}' }">
        @csrf
        @if ($tax->exists) @method('PUT') @endif

        <x-admin.form-bar>
            <a href="{{ route('admin.taxes.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to taxes') }}" aria-label="{{ __('Back to taxes') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ $tax->exists ? __('Edit tax') : __('New tax') }}</div>
                <div class="font-semibold truncate leading-tight">
                    {{ $tax->exists ? ($tax->t('name', admin_content_locale()) ?: $tax->code) : __('New tax') }}
                </div>
            </div>
            @if ($tax->exists)
                <div class="dropdown dropdown-end">
                    <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}" aria-label="{{ __('More actions') }}">
                        <x-icon name="settings" size="lg" />
                    </div>
                    <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-60 mt-2 p-2 z-50 border border-base-300">
                        <li>
                            <button type="submit" name="after_save" value="close">
                                <x-icon name="check" /> {{ __('Save and close') }}
                            </button>
                        </li>
                    </ul>
                </div>
            @endif
            <x-slot:actions>
                <a href="{{ route('admin.taxes.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
                <button class="btn btn-primary btn-sm">{{ $tax->exists ? __('Save') : __('Create') }}</button>
            </x-slot:actions>
        </x-admin.form-bar>

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error text-sm flex-col items-start gap-1">
                @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
            </div>
        @endif

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_24rem] items-start">
            <div class="flex flex-col gap-4 min-w-0">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <h3 class="font-semibold">{{ __('Basics') }}</h3>

                        <div class="grid md:grid-cols-[minmax(0,1fr)_14rem] gap-4">
                            <x-admin.translatable-field name="name" label="{{ __('Name') }}" :value="$tax->name ?? []" required />

                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Code') }} *</legend>
                                <input name="code" autocapitalize="off" autocorrect="off" spellcheck="false" value="{{ old('code', $tax->code) }}" class="input input-bordered w-full font-mono" required placeholder="vat20">
                                <p class="label text-xs opacity-60 whitespace-normal">{{ __('Used in imports and exports. Latin letters and digits, e.g. vat20.') }}</p>
                            </fieldset>
                        </div>

                        <div class="grid sm:grid-cols-2 gap-4">
                            <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Rate type') }}</legend>
                                <select name="rate_type" x-model="rateType" class="select select-bordered w-full">
                                    <option value="percent">{{ __('Percent of the price') }}</option>
                                    <option value="fixed">{{ __('Fixed amount per item') }}</option>
                                </select>
                            </fieldset>

                            <fieldset class="fieldset p-0" x-show="rateType === 'percent'">
                                <legend class="fieldset-legend">{{ __('Rate, %') }}</legend>
                                <label class="input input-bordered w-full">
                                    <input name="rate_percent" type="number" step="0.0001" min="0" max="100"
                                           value="{{ old('rate_percent', $tax->rate_percent) }}" class="grow" placeholder="20">
                                    <span class="opacity-50">%</span>
                                </label>
                            </fieldset>

                            <fieldset class="fieldset p-0" x-show="rateType === 'fixed'" x-cloak>
                                <legend class="fieldset-legend">{{ __('Amount per item') }}</legend>
                                <label class="input input-bordered w-full">
                                    <input name="rate_amount" type="number" step="0.01" min="0"
                                           value="{{ old('rate_amount', $tax->rate_amount ? $tax->rate_amount / 100 : '') }}"
                                           class="grow" placeholder="0.00">
                                    <span class="opacity-50">{{ shop_currency() }}</span>
                                </label>
                            </fieldset>
                        </div>
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-4">
                        <div>
                            <h3 class="font-semibold">{{ __('Rates by destination') }}</h3>
                            <p class="text-xs opacity-60 mt-1">
                                {{ __('Where the goods go decides the rate. The narrowest row naming the address wins: a postal code beats a pattern, which beats a region, which beats a country. The rate above is for everywhere else — and for a customer who has not said where they are yet, which is what the product page shows.') }}
                            </p>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>{{ __('Where') }}</th>
                                        <th></th>
                                        <th></th>
                                        <th>{{ __('Rate') }}</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody x-ref="rates">
                                    @foreach ($rates as $i => $rateRow)
                                        @include('torgash.taxes::admin._rate-row', ['rateIndex' => $i, 'rateRow' => $rateRow, 'rateCountries' => $countries, 'rateRegions' => $regions])
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <template x-ref="rateTemplate">
                            @include('torgash.taxes::admin._rate-row', ['rateIndex' => '__INDEX__', 'rateRow' => null, 'rateCountries' => $countries, 'rateRegions' => $regions])
                        </template>

                        <div>
                            <button type="button" class="btn btn-sm gap-1.5"
                                    @click="$refs.rates.insertAdjacentHTML('beforeend', $refs.rateTemplate.innerHTML.replaceAll('__INDEX__', 'n' + Date.now()))">
                                <x-icon name="plus" size="sm" /> {{ __('Add a destination') }}
                            </button>
                        </div>

                        <label class="label cursor-pointer justify-start gap-3 p-0">
                            <input type="checkbox" name="only_listed" value="1" class="toggle toggle-sm toggle-success"
                                   @checked(old('only_listed', $tax->only_listed ?? false))>
                            <span class="label-text whitespace-normal">{{ __('Charged only in the places listed') }}</span>
                        </label>
                        <p class="text-xs opacity-60 -mt-2">
                            {{ __('Off: the rate above applies everywhere else. On: everywhere else this tax is not charged at all — a sales tax owed only in the states where the shop is registered. Until the customer names a place, such a tax is not shown.') }}
                        </p>
                    </div>
                </section>
            </div>

            <div class="flex flex-col gap-4">
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Status') }}</h3>
                        <label class="label cursor-pointer justify-start gap-3">
                            <input type="checkbox" name="enabled" value="1" class="toggle toggle-sm toggle-success"
                                   @checked(old('enabled', $tax->enabled ?? true))>
                            <span class="label-text">{{ __('Enabled') }}</span>
                        </label>
                        <p class="text-xs opacity-60">{{ __('Disabling a tax stops it applying anywhere without touching orders that already charged it.') }}</p>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Sort order') }}</legend>
                            <input name="position" type="number" value="{{ old('position', $tax->position ?? 0) }}" class="input input-bordered w-full">
                        </fieldset>
                    </div>
                </section>

                {{-- The one decision that makes a tax what it is: two cards rather
                     than a tick box, because both answers are normal and the
                     difference is what the customer sees. --}}
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('How it is charged') }}</h3>
                        @foreach ([
                            1 => ['tag', __('Included in the price'), __('The price on the shelf is what the customer pays; the tax is extracted from it for the cart, the order and the invoice. VAT in most of Europe and the CIS.')],
                            0 => ['circle-plus', __('Added at checkout'), __('Added on top, so the customer sees the price grow at checkout. Sales tax in the US.')],
                        ] as $value => [$icon, $title, $text])
                            <label class="flex items-start gap-3 rounded-box border border-base-300 p-3 cursor-pointer has-[:checked]:border-primary has-[:checked]:bg-primary/5">
                                <input type="radio" name="price_includes_tax" value="{{ $value }}" class="radio radio-sm mt-0.5"
                                       @checked($includes === (bool) $value)>
                                <div>
                                    <div class="font-medium flex items-center gap-1.5"><x-icon :name="$icon" size="sm" class="opacity-60" /> {{ $title }}</div>
                                    <div class="text-xs opacity-60 mt-0.5">{{ $text }}</div>
                                </div>
                            </label>
                        @endforeach
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('What it applies to') }}</h3>
                        <label class="label cursor-pointer justify-start gap-3 p-0">
                            <input type="checkbox" name="applies_by_default" value="1" class="toggle toggle-sm toggle-success"
                                   @checked(old('applies_by_default', $tax->applies_by_default ?? true))>
                            <span class="label-text whitespace-normal">{{ __('Applies to every product') }}</span>
                        </label>
                        <label class="label cursor-pointer justify-start gap-3 p-0">
                            <input type="checkbox" name="applies_to_shipping" value="1" class="toggle toggle-sm toggle-success"
                                   @checked(old('applies_to_shipping', $tax->applies_to_shipping ?? false))>
                            <span class="label-text whitespace-normal">{{ __('Also applies to shipping') }}</span>
                        </label>
                        <p class="text-xs opacity-60">{{ __('A product can override the default set on its own form.') }}</p>
                    </div>
                </section>

                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-3">
                        <h3 class="font-semibold">{{ __('Advanced') }}</h3>
                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Priority') }}</legend>
                            <input name="priority" type="number" min="0" max="100"
                                   value="{{ old('priority', $tax->priority ?? 0) }}" class="input input-bordered w-full">
                            <p class="label text-xs opacity-60 whitespace-normal">
                                {{ __('Taxes sharing a priority are each charged on the original price. A higher priority is charged on the price the lower ones already raised — tax on tax. Leave at 0 unless you know you need this.') }}
                            </p>
                        </fieldset>

                        <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Registration number') }}</legend>
                            <input name="reg_number" value="{{ old('reg_number', $tax->reg_number) }}" class="input input-bordered w-full">
                            <p class="label text-xs opacity-60 whitespace-normal">{{ __('Stored with every order that charges this tax, for the invoice.') }}</p>
                        </fieldset>
                    </div>
                </section>
            </div>
        </div>
    </form>
@endsection
