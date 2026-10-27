@extends('admin.layout')

@section('title', $warehouse->exists ? __('Edit warehouse') : __('New warehouse'))
@section('own_heading', '1')

@section('content')
    <form method="POST" action="{{ $warehouse->exists ? route('admin.warehouses.update', $warehouse) : route('admin.warehouses.store') }}"
          class="flex flex-col gap-4">
        @csrf
        @if ($warehouse->exists) @method('PUT') @endif

        <div class="sticky top-16 z-20 -mx-6 px-6 py-3 bg-base-200/95 backdrop-blur border-b border-base-300 flex items-center gap-3">
            <a href="{{ route('admin.warehouses.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to warehouses') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ $warehouse->exists ? __('Edit warehouse') : __('New warehouse') }}</div>
                <div class="font-semibold truncate leading-tight">{{ $warehouse->exists ? $warehouse->t('name', admin_content_locale()) : __('New warehouse') }}</div>
            </div>
            <div class="dropdown dropdown-end">
                <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}">
                    <x-icon name="settings" size="lg" />
                </div>
                <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-60 mt-2 p-2 z-50 border border-base-300">
                    <li><button type="submit" name="after_save" value="close"><x-icon name="check" /> {{ __('Save and close') }}</button></li>
                    @if ($warehouse->exists)
                        <li><a href="{{ route('admin.warehouses.stock', $warehouse) }}"><x-icon name="package" /> {{ __('Stock') }}</a></li>
                        <li class="menu-title p-0 my-1"><span class="border-t border-base-300 block"></span></li>
                        @if ((int) ($holding->stocked ?? 0) > 0)
                            <li class="disabled" title="{{ __('Still holds stock — move or clear it before deleting.') }}"><span><x-icon name="trash-2" /> {{ __('Delete') }}</span></li>
                        @else
                            <li><button type="submit" form="warehouse-delete" class="text-error"><x-icon name="trash-2" /> {{ __('Delete') }}</button></li>
                        @endif
                    @endif
                </ul>
            </div>
            <a href="{{ route('admin.warehouses.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
            <button class="btn btn-primary btn-sm">{{ $warehouse->exists ? __('Save') : __('Create') }}</button>
        </div>

        @if (session('status'))
            <div class="alert alert-success text-sm">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error text-sm flex-col items-start gap-1">
                @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
            </div>
        @endif

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem] items-start">
        <div class="flex flex-col gap-4 min-w-0">
        <section class="card bg-base-100 border border-base-300">
            <div class="card-body gap-4">
                <h3 class="font-semibold">{{ __('Where it is') }}</h3>
                <x-admin.translatable-field name="name" label="{{ __('Name') }}" :value="$warehouse->name ?? []" required />

                @php
                    // The town field appears only with a place registry installed:
                    // without it there is nothing to search, and the street address
                    // below still says where this is. Included straight from the
                    // locations module — a branch that is never taken resolves no
                    // view, so this stays a soft dependency.
                    $suggest = \Illuminate\Support\Facades\Route::has('places.suggest') ? route('places.suggest') : null;
                @endphp

                @if ($suggest)
                    @include('torgash.locations::partials.place-picker-script')

                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Town') }}</legend>
                        <div x-data="placePicker({ endpoint: '{{ $suggest }}', label: @js($placeName), name: @js($placeName), placeId: @js($warehouse->place_id ?? '') })"
                             class="relative">
                            <input type="text" class="input input-bordered w-full" autocomplete="off"
                                   value="{{ $placeName }}" :value="label"
                                   @input="search($event.target.value)" @focus="open = true"
                                   placeholder="{{ __('Start typing a town') }}">
                            <input type="hidden" name="place_id" value="{{ $warehouse->place_id }}" :value="placeId">

                            @include('torgash.locations::partials.place-suggestions')
                        </div>
                        <div class="text-xs opacity-50">
                            {{ __('Where this warehouse is. A carrier prices a parcel from here, and a customer collecting an order looks for it in this town.') }}
                        </div>
                    </fieldset>
                @endif

                <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Address') }}</legend>
                    <input name="address" value="{{ old('address', $warehouse->address) }}" class="input input-bordered w-full">
                </fieldset>

            </div>
        </section>

        {{-- What the customer is allowed to know about this place, and what
             they may do with it. Ticking "collect here" implies being shown at
             all, so the visibility box follows it. --}}
        <section class="card bg-base-100 border border-base-300" x-data="{ pickup: {{ old('is_pickup', $warehouse->is_pickup ?? false) ? 'true' : 'false' }} }">
            <div class="card-body gap-4">
                <h3 class="font-semibold">{{ __('For customers') }}</h3>

                <label class="label cursor-pointer justify-start gap-2 p-0">
                    <input type="checkbox" name="is_public" value="1" class="checkbox checkbox-sm checkbox-primary"
                           {{-- Collecting implies being shown, so the box follows and
                                stops being a separate answer; the server applies the
                                same rule, since a disabled box posts nothing. --}}
                           :disabled="pickup" :checked="pickup || $el.defaultChecked"
                           @checked(old('is_public', $warehouse->is_public ?? false))>
                    <span class="label-text">{{ __('Show this location to customers') }}</span>
                </label>
                <div class="text-xs opacity-50 -mt-3">
                    {{ __('Lets the storefront name this location — "in stock in your town". A fulfilment warehouse the customer never visits stays off.') }}
                </div>

                <label class="label cursor-pointer justify-start gap-2 p-0">
                    <input type="checkbox" name="is_pickup" value="1" class="checkbox checkbox-sm checkbox-primary"
                           x-model="pickup" @checked(old('is_pickup', $warehouse->is_pickup ?? false))>
                    <span class="label-text">{{ __('Orders can be collected here') }}</span>
                </label>
                <div class="text-xs opacity-50 -mt-3">
                    {{ __('Offers this location in the delivery step as a pickup point. Not every warehouse is one — a depot that only dispatches parcels is not.') }}
                </div>

                <fieldset class="fieldset p-0 max-w-xs"><legend class="fieldset-legend">{{ __('Phone') }}</legend>
                    <input name="phone" value="{{ old('phone', $warehouse->phone) }}" class="input input-bordered w-full">
                </fieldset>

                <fieldset class="fieldset p-0 max-w-xs"><legend class="fieldset-legend">{{ __('Days to bring goods to a pickup point') }}</legend>
                    <input type="number" min="0" max="365" name="transfer_days" value="{{ old('transfer_days', $warehouse->transfer_days) }}" class="input input-bordered w-full">
                    <p class="label whitespace-normal">{{ __('What "we will bring it by …" is counted from, when the shop a customer chose does not have a product and this warehouse does. Empty — this warehouse does not send to shops.') }}</p>
                </fieldset>

                <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Opening hours') }}</legend>
                    <div class="flex flex-col gap-1">
                        @foreach (\Torgash\Warehouses\Hours::labels() as $day => $label)
                            @php $range = ($warehouse->hours ?? [])[$day] ?? null; @endphp
                            <div class="flex items-center gap-2">
                                <span class="w-12 text-sm opacity-70">{{ $label }}</span>
                                <input type="time" name="hours[{{ $day }}][from]" class="input input-bordered w-32"
                                       value="{{ old('hours.'.$day.'.from', $range[0] ?? '') }}">
                                <span class="opacity-40">—</span>
                                <input type="time" name="hours[{{ $day }}][to]" class="input input-bordered w-32"
                                       value="{{ old('hours.'.$day.'.to', $range[1] ?? '') }}">
                            </div>
                        @endforeach
                    </div>
                    <div class="text-xs opacity-50">{{ __('An empty day is a closed day.') }}</div>
                </fieldset>
            </div>
        </section>
        </div>

        <div class="flex flex-col gap-4">
            <section class="card bg-base-100 border border-base-300">
                <div class="card-body gap-3">
                    <h3 class="font-semibold">{{ __('Status') }}</h3>
                    <label class="label cursor-pointer justify-start gap-3">
                        <input type="checkbox" name="enabled" value="1" class="toggle toggle-sm toggle-success"
                               @checked(old('enabled', $warehouse->enabled ?? true))>
                        <span class="label-text">{{ __('Enabled') }}</span>
                    </label>
                    <label class="label cursor-pointer justify-start gap-3 whitespace-normal">
                        <input type="checkbox" name="is_default" value="1" class="toggle toggle-sm toggle-primary"
                               @checked(old('is_default', $warehouse->is_default ?? false))>
                        <span class="label-text">{{ __('Default') }}</span>
                    </label>
                    <p class="text-xs opacity-60">{{ __('The default warehouse receives stock returns and restocks that don\'t name a warehouse. There is always exactly one.') }}</p>
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Sort order') }}</legend>
                        <input name="sort" type="number" value="{{ old('sort', $warehouse->sort ?? 0) }}" class="input input-bordered w-full">
                        <p class="label text-xs opacity-60 whitespace-normal">{{ __('Lower sorts first — a sale drains warehouses in this order.') }}</p>
                    </fieldset>
                </div>
            </section>

            @if ($warehouse->exists)
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-2 text-sm">
                        <h3 class="font-semibold">{{ __('What it holds') }}</h3>
                        <div class="flex justify-between gap-2">
                            <span class="opacity-60">{{ __('Products') }}</span>
                            <span class="font-semibold tabular-nums">{{ (int) ($holding->stocked ?? 0) }}</span>
                        </div>
                        <div class="flex justify-between gap-2">
                            <span class="opacity-60">{{ __('Units') }}</span>
                            <span class="font-semibold tabular-nums">{{ (int) ($holding->units ?? 0) }}</span>
                        </div>
                        <a href="{{ route('admin.warehouses.stock', [$warehouse, 'here' => 1]) }}" class="btn btn-ghost btn-sm gap-1.5 self-start -ml-2">
                            <x-icon name="package" size="sm" /> {{ __('Stock') }}
                        </a>
                    </div>
                </section>
            @endif
        </div>
        </div>
    </form>

    {{-- Beside the main form, never inside it. --}}
    @if ($warehouse->exists)
        <form id="warehouse-delete" method="POST" action="{{ route('admin.warehouses.destroy', $warehouse) }}"
              data-confirm="{{ __('Delete :name?', ['name' => $warehouse->t('name', admin_content_locale())]) }}">
            @csrf @method('DELETE')
        </form>
    @endif
@endsection
