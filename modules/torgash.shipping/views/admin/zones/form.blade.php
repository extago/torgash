@extends('admin.layout')

@section('title', $zone->exists ? __('Edit zone') : __('New zone'))
@section('own_heading', '1')

@section('content')
    @php
        $townRoute = \Illuminate\Support\Facades\Route::has('admin.locations.suggest')
            ? route('admin.locations.suggest') : null;

        $postedCountries = is_array($c = old('countries', $covers['countries'])) ? $c : [];
        $chosenCountries = array_values(array_diff($postedCountries, ['*']));
        $everywhere = in_array('*', $postedCountries, true);
        $chosenRegions = is_array($r = old('regions', $covers['regions'])) ? $r : [];
        $chosenTowns = old('towns_data') ? (json_decode(old('towns_data'), true) ?: []) : $covers['towns'];
    @endphp

    @include('torgash.shipping::admin.zones.pick-list-script')
    @if ($townRoute)
        @include('torgash.locations::partials.place-picker-script')
    @endif

    <form method="POST" action="{{ $zone->exists ? route('admin.shipping.zones.update', $zone) : route('admin.shipping.zones.store') }}"
          class="flex flex-col gap-4">
        @csrf
        @if ($zone->exists) @method('PUT') @endif

        <x-admin.form-bar>
            <a href="{{ route('admin.shipping.zones.index') }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to zones') }}" aria-label="{{ __('Back to zones') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <div class="text-xs opacity-50 leading-none">{{ $zone->exists ? __('Edit zone') : __('New zone') }}</div>
                <div class="font-semibold truncate leading-tight">{{ $zone->exists ? $zone->t('name', admin_content_locale()) : __('New zone') }}</div>
            </div>
            <div class="dropdown dropdown-end">
                <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square" title="{{ __('More actions') }}" aria-label="{{ __('More actions') }}">
                    <x-icon name="settings" size="lg" />
                </div>
                <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-60 mt-2 p-2 z-50 border border-base-300">
                    <li><button type="submit" name="after_save" value="close"><x-icon name="check" /> {{ __('Save and close') }}</button></li>
                    @if ($zone->exists)
                        <li class="menu-title p-0 my-1"><span class="border-t border-base-300 block"></span></li>
                        <li><button type="submit" form="delete-zone" class="text-error"><x-icon name="trash-2" /> {{ __('Delete') }}</button></li>
                    @endif
                </ul>
            </div>
            <x-slot:actions>
                <a href="{{ route('admin.shipping.zones.index') }}" class="btn btn-ghost btn-sm">{{ __('Cancel') }}</a>
                <button class="btn btn-primary btn-sm">{{ $zone->exists ? __('Save') : __('Create') }}</button>
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

        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem] items-start">
        <div class="flex flex-col gap-4 min-w-0">
        <section class="card bg-base-100 border border-base-300">
            <div class="card-body gap-4">
                <h3 class="font-semibold">{{ __('Name') }}</h3>

                <div class="grid md:grid-cols-3 gap-4">
                    @foreach ($languages as $code => $label)
                        <fieldset class="fieldset p-0">
                            <legend class="fieldset-legend">
                                {{ $label }} <span class="opacity-40 font-mono">{{ $code }}</span>
                            </legend>
                            <input name="names[{{ $code }}]" value="{{ old("names.{$code}", $zone->name[$code] ?? '') }}"
                                   class="input input-bordered w-full"
                                   @required($code === content_base_locale())>
                        </fieldset>
                    @endforeach
                </div>

            </div>
        </section>

        <section class="card bg-base-100 border border-base-300">
            <div class="card-body gap-6">
                <div>
                    <h3 class="font-semibold">{{ __('What this zone covers') }}</h3>
                    <div class="text-xs opacity-60 mt-1">
                        {{ __('Add countries, regions, towns or postal codes — any one match puts the customer in this zone. Across the whole shop the narrowest matching zone wins: a town beats a region beats a country.') }}
                    </div>
                </div>

                {{-- Countries ------------------------------------------------ --}}
                <div class="flex flex-col gap-2">
                    <div class="text-sm font-medium">{{ __('Countries') }}</div>

                    @if (count($allCountries))
                        <div x-data="pickList({
                                all: {{ \Illuminate\Support\Js::from(collect($allCountries)->map(fn ($c) => ['value' => $c['code'], 'label' => $c['name']])->values()) }},
                                chosen: {{ \Illuminate\Support\Js::from($chosenCountries) }},
                             })">
                            @include('torgash.shipping::admin.zones.partials.pick-list', ['field' => 'countries[]', 'filterPlaceholder' => __('Filter countries')])
                        </div>

                        <label class="label cursor-pointer justify-start gap-2 p-0 mt-1">
                            <input type="checkbox" name="countries[]" value="*" class="checkbox checkbox-sm"
                                   @checked($everywhere)>
                            <span class="label-text">{{ __('Everywhere — the catch-all every otherwise-unmatched address falls to') }}</span>
                        </label>
                    @else
                        <textarea name="countries_text" rows="3" class="textarea textarea-bordered w-full font-mono text-sm"
                                  placeholder="UA&#10;RO&#10;*">{{ old('countries_text', implode("\n", $covers['countries'])) }}</textarea>
                        <div class="text-xs opacity-50">{{ __('One ISO country code per line, or * for anywhere. Install the locations module to pick from a list.') }}</div>
                    @endif
                </div>

                {{-- Regions ------------------------------------------------- --}}
                <div class="flex flex-col gap-2">
                    <div class="text-sm font-medium">{{ __('Regions') }}</div>

                    @if (count($allRegions))
                        <div x-data="pickList({
                                all: {{ \Illuminate\Support\Js::from(collect($allRegions)->map(fn ($r) => ['value' => $r['value'], 'label' => $r['country_name'].': '.$r['name'], 'hint' => $r['country']])->values()) }},
                                chosen: {{ \Illuminate\Support\Js::from($chosenRegions) }},
                             })">
                            @include('torgash.shipping::admin.zones.partials.pick-list', ['field' => 'regions[]', 'filterPlaceholder' => __('Filter regions')])
                        </div>
                    @else
                        <textarea name="regions_text" rows="3" class="textarea textarea-bordered w-full font-mono text-sm"
                                  placeholder="UA:12&#10;UA:14">{{ old('regions_text', implode("\n", $covers['regions'])) }}</textarea>
                        <div class="text-xs opacity-50">{{ __('One region per line as COUNTRY:CODE. Install the locations module to pick from a list.') }}</div>
                    @endif
                </div>

                {{-- Towns --------------------------------------------------- --}}
                <div class="flex flex-col gap-2"
                     x-data="{
                         towns: {{ \Illuminate\Support\Js::from($chosenTowns) }},
                         addTown(item) {
                             if (this.towns.some((t) => t.id == item.id)) return;
                             this.towns.push({ id: item.id, label: item.context ? item.name + ' — ' + item.context : item.name });
                         },
                     }">
                    <div class="text-sm font-medium">{{ __('Towns') }}</div>
                    <input type="hidden" name="towns_data" :value="JSON.stringify(towns)">

                    @if ($townRoute)
                        <template x-for="(town, i) in towns" :key="town.id">
                            <div class="flex items-center gap-1 text-sm">
                                <span class="badge badge-sm badge-ghost" x-text="town.label"></span>
                                <input type="hidden" name="towns[]" :value="town.id">
                                <button type="button" class="btn btn-ghost btn-sm btn-square text-error" @click="towns.splice(i, 1)"
                                        title="{{ __('Remove') }}" aria-label="{{ __('Remove') }}">
                                    <x-icon name="x" />
                                </button>
                            </div>
                        </template>

                        <div class="fieldset p-0 grow max-w-sm relative"
                             x-data="placePicker({ endpoint: '{{ $townRoute }}' })">
                            <input type="text" class="input input-bordered w-full" autocomplete="off"
                                   :value="label" @input="search($event.target.value)" @focus="open = true"
                                   placeholder="{{ __('Start typing a town') }}">
                            @include('torgash.locations::partials.place-suggestions', [
                                'onPick' => '; addTown(item); label = \'\'; name = \'\'; placeId = \'\'; results = []',
                            ])
                        </div>
                    @else
                        <div class="text-xs opacity-50">{{ __('Install the locations module to add towns to a zone.') }}</div>
                    @endif
                </div>

                {{-- Postal codes ----------------------------------------- --}}
                <div class="flex flex-col gap-2">
                    <div class="text-sm font-medium">{{ __('Postal codes') }}</div>
                    <textarea name="postal" rows="3" class="textarea textarea-bordered w-full font-mono text-sm"
                              placeholder="01001&#10;49*&#10;9?9">{{ old('postal', $covers['postal']) }}</textarea>
                    <div class="text-xs opacity-50">
                        {{ __('One code or pattern per line. * matches any run of characters, ? matches one: 49* covers 49000, 49312…; 9?9 covers 909, 919…') }}
                    </div>
                </div>

                <div class="text-xs opacity-50">
                    {{ __('A zone with nothing here matches no one and is never used.') }}
                </div>
            </div>
        </section>
        </div>

        <div class="flex flex-col gap-4">
            <section class="card bg-base-100 border border-base-300">
                <div class="card-body gap-3">
                    <h3 class="font-semibold">{{ __('Status') }}</h3>
                    <label class="label cursor-pointer justify-start gap-3">
                        <input type="checkbox" name="enabled" value="1" class="toggle toggle-sm toggle-success"
                               @checked(old('enabled', $zone->enabled ?? true))>
                        <span class="label-text">{{ __('Enabled') }}</span>
                    </label>
                    <p class="text-xs opacity-60">{{ __('A disabled zone matches no one: its addresses fall to the next narrowest zone.') }}</p>
                    <fieldset class="fieldset p-0"><legend class="fieldset-legend">{{ __('Sort order') }}</legend>
                        <input name="position" type="number" value="{{ old('position', $zone->position ?? 0) }}" class="input input-bordered w-full">
                        <p class="label text-xs opacity-60 whitespace-normal">{{ __('Breaks ties between equally narrow zones.') }}</p>
                    </fieldset>
                </div>
            </section>

            {{-- What a customer here is actually offered: a zone is only half
                 the answer until some method has a rate in it. --}}
            @if ($zone->exists)
                <section class="card bg-base-100 border border-base-300">
                    <div class="card-body gap-2 text-sm">
                        <h3 class="font-semibold">{{ __('Offered here') }}</h3>
                        @forelse ($offered as $zoneRate)
                            @continue(! $zoneRate->method)
                            <div @class(['flex justify-between gap-2', 'opacity-50' => ! $zoneRate->enabled || ! $zoneRate->method->enabled])>
                                <a href="{{ route('admin.shipping.methods.edit', [$zoneRate->method, 'tab' => 'rates']) }}" class="link link-hover truncate">
                                    {{ $zoneRate->method->t('name', admin_content_locale()) }}
                                </a>
                                <span class="tabular-nums whitespace-nowrap">
                                    @if (! $zoneRate->method->enabled || ! $zoneRate->enabled)
                                        {{ __('off') }}
                                    @else
                                        {{ $zoneRate->base_rate > 0 ? admin_price($zoneRate->base_rate, $zoneRate->currency) : __('Free') }}
                                    @endif
                                </span>
                            </div>
                        @empty
                            <div class="alert alert-warning py-2 text-xs">{{ __('No shipping method has a rate here yet, so a customer in this zone is offered nothing.') }}</div>
                        @endforelse
                        <p class="text-xs opacity-60">{{ __('A rate is set on the method, under "Zones and price".') }}</p>
                    </div>
                </section>
            @endif
        </div>
        </div>
    </form>

    {{-- Beside the main form, never inside it. --}}
    @if ($zone->exists)
        <form id="delete-zone" method="POST" action="{{ route('admin.shipping.zones.destroy', $zone) }}"
              data-confirm="{{ __('Delete this zone? Rates assigned to it go too.') }}">
            @csrf @method('DELETE')
        </form>
    @endif
@endsection
