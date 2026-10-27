@extends('admin.layout')

@section('title', __('Import a pack'))

@section('content')
    @include('torgash.locations::admin.partials.tabs')

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error text-sm">{{ $errors->first() }}</div>
    @endif

    {{-- A new shop's first question here is "where do I get the towns" — answered before anything else, and folded away once the shop has some. --}}
    <details id="where-towns-come-from" class="card bg-base-100 border border-base-300" @if ($counts->sum() === 0) open @endif>
        <summary class="card-body py-4 cursor-pointer font-semibold flex-row items-center gap-2">
            <x-icon name="circle-help" size="sm" /> {{ __('Where the towns come from') }}
        </summary>
        <div class="card-body pt-0 text-sm space-y-3">
            <p>{{ __('Towns, regions and districts are not typed in by hand: they come as a pack — two CSV files per country — built from open map data. The town window, delivery zones, pickup points and city pages all read them.') }}</p>
            <ol class="list-decimal pl-5 space-y-2">
                <li>
                    <strong>{{ __('Ukraine and Russia come with the engine.') }}</strong>
                    {{ __('Their packs are already in the folder below — press Import beside the country and the list fills in the background in a few minutes. A shop installed with its country gets its pack loaded by the installer.') }}
                </li>
                <li>
                    <strong>{{ __('Another country: build its pack.') }}</strong>
                    {!! __('From GeoNames — download the country\'s :file, admin1CodesASCII.txt and alternateNamesV2.zip from :geonames and run tools/build-locations.php from the engine\'s source code. Or from OpenStreetMap — the country\'s extract from :geofabrik and tools/build-locations-osm.py (Python), which gives more local names, districts and postal codes. Then drop the two files into the folder or upload them below.', [
                        'file' => '<code>&lt;CC&gt;.zip</code>',
                        'geonames' => '<a class="link" href="https://download.geonames.org/export/dump/" target="_blank" rel="noopener noreferrer">download.geonames.org</a>',
                        'geofabrik' => '<a class="link" href="https://download.geofabrik.de/" target="_blank" rel="noopener noreferrer">download.geofabrik.de</a>',
                    ]) !!}
                </li>
                <li>
                    <strong>{{ __('No pack and no time to build one?') }}</strong>
                    {{ __('Add the towns you deliver to by hand under Places — an import never touches a place you added or edited.') }}
                </li>
            </ol>
            <p class="opacity-70">
                {{ __('Licences: OpenStreetMap data is ODbL 1.0 and GeoNames is CC BY 4.0 — both free to use in a shop, both ask for a credit (“© OpenStreetMap contributors”, “GeoNames”). Every pack records its source in its manifest.json, shown in the table below. The list of countries is a separate file, countries.csv (from GeoNames), loaded on the Countries tab.') }}
            </p>
        </div>
    </details>

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body">
            <h3 class="font-semibold">{{ __('Packs in the folder') }}</h3>
            <p class="text-sm opacity-60">
                {{ __('Anything dropped into :path as <country>-states.csv and <country>-cities.csv shows up here.', ['path' => $packsPath]) }}
            </p>

            @if ($packs === [])
                <p class="opacity-60 text-sm">{{ __('The folder has no packs in it.') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>{{ __('Country') }}</th>
                                <th>{{ __('Files') }}</th>
                                <th>{{ __('Source') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($packs as $country => $pack)
                                <tr>
                                    <td class="font-mono">
                                        {{ $country }}
                                        @if (($counts[$country] ?? 0) > 0)
                                            <div class="text-xs opacity-50 font-sans">{{ __(':count already imported', ['count' => number_format($counts[$country], 0, '.', ' ')]) }}</div>
                                        @endif
                                    </td>
                                    <td class="text-xs opacity-60">
                                        {{ basename($pack['states']) }} ({{ round(filesize($pack['states']) / 1024) }} KB)<br>
                                        {{ basename($pack['cities']) }} ({{ round(filesize($pack['cities']) / 1024 / 1024, 1) }} MB)
                                    </td>
                                    <td class="text-xs">
                                        @php($packSource = $pack['manifest']['source'] ?? null)
                                        @if ($packSource)
                                            <div>{{ $packSource['name'] ?? '' }} · {{ $packSource['licence'] ?? '' }}</div>
                                            <div class="opacity-60">
                                                @if (! empty($pack['manifest']['generated_at']))
                                                    {{ \Illuminate\Support\Carbon::parse($pack['manifest']['generated_at'])->format('d.m.Y') }} ·
                                                @endif
                                                {{ __(':count places', ['count' => number_format((int) ($pack['manifest']['counts']['cities'] ?? 0), 0, '.', ' ')]) }}
                                            </div>
                                            @if (! empty($packSource['attribution']))
                                                <div class="opacity-60">{{ $packSource['attribution'] }}</div>
                                            @endif
                                        @else
                                            <span class="opacity-50">{{ __('No manifest.json — the source is not recorded.') }}</span>
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        <form method="POST" action="{{ route('admin.locations.import.folder') }}"
                                              x-data="{ replace: false }" class="flex items-center justify-end gap-3">
                                            @csrf
                                            <input type="hidden" name="country" value="{{ $country }}">
                                            <label class="label cursor-pointer justify-start gap-2 p-0 text-xs">
                                                <input type="checkbox" name="replace" value="1" x-model="replace" class="checkbox checkbox-sm checkbox-error">
                                                <span>
                                                    {{ __('Wipe first') }}
                                                    @if (($ownCounts[$country] ?? 0) > 0)
                                                        <span class="text-error" x-show="replace" x-cloak>
                                                            — {{ __(':count of yours will be lost', ['count' => $ownCounts[$country]]) }}
                                                        </span>
                                                    @endif
                                                </span>
                                            </label>
                                            <button class="btn btn-sm" :class="replace ? 'btn-error' : 'btn-primary'"
                                                    @click="if (replace && ! confirm('{{ ($ownCounts[$country] ?? 0) > 0
                                                        ? __('This deletes :count places you added or edited yourself, and they cannot be restored by importing again. Continue?', ['count' => $ownCounts[$country]])
                                                        : __('Delete every place and region of this country first, then load the pack fresh?') }}')) $event.preventDefault()">
                                                <span x-text="replace ? '{{ __('Wipe and import') }}' : '{{ __('Import') }}'"></span>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body">
            <h3 class="font-semibold">{{ __('Or upload a pair of files') }}</h3>

            <form method="POST" action="{{ route('admin.locations.import.upload') }}" enctype="multipart/form-data"
                  class="grid md:grid-cols-3 gap-4 items-end">
                @csrf
                <fieldset class="fieldset p-0">
                    <legend class="fieldset-legend">{{ __('Country code') }}</legend>
                    <input name="country" maxlength="2" class="input input-bordered w-full uppercase" placeholder="UA" required>
                </fieldset>
                <fieldset class="fieldset p-0">
                    <legend class="fieldset-legend">{{ __('Regions CSV') }}</legend>
                    <input type="file" name="states" accept=".csv,.txt" class="file-input file-input-bordered w-full" required>
                </fieldset>
                <fieldset class="fieldset p-0">
                    <legend class="fieldset-legend">{{ __('Cities CSV') }}</legend>
                    <input type="file" name="cities" accept=".csv,.txt" class="file-input file-input-bordered w-full" required>
                </fieldset>
                <div class="md:col-span-3">
                    <button class="btn btn-primary btn-sm">{{ __('Upload and import') }}</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card bg-base-100 border border-base-300">
        <div class="card-body text-sm">
            <h3 class="font-semibold">{{ __('What an import does') }}</h3>
            <ul class="list-disc pl-5 space-y-1 opacity-80">
                <li>{{ __('Runs in the background — watch it under System → Background tasks.') }}</li>
                <li>{{ __('Matches existing places by their id in the pack, so re-importing updates rather than duplicates.') }}</li>
                <li><strong>{{ __('Places you edited or added by hand are never touched.') }}</strong></li>
                <li>{{ __('A place the new pack no longer lists is flagged, not deleted — an order may point at it.') }}</li>
                <li>{{ __('"Wipe first" throws away everything the country has — including places you added by hand — and loads the pack fresh. For when the registry needs starting over.') }}</li>
                <li>{{ __('Expected columns: ref, country, state_code, type, latitude, longitude, population, postal_from, postal_to, aliases — plus one name_<language> and one district_<language> column for every language the pack carries.') }}</li>
            </ul>
        </div>
    </div>
@endsection
